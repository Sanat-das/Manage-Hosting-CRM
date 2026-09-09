<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductOptionRequest;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionGroupProduct;
use App\Services\ProductOptionLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Admin configurable options (EAV: product_option_groups → values → pricing).
 *
 * This is the CATALOG side — the template for a feature (RAM, Storage,
 * Backup): its name, its machine `key`, its unit, its input type and its
 * candidate values with indicative per-cycle prices.
 *
 * What actually charges a customer lives one level down, on the product:
 * attaching a group snapshots its values into `product_option_link_values` +
 * `product_option_link_value_pricing`, and only those rows are read at
 * checkout (OptionPricingResolver). Editing a value here therefore does NOT
 * reprice an already-attached product until the snapshot is refreshed — hence
 * the explicit "push to products" action rather than a silent cascade.
 *
 * Two rules this controller exists to enforce:
 *
 * 1. ATTACHING GOES THROUGH ProductOptionLinkService. A raw `products()->sync()`
 *    creates a pivot row with zero link values, and because links are required
 *    by default the storefront then renders an unanswerable control that makes
 *    the product unorderable.
 *
 * 2. DETACHING IS DESTRUCTIVE. `product_option_link_values` cascades on the
 *    pivot's delete, so dropping a product from a group throws away the
 *    per-product pricing an admin built on the product page. A detach that
 *    would destroy pricing is refused, not confirmed away.
 */
class ProductOptionController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(private readonly ProductOptionLinkService $links) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $productId = $request->query('product_id');

        $groups = ProductOptionGroup::query()
            ->with('products:id,name')
            ->withCount(['values', 'products'])
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->when(
                $productId !== null && $productId !== '',
                fn ($query) => $query->whereHas('products', fn ($q) => $q->where('products.id', $productId))
            )
            ->gridSort([
                'name' => 'name',
                'type' => 'type',
                'sort_order' => 'sort_order',
                'created_at' => 'created_at',
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $products = Product::query()->orderBy('name')->get(['id', 'name']);

        return view('admin.product-options.index', compact('groups', 'search', 'products', 'productId'));
    }

    public function create(): View
    {
        $products = Product::query()->orderBy('name')->get(['id', 'name']);

        return view('admin.product-options.create', [
            'products' => $products,
            'optionTypes' => ProductOptionGroup::OPTION_TYPES,
        ]);
    }

    public function store(ProductOptionRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $group = DB::transaction(function () use ($validated) {
                $group = ProductOptionGroup::create($this->groupData($validated));

                // Values first: attaching snapshots whatever the group holds,
                // so a group attached before its values exist links nothing.
                $this->saveValues($group, $validated['values'] ?? [], $validated['default_value_index'] ?? null);

                foreach ($validated['product_ids'] ?? [] as $productId) {
                    $this->links->attachGroup(Product::findOrFail($productId), $group);
                }

                return $group;
            });
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['error' => 'Could not create option group: '.$e->getMessage()]);
        }

        return redirect()
            ->route('admin.product-options.index')
            ->with('success', "Option group {$group->name} created.");
    }

    public function edit(ProductOptionGroup $productOption): View
    {
        $productOption->load([
            'values' => fn ($query) => $query->with('pricing')->orderBy('sort_order'),
        ]);

        return view('admin.product-options.edit', [
            'productOption' => $productOption,
            // Where this template is actually in use. Read-only here: the
            // per-product pricing that hangs off each link is edited on the
            // product, and detaching from this page would destroy it.
            'usedBy' => $this->usedBy($productOption),
            'optionTypes' => ProductOptionGroup::OPTION_TYPES,
        ]);
    }

    public function update(ProductOptionRequest $request, ProductOptionGroup $productOption): RedirectResponse
    {
        $validated = $request->validated();

        // An absent product_ids key means "leave the attachments alone" — the
        // edit form no longer posts one. An empty array from an older client
        // still means "detach everything", and is guarded below.
        $productIds = array_key_exists('product_ids', $validated) ? $validated['product_ids'] : null;

        if ($productIds !== null && ($blocked = $this->pricedDetachments($productOption, $productIds)) !== []) {
            return back()->withInput()->withErrors(['product_ids' => 'Detaching '.implode(', ', $blocked).' would delete the per-product option pricing configured on '.(count($blocked) === 1 ? 'it' : 'them').'. Remove the option from the product itself if that is what you intend.']);
        }

        try {
            DB::transaction(function () use ($validated, $productOption, $productIds) {
                $productOption->update($this->groupData($validated));

                $this->saveValues($productOption, $validated['values'] ?? [], $validated['default_value_index'] ?? null);

                if ($productIds !== null) {
                    $this->syncProducts($productOption, $productIds);
                }
            });
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['error' => 'Could not update option group: '.$e->getMessage()]);
        }

        return redirect()
            ->route('admin.product-options.index')
            ->with('success', "Option group {$productOption->name} updated.");
    }

    /**
     * Re-snapshot every attached product's link values from this group, so the
     * catalog edits an admin just made reach the products that offer them.
     *
     * Deliberately explicit: the link values are a snapshot precisely so an
     * admin can price one product differently, and a silent cascade on every
     * save would flatten that. Per-product unit pricing and the customer-
     * editable flag survive (syncValuesFromGroup only replaces the values).
     */
    public function pushToProducts(ProductOptionGroup $productOption): RedirectResponse
    {
        $links = $productOption->productLinks()->with('group.values.pricing')->get();

        try {
            DB::transaction(function () use ($links) {
                foreach ($links as $link) {
                    $this->links->syncValuesFromGroup($link);
                }
            });
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => 'Could not push values to products: '.$e->getMessage()]);
        }

        return back()->with('success', $links->count() === 1
            ? "Values pushed to 1 product. Its per-product prices were replaced with this group's."
            : "Values pushed to {$links->count()} products. Their per-product prices were replaced with this group's.");
    }

    /**
     * The products this group is attached to, for the read-only "used by"
     * list: the link itself, whether the customer may change it there, and
     * whether it carries per-product pricing (which a detach would destroy and
     * which a push would overwrite).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function usedBy(ProductOptionGroup $group): Collection
    {
        return $group->productLinks()
            ->with(['product:id,name,billing_cycle'])
            ->withCount('linkValues')
            ->get()
            ->map(fn (ProductOptionGroupProduct $link) => [
                'link' => $link,
                'product' => $link->product,
                'values_count' => (int) $link->link_values_count,
                'has_pricing' => $this->hasPricing($link),
            ]);
    }

    /**
     * The names of currently-attached products that the submitted id list
     * would detach AND whose link holds pricing — the detaches worth refusing.
     * Detaching a link with no pricing on it loses nothing.
     *
     * @param  list<int|string>  $productIds
     * @return list<string>
     */
    private function pricedDetachments(ProductOptionGroup $group, array $productIds): array
    {
        $keep = array_map('intval', $productIds);

        return $group->productLinks()
            ->with('product:id,name')
            ->whereNotIn('product_id', $keep ?: [0])
            ->get()
            ->filter(fn (ProductOptionGroupProduct $link) => $this->hasPricing($link))
            ->map(fn (ProductOptionGroupProduct $link) => $link->product?->name ?? "product #{$link->product_id}")
            ->values()
            ->all();
    }

    /**
     * Whether a link holds money: a per-cycle unit price (continuous groups)
     * or any per-value price modifier (discrete groups).
     */
    private function hasPricing(ProductOptionGroupProduct $link): bool
    {
        return $link->unitPricing()->exists()
            || $link->linkValues()->whereHas('pricing')->exists();
    }

    /**
     * Bring the group's attachments in line with a submitted id list: new
     * products are attached through the link service (which snapshots the
     * values), and products dropped from the list are detached. Callers must
     * clear pricedDetachments() first — this method does not re-check.
     *
     * @param  list<int|string>  $productIds
     */
    private function syncProducts(ProductOptionGroup $group, array $productIds): void
    {
        $keep = array_map('intval', $productIds);
        $existing = $group->productLinks()->pluck('product_id')->map('intval')->all();

        $group->productLinks()->whereNotIn('product_id', $keep ?: [0])->get()
            ->each(fn (ProductOptionGroupProduct $link) => $link->delete());

        foreach (array_diff($keep, $existing) as $productId) {
            $this->links->attachGroup(Product::findOrFail($productId), $group);
        }
    }

    public function destroy(Request $request, ProductOptionGroup $productOption): RedirectResponse
    {
        // Deleting the group cascades all the way down: values, their pricing,
        // every product link and the per-product pricing on it. Refuse while a
        // product still prices this feature — detach it there first.
        if (($priced = $this->pricedDetachments($productOption, [])) !== []) {
            return back()->withErrors(['error' => "Option group {$productOption->name} is still priced on ".implode(', ', $priced).'. Remove it from '.(count($priced) === 1 ? 'that product' : 'those products').' first — deleting it here would delete that pricing too.']);
        }

        $productOption->delete(); // cascades to values and their pricing

        return redirect()
            ->route('admin.product-options.index')
            ->with('success', "Option group {$productOption->name} deleted.");
    }

    /**
     * Map validated input onto the product_option_groups row (product
     * attachments live in the pivot and are synced separately).
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function groupData(array $validated): array
    {
        return [
            'name' => $validated['name'],
            // An empty unit box means "no unit", not an empty string — the
            // snapshot and the views test it for null.
            'unit' => ($validated['unit'] ?? '') !== '' ? $validated['unit'] : null,
            'type' => $validated['type'],
            'input_min' => $validated['input_min'] ?? null,
            // input_max carries two meanings and the form posts both, because
            // hiding a field must never blank it: for a checkbox it is the cap
            // on how many values may be ticked, for everything else it is the
            // numeric upper bound. The type decides which one lands.
            'input_max' => ($validated['type'] === 'checkbox')
                ? ($validated['max_selections'] ?? null)
                : ($validated['input_max'] ?? null),
            'input_step' => $validated['input_step'] ?? null,
            'input_placeholder' => $validated['input_placeholder'] ?? null,
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ];
    }

    /**
     * Replace the group's values wholesale (reference updateGroup behavior).
     * Each value may carry a price modifier for any of the billing cycles.
     *
     * @param  array<int, array<string, mixed>>  $values
     */
    private function saveValues(ProductOptionGroup $group, array $values, mixed $submittedDefault = null): void
    {
        $group->values()->delete();

        // The form posts one radio for the whole list, carrying the index of
        // the default row. Nothing selected (or a stale index) falls back to
        // the first saved value, which is what attaching used to assume.
        $defaultIndex = $this->defaultValueIndex($values, $submittedDefault);

        foreach ($values as $index => $value) {
            if (empty($value['label'])) {
                continue;
            }

            $optionValue = $group->values()->create([
                'label' => $value['label'],
                'is_default' => $index === $defaultIndex,
                'sort_order' => (int) ($value['sort_order'] ?? 0),
            ]);

            foreach (Product::BILLING_CYCLES as $cycle => $cycleLabel) {
                $modifier = $value['pricing'][$cycle]['price_modifier'] ?? null;

                if ($modifier === null || $modifier === '') {
                    continue;
                }

                $optionValue->pricing()->create([
                    'billing_cycle' => $cycle,
                    'price_modifier' => $modifier,
                ]);
            }
        }
    }

    /**
     * Which submitted row is the default: the one the radio names, else a row
     * flagged inline (API payloads), else the first row carrying a label — the
     * behaviour attaching assumed before the flag existed.
     *
     * Compared loosely: the radio's value arrives as a string key while the
     * values array may be keyed by int.
     *
     * @param  array<int|string, array<string, mixed>>  $values
     */
    private function defaultValueIndex(array $values, mixed $submittedDefault = null): int|string|null
    {
        if ($submittedDefault !== null && $submittedDefault !== '') {
            foreach ($values as $index => $value) {
                if ((string) $index === (string) $submittedDefault && ! empty($value['label'])) {
                    return $index;
                }
            }
        }

        foreach ($values as $index => $value) {
            if (! empty($value['label']) && filter_var($value['is_default'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                return $index;
            }
        }

        foreach ($values as $index => $value) {
            if (! empty($value['label'])) {
                return $index;
            }
        }

        return null;
    }
}
