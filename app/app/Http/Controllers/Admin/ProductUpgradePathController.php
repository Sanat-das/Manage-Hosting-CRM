<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductUpgradePath;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin product upgrade paths (Tier 4.4).
 *
 * Configuration only — records which (from, to) product pairs may be
 * upgraded. The billing engine that prices upgrades is intentionally out of
 * scope here. Duplicate (from, to) pairs are rejected by the DB unique
 * constraint (and surfaced here as a friendly validation error).
 */
class ProductUpgradePathController extends Controller
{
    public function index(): View
    {
        $paths = ProductUpgradePath::with(['fromProduct', 'toProduct'])
            ->orderBy('from_product_id')
            ->orderBy('to_product_id')
            ->paginate(20);

        return view('admin.product_upgrades.index', compact('paths'));
    }

    public function create(): View
    {
        return view('admin.product_upgrades.create', $this->formData());
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'from_product_id' => ['required', 'integer', 'exists:products,id'],
            'to_product_id' => ['required', 'integer', 'different:from_product_id', 'exists:products,id'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        try {
            ProductUpgradePath::create([
                'from_product_id' => $validated['from_product_id'],
                'to_product_id' => $validated['to_product_id'],
                'enabled' => (bool) ($validated['enabled'] ?? true),
            ]);
        } catch (QueryException) {
            return back()
                ->withInput()
                ->withErrors(['to_product_id' => 'This upgrade path already exists.']);
        }

        return redirect()
            ->route('admin.product-upgrades.index')
            ->with('success', 'Upgrade path created.');
    }

    public function show(ProductUpgradePath $productUpgradePath): View
    {
        $productUpgradePath->load(['fromProduct', 'toProduct']);

        return view('admin.product_upgrades.show', ['path' => $productUpgradePath]);
    }

    public function edit(ProductUpgradePath $productUpgradePath): View
    {
        return view('admin.product_upgrades.edit', array_merge(
            ['path' => $productUpgradePath],
            $this->formData(),
        ));
    }

    public function update(Request $request, ProductUpgradePath $productUpgradePath): RedirectResponse
    {
        $validated = $request->validate([
            'from_product_id' => ['required', 'integer', 'exists:products,id'],
            'to_product_id' => ['required', 'integer', 'different:from_product_id', 'exists:products,id'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        try {
            $productUpgradePath->update([
                'from_product_id' => $validated['from_product_id'],
                'to_product_id' => $validated['to_product_id'],
                'enabled' => (bool) ($validated['enabled'] ?? true),
            ]);
        } catch (QueryException) {
            return back()
                ->withInput()
                ->withErrors(['to_product_id' => 'This upgrade path already exists.']);
        }

        return redirect()
            ->route('admin.product-upgrades.show', $productUpgradePath)
            ->with('success', 'Upgrade path updated.');
    }

    public function destroy(ProductUpgradePath $productUpgradePath): RedirectResponse
    {
        $productUpgradePath->delete();

        return redirect()
            ->route('admin.product-upgrades.index')
            ->with('success', 'Upgrade path deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        return [
            'products' => Product::query()
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name']),
        ];
    }
}
