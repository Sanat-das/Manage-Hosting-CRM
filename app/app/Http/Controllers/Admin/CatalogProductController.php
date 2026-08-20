<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CatalogProduct;
use App\Models\ProductGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CatalogProductController extends Controller
{
    public function index(Request $request): View
    {
        $products = CatalogProduct::with('category')->orderByDesc('id')->paginate(20);

        return view('admin.catalog_products.index', compact('products'));
    }

    public function create(): View
    {
        $categories = ProductGroup::orderBy('name')->get();

        return view('admin.catalog_products.create', compact('categories'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'sku' => ['required', 'string', 'max:100', 'unique:catalog_products,sku'],
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:product_groups,id'],
            'description' => ['nullable', 'string', 'max:1000'],
            'product_type' => ['sometimes', 'string', 'in:shared,reseller,dedicated,vps,domain'],
            'provisioning_method' => ['nullable', 'string', 'max:100'],
            'billing_model' => ['sometimes', 'string', 'in:recurring,one_time,usage'],
            'require_domain' => ['sometimes', 'boolean'],
            'show_in_order' => ['sometimes', 'boolean'],
            'only_admin' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'status' => ['sometimes', 'string', 'in:active,inactive'],
        ]);
        $validated['product_type'] = $validated['product_type'] ?? 'shared';
        $validated['billing_model'] = $validated['billing_model'] ?? 'recurring';
        $validated['require_domain'] = $validated['require_domain'] ?? false;
        $validated['show_in_order'] = $validated['show_in_order'] ?? true;
        $validated['only_admin'] = $validated['only_admin'] ?? false;
        $validated['sort_order'] = $validated['sort_order'] ?? 0;
        $validated['status'] = $validated['status'] ?? 'active';
        $validated['version'] = 1;
        CatalogProduct::create($validated);

        return redirect()->route('admin.catalog-products.index')->with('success', 'Catalog product created.');
    }

    public function show(CatalogProduct $catalogProduct): View
    {
        $catalogProduct->load('category');

        return view('admin.catalog_products.show', ['product' => $catalogProduct]);
    }

    public function edit(CatalogProduct $catalogProduct): View
    {
        $categories = ProductGroup::orderBy('name')->get();

        return view('admin.catalog_products.edit', ['product' => $catalogProduct, 'categories' => $categories]);
    }

    public function update(Request $request, CatalogProduct $catalogProduct): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', 'string', 'in:active,inactive'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
        $catalogProduct->update($validated);

        return redirect()->route('admin.catalog-products.show', $catalogProduct)->with('success', 'Catalog product updated.');
    }

    public function destroy(CatalogProduct $catalogProduct): RedirectResponse
    {
        $catalogProduct->delete();

        return redirect()->route('admin.catalog-products.index')->with('success', 'Catalog product deleted.');
    }
}
