<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductOptionGroupProduct;
use App\Models\ProductPricing;
use App\Services\Billing\UpgradeQuoteService;
use App\Services\Billing\UpgradeRequestService;
use App\Services\OptionPricingResolver;
use App\Support\AppSettings;
use App\Support\OptionSelectionRules;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Client portal — product upgrade/downgrade requests.
 *
 * A customer on an active recurring service picks a target product from the
 * enabled upgrade paths and sees a WHMCS-style prorated quote; submitting
 * files a pending upgrade_requests row. Whether the request is approved
 * immediately (and invoiced) is the shared auto-approval rule — everything
 * after that belongs to the billing services.
 */
class UpgradeController extends Controller
{
    public function __construct(
        private readonly UpgradeQuoteService $quotes,
        private readonly UpgradeRequestService $requests,
    ) {}

    public function index(Request $request, Order $order): View
    {
        $customer = $request->user()->customer;
        abort_unless($customer, 404);

        $order = $customer->orders()
            ->with(['items', 'product.upgradeableTo.toProduct'])
            ->findOrFail($order->id);

        abort_unless($this->eligible($order), 404);

        $upgradeTargets = [];
        $downgradeTargets = [];
        $downgradesEnabled = (bool) AppSettings::get('product_enable_downgrades', '0');
        $recurringCycles = array_keys(array_filter(Order::CYCLE_MONTHS, fn (int $months) => $months > 0));

        $served = $order->items->first(
            fn (OrderItem $item) => $item->product_addon_id === null && (int) $item->product_id === (int) $order->product_id,
        ) ?? $order->items->first(fn (OrderItem $item) => $item->product_addon_id === null);

        $fromProduct = $served?->product ?? $order->product;

        foreach ($fromProduct->upgradeableTo as $path) {
            $to = $path->toProduct;

            if ($to === null || $to->status !== 'active') {
                continue;
            }

            // The target product is offered once per recurring cycle it is
            // priced for (WHMCS newproductbillingcycle): each pair quotes and
            // submits independently.
            $cycles = ProductPricing::query()
                ->where('product_id', $to->id)
                ->whereIn('billing_cycle', $recurringCycles)
                ->pluck('billing_cycle')
                ->sortBy(fn (string $cycle) => Order::CYCLE_MONTHS[$cycle] ?? PHP_INT_MAX)
                ->values()
                ->all();

            foreach ($cycles as $cycle) {
                try {
                    $quote = $this->quotes->quote($order, $to, null, $cycle);
                } catch (DomainException) {
                    // No pricing for this cycle — that pair is not offered.
                    continue;
                }

                // The path's direction gates which side of the quote it may
                // be offered on: a downgrade quote needs a downgrade-allowed
                // path and the global toggle; anything else needs an
                // upgrade-allowed path.
                $isDowngrade = $quote['change_type'] === 'downgrade';
                $direction = (string) $path->direction;

                if ($isDowngrade
                    ? (! $downgradesEnabled || ! in_array($direction, ['downgrade', 'both'], true))
                    : ! in_array($direction, ['upgrade', 'both'], true)
                ) {
                    continue;
                }

                $pair = [
                    'product' => $to,
                    'billing_cycle' => $cycle,
                    'quote' => $quote,
                ];

                if ($isDowngrade) {
                    $downgradeTargets[] = $pair;
                } else {
                    $upgradeTargets[] = $pair;
                }
            }
        }

        $groupTargets = function (array $targets): array {
            $groups = [];

            foreach ($targets as $target) {
                $id = (int) $target['product']->id;
                $groups[$id] ??= ['product' => $target['product'], 'pairs' => []];
                $groups[$id]['pairs'][] = $target;
            }

            foreach ($groups as $id => $group) {
                $groups[$id]['editable_links'] = OptionPricingResolver::loadLinks($group['product'])
                    ->where('customer_editable', true);
            }

            return $groups;
        };

        $upgradeTargets = $groupTargets($upgradeTargets);
        $downgradeTargets = $groupTargets($downgradeTargets);

        $servedSnapshot = $this->servedSnapshot($order);

        return view('client.upgrades.show', compact(
            'order',
            'upgradeTargets',
            'downgradeTargets',
            'servedSnapshot',
        ));
    }

    /**
     * Second step of the client flow: configure options for ONE chosen
     * (product, cycle) pair. Stateless — the selection travels in the page's
     * form payload, which posts to the existing preview route for the live
     * money computation. An "Edit Configuration" round-trip from the review
     * step reposts the same payload here so the pickers re-render with the
     * submitted selections applied.
     */
    public function configure(Request $request, Order $order): View|RedirectResponse
    {
        $customer = $request->user()->customer;
        abort_unless($customer, 404);

        $order = $customer->orders()
            ->with(['items', 'product.upgradeableTo.toProduct'])
            ->findOrFail($order->id);

        abort_unless($this->eligible($order), 404);

        // The step-1 form ships one radio per (product × cycle) pair whose
        // value carries both ids; direct payloads (tests, the edit round-trip)
        // already use the plain fields.
        if (! $request->has('to_product_id') && is_string($request->input('pair'))) {
            [$productId, $cycle] = array_pad(explode(':', $request->input('pair'), 2), 2, null);
            $request->merge([
                'to_product_id' => $productId,
                'to_billing_cycle' => $cycle,
            ]);
        }

        $validated = $request->validate([
            'to_product_id' => ['required', 'integer'],
            'to_billing_cycle' => ['nullable', 'string', 'in:'.implode(',', array_keys(array_filter(Order::CYCLE_MONTHS, fn (int $months) => $months > 0)))],
        ]);

        $to = Product::where('status', 'active')->find($validated['to_product_id']);

        if ($to === null) {
            return back()->withErrors(['to_product_id' => 'The target product is not available.']);
        }

        // The pair must still be a genuine candidate: the same enabled-path
        // check index() couples with the target's active status above.
        if (! $order->product?->upgradeableTo()->where('to_product_id', $to->id)->exists()) {
            return back()->withErrors(['to_product_id' => 'The target product is not available.']);
        }

        $cycle = $validated['to_billing_cycle'] ?? null;

        try {
            $quote = $this->quotes->quote($order, $to, null, $cycle);
        } catch (DomainException $e) {
            return back()->withErrors(['to_product_id' => $e->getMessage()]);
        }

        if ($quote['change_type'] === 'downgrade' && ! (bool) AppSettings::get('product_enable_downgrades', '0')) {
            return back()->withErrors(['to_product_id' => 'Downgrades are disabled.']);
        }

        $links = OptionPricingResolver::loadLinks($to)->where('customer_editable', true);
        $servedSnapshot = $this->servedSnapshot($order);

        // Repopulate the pickers from a reposted payload when present;
        // otherwise the view preselects the served configuration for an
        // unchanged product.
        $submittedPreselection = $request->has('options')
            ? $this->preselectionFrom($request, $to)
            : null;

        return view('client.upgrades.configure', compact('order', 'to', 'cycle', 'quote', 'links', 'servedSnapshot', 'submittedPreselection'));
    }

    /**
     * Third step of the client flow: re-run the live quote with the option
     * selections submitted from the configure step and render the confirm view
     * with the exact prorated breakdown. Stateless — nothing is persisted
     * here, the confirm form repeats the payload to store().
     */
    public function preview(Request $request, Order $order): View|RedirectResponse
    {
        $customer = $request->user()->customer;
        abort_unless($customer, 404);

        $order = $customer->orders()
            ->with(['items', 'product.upgradeableTo.toProduct'])
            ->findOrFail($order->id);

        abort_unless($this->eligible($order), 404);

        $validated = $request->validate([
            'to_product_id' => ['required', 'integer'],
            'to_billing_cycle' => ['nullable', 'string', 'in:'.implode(',', array_keys(array_filter(Order::CYCLE_MONTHS, fn (int $months) => $months > 0)))],
        ]);

        $to = Product::where('status', 'active')->find($validated['to_product_id']);

        if ($to === null) {
            return back()->withErrors(['to_product_id' => 'The target product is not available.']);
        }

        $cycle = $validated['to_billing_cycle'] ?? null;
        $options = $this->optionsFrom($request, $to);

        try {
            $quote = $this->quotes->quote($order, $to, null, $cycle, $options);
        } catch (DomainException $e) {
            return back()->withErrors(['to_product_id' => $e->getMessage()]);
        }

        if ($quote['change_type'] === 'downgrade' && ! (bool) AppSettings::get('product_enable_downgrades', '0')) {
            return back()->withErrors(['to_product_id' => 'Downgrades are disabled.']);
        }

        return view('client.upgrades.confirm', [
            'order' => $order,
            'to' => $to,
            'cycle' => $cycle,
            'quote' => $quote,
            'options' => $options,
            'option_rows' => $this->optionRows($order, $to, $quote, $options),
        ]);
    }

    public function store(Request $request, Order $order): RedirectResponse
    {
        $customer = $request->user()->customer;
        abort_unless($customer, 404);

        $order = $customer->orders()->findOrFail($order->id);

        $validated = $request->validate([
            'to_product_id' => ['required', 'integer'],
            'to_billing_cycle' => ['nullable', 'string', 'in:'.implode(',', array_keys(array_filter(Order::CYCLE_MONTHS, fn (int $months) => $months > 0)))],
            'confirm' => ['required', 'accepted'],
        ], [
            'confirm.required' => 'You must confirm the upgrade details to proceed.',
            'confirm.accepted' => 'You must confirm the upgrade details to proceed.',
        ]);

        $to = Product::where('status', 'active')->find($validated['to_product_id']);

        if ($to === null) {
            return back()->withErrors(['to_product_id' => 'The target product is not available.']);
        }

        try {
            $upgrade = $this->requests->place(
                $order,
                $to,
                null,
                $validated['to_billing_cycle'] ?? null,
                $this->optionsFrom($request, $to),
            );
        } catch (DomainException $e) {
            return back()->withErrors(['to_product_id' => $e->getMessage()]);
        }

        $invoice = null;

        if ($this->requests->shouldAutoApprove($upgrade)) {
            $this->requests->approve($upgrade);
            $upgrade->refresh();
            $invoice = $upgrade->invoice;
        }

        $message = $invoice !== null && $invoice->id !== null
            ? "Upgrade {$upgrade->upgrade_no} approved. Invoice {$invoice->invoice_no} generated."
            : "Upgrade request {$upgrade->upgrade_no} submitted.";

        if ($order->hostingAccount !== null) {
            return redirect()->route('client.hosting.show', $order->hostingAccount->id)->with('success', $message);
        }

        return redirect()->route('client.orders.show', $order->id)->with('success', $message);
    }

    /**
     * The submitted option selections for a target product, validated per
     * option-link input type.
     *
     * A request without an `options` key keeps the pre-options engine
     * behavior exactly (null → "no selections"): the legacy direct submit on
     * the upgrade page and the product-switch form. A request that reached the
     * flow through a card (which renders pickers whenever the target carries
     * any option links) submits the keyed payload, and the empty set is
     * meaningful — the resolver prices the target's declared FIXED options
     * from it automatically.
     *
     * Discrete values are validated as integers (the pickers submit link-value
     * ids); an unknown id is neither rejected nor priced — the resolver
     * ignores it. Continuous values are numeric on the link's min/max/step
     * grid, text is a plain string.
     */
    private function optionsFrom(Request $request, Product $to): ?array
    {
        $links = OptionPricingResolver::loadLinks($to);

        if (! $request->has('options')) {
            return $links->isEmpty() ? null : [];
        }

        $editable = $links->where('customer_editable', true);

        return $request->validate($this->optionRules($editable))['options'] ?? [];
    }

    /**
     * Snapshot-shaped preselection rows rebuilt from a reposted option payload
     * (the review step's "Edit Configuration" form), so the step-2 pickers
     * re-render with the customer's selections applied. Tolerant on purpose:
     * unknown ids simply preselect nothing, exactly like the served snapshot.
     */
    private function preselectionFrom(Request $request, Product $to): Collection
    {
        $links = OptionPricingResolver::loadLinks($to)->where('customer_editable', true);
        $rows = [];

        foreach ($request->input('options', []) as $linkId => $value) {
            $link = $links->firstWhere('id', (int) $linkId);
            $type = $link?->group?->type ?? 'dropdown';

            $rows[(int) $linkId] = match ($type) {
                'checkbox' => ['value_ids' => array_map('intval', (array) $value)],
                'dropdown', 'radio' => ['value_id' => (int) $value],
                default => ['selected' => $value],
            };
        }

        return collect($rows);
    }

    /**
     * Per-link validation rules for the upgrade option payload — the
     * storefront's rules minus the membership check, so an unknown value id
     * still previews (OptionPricingResolver ignores it) instead of erroring.
     *
     * @param  Collection<int, ProductOptionGroupProduct>  $editableLinks
     * @return array<string, list<mixed>>
     */
    private function optionRules(Collection $editableLinks): array
    {
        $rules = [];

        foreach ($editableLinks as $link) {
            $key = 'options.'.$link->id;
            $type = $link->group?->type ?? 'dropdown';
            $presence = ($link->required ?? true) ? 'required' : 'nullable';

            switch ($type) {
                case 'checkbox':
                    $maxCheckboxes = (int) ($link->input_max ?? $link->group?->input_max ?? $link->linkValues->count());
                    $rules[$key] = [$presence, 'array', 'max:'.max(1, $maxCheckboxes)];
                    $rules[$key.'.*'] = ['integer'];
                    break;

                case 'quantity':
                    $rules[$key] = [$presence, 'integer', 'min:'.OptionSelectionRules::inputMin($link)];
                    if (OptionSelectionRules::inputMax($link) !== null) {
                        $rules[$key][] = 'max:'.OptionSelectionRules::inputMax($link);
                    }
                    break;

                case 'number':
                    $rules[$key] = [$presence, 'numeric', 'min:'.OptionSelectionRules::inputMin($link), 'max:'.(OptionSelectionRules::inputMax($link) ?? PHP_FLOAT_MAX), OptionSelectionRules::stepRule($link)];
                    break;

                case 'slider':
                    $rules[$key] = [$presence, 'numeric', 'min:'.OptionSelectionRules::inputMin($link), 'max:'.(OptionSelectionRules::inputMax($link) ?? 100), OptionSelectionRules::stepRule($link)];
                    break;

                case 'text':
                    $rules[$key] = [$presence, 'string', 'max:255'];
                    break;

                default: // dropdown / radio — link-value ids
                    $rules[$key] = [$presence, 'integer'];
                    break;
            }
        }

        return $rules;
    }

    /**
     * Per-option change rows for the confirm view: group name, the served
     * selection vs the submitted selection, and the new per-cycle unit rate,
     * one row per editable link that actually changes (selection, rate or
     * applied price).
     *
     * @return array<int, array{name: string, old: string|null, new: string|null, unit: string, price_unit: float|null}>
     */
    private function optionRows(Order $order, Product $to, array $quote, ?array $selections): array
    {
        if ($selections === null) {
            return [];
        }

        $cycle = (string) ($quote['to']['billing_cycle'] ?? $order->billing_cycle);
        $links = OptionPricingResolver::loadLinks($to);
        $resolved = (new OptionPricingResolver)->resolve($to, $selections, $cycle, $links);
        $snapshot = $this->servedSnapshot($order);

        $rows = [];

        foreach ($links as $link) {
            $line = $resolved['lines'][$link->id] ?? null;

            if ($line === null) {
                continue;
            }

            $old = $snapshot !== null ? ($snapshot[$link->id] ?? null) : null;
            $oldSelected = $old !== null
                ? $this->displaySelected($old['selected'] ?? null, $old['value_id'] ?? null, $old['value_ids'] ?? [], $link)
                : null;
            $newSelected = $this->displaySelected($line['selected'] ?? null, $line['value_id'] ?? null, $line['value_ids'] ?? [], $link);

            $oldUnit = $old !== null && ($old['price_unit'] ?? null) !== null ? (float) $old['price_unit'] : null;
            $oldApplied = $old !== null ? (float) ($old['price_applied'] ?? 0) : 0.0;
            $newUnit = $line['price_unit'] !== null ? (float) $line['price_unit'] : null;

            if ($oldSelected === $newSelected
                && $oldUnit === $newUnit
                && $oldApplied === (float) $line['price_applied']) {
                continue;
            }

            $rows[] = [
                'name' => (string) ($link->group?->name ?? ''),
                'old' => $oldSelected,
                'new' => $newSelected,
                'unit' => (string) ($link->group?->unit ?? ''),
                'price_unit' => $line['price_unit'],
            ];
        }

        return $rows;
    }

    /**
     * Render a selection for display: label(s) for discrete values, the raw
     * amount for continuous ones, falling back to the value id's label when
     * the resolver left the label empty.
     */
    private function displaySelected(mixed $selected, mixed $valueId, array $valueIds, ProductOptionGroupProduct $link): ?string
    {
        if (is_array($selected)) {
            $labels = array_values(array_filter(array_map('strval', $selected), static fn (string $s) => $s !== ''));

            return $labels === [] ? null : implode(', ', $labels);
        }

        if ($selected !== null && $selected !== '') {
            return (string) $selected;
        }

        if ($valueIds !== []) {
            $label = $link->linkValues->firstWhere('id', (int) $valueIds[0])?->label;

            if ($label !== null) {
                return (string) $label;
            }
        }

        if ($valueId !== null) {
            return (string) ($link->linkValues->firstWhere('id', (int) $valueId)?->label ?? '');
        }

        return null;
    }

    /**
     * The served order item's option snapshot keyed by option-link id, or null
     * when the item carries none. Preselects on the upgrade page and feeds the
     * "current" side of the confirm view's per-option rows.
     */
    private function servedSnapshot(Order $order): ?Collection
    {
        $served = $order->items->first(
            fn (OrderItem $item) => $item->product_addon_id === null && (int) $item->product_id === (int) $order->product_id,
        ) ?? $order->items->first(fn (OrderItem $item) => $item->product_addon_id === null);

        if ($served === null || ! is_array($served->config_options)) {
            return null;
        }

        return collect($served->config_options['options'] ?? [])->keyBy('id');
    }

    /**
     * Whether this order may open the upgrade page: an active recurring
     * service, upgrades enabled, and at least one enabled path to an active
     * target product.
     */
    private function eligible(Order $order): bool
    {
        return $order->status === Order::STATUS_ACTIVE
            && (Order::CYCLE_MONTHS[(string) $order->billing_cycle] ?? 0) > 0
            && (bool) AppSettings::get('product_enable_upgrades', '1')
            && $order->product !== null
            && $order->product->upgradeableTo()
                ->whereHas('toProduct', fn ($q) => $q->where('status', 'active'))
                ->exists();
    }
}
