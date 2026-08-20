<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductRequest;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductOptionGroup;
use App\Models\ServerGroup;
use App\Services\ProductOptionLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Admin product management (CRUD + groups + multi-cycle pricing +
 * configurable options + addons).
 *
 * Ported from the reference CRM:
 * - the `products` row keeps the legacy price/setup_fee/billing_cycle columns
 *   (the default cycle), while the full price ladder lives in
 *   `product_pricing` rows (term months + price + promo).
 * - deletion is guarded: products referenced by active/pending orders cannot
 *   be deleted (decisions.md #10).
 */
class ProductController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(private readonly ProductOptionLinkService $optionLinks)
    {
    }

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $status = $request->query('status');
        $groupId = $request->query('group_id');

        $products = Product::query()
            ->with('group')
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->when(in_array($status, ['active', 'inactive'], true), fn ($query) => $query->where('status', $status))
            ->when($groupId !== null && $groupId !== '', fn ($query) => $query->where('product_group_id', $groupId))
            ->withCount('orders')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $groups = ProductGroup::query()->orderBy('sort_order')->orderBy('name')->get();

        return view('admin.products.index', compact('products', 'search', 'status', 'groupId', 'groups'));
    }

    public function create(): View
    {
        $availableGroups = ProductOptionGroup::query()
            ->with('values.pricing')
            ->orderBy('name')
            ->get();

        return view('admin.products.create', array_merge([
            'availableGroups' => $availableGroups,
        ], $this->formData()));
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $product = DB::transaction(function () use ($validated) {
                $product = Product::create($this->productData($validated));
                $this->savePricing($product, $validated['pricing'] ?? []);
                $this->attachOptionGroups($product, $validated['option_groups'] ?? []);

                return $product;
            });
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['error' => 'Could not create product: '.$e->getMessage()]);
        }

        return redirect()
            ->route('admin.products.show', $product)
            ->with('success', "Product {$product->name} created.");
    }

    public function show(Product $product): View
    {
        $product->load([
            'group',
            'pricing',
            'options' => fn ($query) => $query
                ->with(['values' => fn ($v) => $v->with('pricing')->orderBy('sort_order')])
                ->orderBy('sort_order'),
            'addons' => fn ($query) => $query->orderBy('name'),
            'optionLinks.linkValues.pricing',
            'optionLinks.unitPricing',
        ]);

        return view('admin.products.show', compact('product'));
    }

    public function edit(Product $product): View
    {
        $product->load(['group', 'pricing', 'optionLinks.linkValues.pricing', 'optionLinks.unitPricing', 'options']);

        $availableGroups = ProductOptionGroup::query()->orderBy('name')->get();

        return view('admin.products.edit', array_merge([
            'product' => $product,
            'availableGroups' => $availableGroups,
        ], $this->formData()));
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $validated = $request->validated();

        try {
            DB::transaction(function () use ($validated, $request, $product) {
                $product->update($this->productData($validated));
                $this->savePricing($product, $validated['pricing'] ?? []);
                $this->updateOptionLinks($request, $product, $validated['option_links'] ?? []);
            });
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['error' => 'Could not update product: '.$e->getMessage()]);
        }

        // Stay on the edit page after saving (the header Save button's form
        // re-renders here) and restore the tab the user was editing.
        return redirect()
            ->route('admin.products.edit', $product)
            ->with('success', "Product {$product->name} updated.")
            ->with('active_tab', $request->input('active_tab', 'details'));
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        $hasLiveOrders = Order::query()
            ->where('product_id', $product->id)
            ->whereIn('status', ['active', 'pending'])
            ->exists();

        if ($hasLiveOrders) {
            return back()
                ->with('error', "Cannot delete product {$product->name}: it has active or pending orders.");
        }

        $product->delete(); // cascades to pricing, options, addons, meta

        return redirect()
            ->route('admin.products.index')
            ->with('success', "Product {$product->name} deleted.");
    }

    /**
     * Shared select-list data for the create/edit forms.
     *
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        return [
            'cycles' => Product::BILLING_CYCLES,
            'defaultCycles' => Product::DEFAULT_CYCLES,
            'provisioningModules' => Product::PROVISIONING_MODULES,
            'gstTypes' => Product::GST_TYPES,
            'currency' => app(\App\Settings\BillingSettings::class)->currency,
            'groups' => ProductGroup::query()->orderBy('sort_order')->orderBy('name')->get(),
            'serverGroups' => ServerGroup::query()->where('status', 'active')->orderBy('name')->get(),
            'emailTemplates' => EmailTemplate::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
        ];
    }

    /**
     * Map validated input onto the products row. The legacy price / setup_fee
     * columns mirror the selected default billing cycle.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function productData(array $validated): array
    {
        $pricing = $validated['pricing'] ?? [];
        $cycle = $validated['billing_cycle'];
        $defaultPrice = $pricing[$cycle]['price'] ?? null;
        $defaultSetupFee = $pricing[$cycle]['setup_fee'] ?? null;

        $data = [
            'name' => $validated['name'],
            'product_group_id' => $validated['product_group_id'] ?? null,
            'description' => $validated['description'] ?? null,
            'billing_cycle' => $cycle,
            'payment_type' => $validated['payment_type'] ?? 'recurring',
            'price' => $defaultPrice !== null && $defaultPrice !== '' ? $defaultPrice : 0,
            'setup_fee' => $defaultSetupFee !== null && $defaultSetupFee !== '' ? $defaultSetupFee : 0,
            'quantity_behaviour' => $validated['quantity_behaviour'] ?? 'multiple_services',
            'recurring_cycles_limit' => (int) ($validated['recurring_cycles_limit'] ?? 0),
            'auto_terminate_value' => (int) ($validated['auto_terminate_value'] ?? 0),
            'auto_terminate_unit' => $validated['auto_terminate_unit'] ?? 'days',
            'prorata_enabled' => (bool) ($validated['prorata_enabled'] ?? false),
            'prorata_date' => filled($validated['prorata_date'] ?? null) ? (int) $validated['prorata_date'] : null,
            'prorata_charge_next_month' => (bool) ($validated['prorata_charge_next_month'] ?? false),
            'early_renewal_mode' => $validated['early_renewal_mode'] ?? 'default',
            'early_renewal_days' => $validated['early_renewal_days'] ?? null,
            'provisioning_module' => $validated['provisioning_module'],
            'server_group_id' => $validated['server_group_id'] ?? null,
            'welcome_email_template_id' => $validated['welcome_email_template_id'] ?? null,
            'require_domain' => (bool) ($validated['require_domain'] ?? false),
            'require_public_ip' => (bool) ($validated['require_public_ip'] ?? false),
            'require_private_ip' => (bool) ($validated['require_private_ip'] ?? false),
            'show_in_order' => (bool) ($validated['show_in_order'] ?? false),
            'show_in_affiliate' => (bool) ($validated['show_in_affiliate'] ?? false),
            'only_admin' => (bool) ($validated['only_admin'] ?? false),
            'is_bundle' => (bool) ($validated['is_bundle'] ?? false),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'status' => $validated['status'],
            'gst_enabled' => (bool) ($validated['gst_enabled'] ?? false),
            'gst_rate' => $validated['gst_rate'] ?? null,
            'gst_type' => $validated['gst_type'],
            'cgst_rate' => $validated['cgst_rate'] ?? null,
            'sgst_rate' => $validated['sgst_rate'] ?? null,
            'igst_rate' => $validated['igst_rate'] ?? null,
        ];

        return $data;
    }

    /**
     * Replace the product's pricing ladder (reference: ProductPricingModel::savePricing).
     *
     * @param  array<string, mixed>  $pricing
     */
    private function savePricing(Product $product, array $pricing): void
    {
        $product->pricing()->delete();

        foreach (Product::BILLING_CYCLES as $cycle => $label) {
            $row = $pricing[$cycle] ?? [];
            $price = $row['price'] ?? null;

            // Skip cycles with no price unless the cycle is 'free'.
            if ($cycle !== 'free' && ($price === null || $price === '')) {
                continue;
            }

            $product->pricing()->create([
                'billing_cycle' => $cycle,
                'setup_fee' => $row['setup_fee'] ?? 0,
                'price' => $price !== null && $price !== '' ? $price : 0,
                'promo_price' => ! empty($row['promo_price']) ? $row['promo_price'] : null,
                'promo_start' => ! empty($row['promo_start']) ? $row['promo_start'] : null,
                'promo_end' => ! empty($row['promo_end']) ? $row['promo_end'] : null,
            ]);
        }
    }

    /**
     * Attach the option groups selected on the create page. The payload is
     * keyed by group id: checked groups carry a `selected` flag plus either
     * per-cycle unit pricing (continuous) or per-value pricing keyed by the
     * GROUP value id (discrete — the link rows don't exist until attach runs).
     *
     * @param  array<string, array<string, mixed>>  $optionGroups
     */
    private function attachOptionGroups(Product $product, array $optionGroups): void
    {
        foreach ($optionGroups as $groupId => $payload) {
            if (! is_array($payload) || ! filter_var($payload['selected'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }

            $group = ProductOptionGroup::query()->findOrFail((int) $groupId);
            $link = $this->optionLinks->attachGroup(
                $product,
                $group,
                (bool) ($payload['customer_editable'] ?? false)
            );

            $this->optionLinks->saveInputOverrides($link, $payload);

            if (ProductOptionGroup::isContinuousType($group->type)) {
                $this->optionLinks->saveUnitPricing($link, $payload['unit_pricing'] ?? []);
            } else {
                $this->optionLinks->applyGroupValuePricing($link, $payload['pricing'] ?? []);
            }
        }
    }

    /**
     * Apply the per-link option payloads submitted with the product update
     * form: every rendered link card submits its configuration, and each is
     * saved atomically inside the product transaction.
     *
     * @param  array<string, array<string, mixed>>  $optionLinks  link id => payload
     */
    private function updateOptionLinks(Request $request, Product $product, array $optionLinks): void
    {
        foreach ($optionLinks as $linkId => $payload) {
            if (! is_array($payload)) {
                continue;
            }

            $link = $product->optionLinks()->find((int) $linkId);

            if ($link === null) {
                continue;
            }

            // Restore the positional value ids (validated() strips them) so
            // the wholesale replace updates existing rows instead of deleting
            // and recreating them.
            $rawValues = $request->input("option_links.{$linkId}.values");
            $values = $payload['values'] ?? [];

            if (is_array($rawValues)) {
                foreach ($values as $index => &$value) {
                    if (isset($rawValues[$index]['id']) && is_numeric($rawValues[$index]['id'])) {
                        $value['id'] = (int) $rawValues[$index]['id'];
                    }
                }
                unset($value);
            }

            $this->optionLinks->updateLink($link, array_merge($payload, ['values' => $values]));
        }
    }
}
