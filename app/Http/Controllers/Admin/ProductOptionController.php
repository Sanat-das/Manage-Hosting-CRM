<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductOptionRequest;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Admin configurable options (EAV: product_option_groups → values → pricing).
 *
 * Ported from the reference ConfigurableOptionModel:
 * - a group belongs to one product and carries a name, type
 *   (dropdown / radio / quantity) and sort_order;
 * - each value has a label + sort_order and an optional price modifier per
 *   billing cycle (product_option_pricing.price_modifier);
 * - updating a group replaces its values wholesale (reference updateGroup
 *   deletes + recreates, relying on the FK cascade for the pricing rows).
 */
class ProductOptionController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $productId = $request->query('product_id');

        $groups = ProductOptionGroup::query()
            ->with('product:id,name')
            ->withCount('values')
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->when($productId !== null && $productId !== '', fn ($query) => $query->where('product_id', $productId))
            ->orderBy('product_id')
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

        return view('admin.product-options.create', compact('products'));
    }

    public function store(ProductOptionRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $group = DB::transaction(function () use ($validated) {
                $group = ProductOptionGroup::create([
                    'product_id' => $validated['product_id'],
                    'name' => $validated['name'],
                    'type' => $validated['type'],
                    'sort_order' => (int) ($validated['sort_order'] ?? 0),
                ]);

                $this->saveValues($group, $validated['values'] ?? []);

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

        $products = Product::query()->orderBy('name')->get(['id', 'name']);

        return view('admin.product-options.edit', compact('productOption', 'products'));
    }

    public function update(ProductOptionRequest $request, ProductOptionGroup $productOption): RedirectResponse
    {
        $validated = $request->validated();

        try {
            DB::transaction(function () use ($validated, $productOption) {
                $productOption->update([
                    'product_id' => $validated['product_id'],
                    'name' => $validated['name'],
                    'type' => $validated['type'],
                    'sort_order' => (int) ($validated['sort_order'] ?? 0),
                ]);

                $this->saveValues($productOption, $validated['values'] ?? []);
            });
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['error' => 'Could not update option group: '.$e->getMessage()]);
        }

        return redirect()
            ->route('admin.product-options.index')
            ->with('success', "Option group {$productOption->name} updated.");
    }

    public function destroy(Request $request, ProductOptionGroup $productOption): RedirectResponse
    {
        $productOption->delete(); // cascades to values and their pricing

        return redirect()
            ->route('admin.product-options.index')
            ->with('success', "Option group {$productOption->name} deleted.");
    }

    /**
     * Replace the group's values wholesale (reference updateGroup behavior).
     * Each value may carry a price modifier for any of the billing cycles.
     *
     * @param  array<int, array<string, mixed>>  $values
     */
    private function saveValues(ProductOptionGroup $group, array $values): void
    {
        $group->values()->delete();

        foreach ($values as $value) {
            if (empty($value['label'])) {
                continue;
            }

            $optionValue = $group->values()->create([
                'label' => $value['label'],
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
}
