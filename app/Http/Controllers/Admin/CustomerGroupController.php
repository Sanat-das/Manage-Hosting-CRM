<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin customer groups — uses ProductGroup as the underlying model.
 * Maps to sidebar route admin.customer-groups.index.
 */
class CustomerGroupController extends Controller
{
    public function index(): View
    {
        $groups = ProductGroup::with('parent')
            ->withCount('products')
            ->gridSort([
                'name' => 'name',
                'description' => 'description',
                'parent' => 'parent_id',
                'status' => 'status',
                'created_at' => 'created_at',
                'sort_order' => 'sort_order',
            ])
            ->orderBy('sort_order')
            ->paginate(20);

        return view('admin.customer_groups.index', compact('groups'));
    }

    public function create(): View
    {
        $parentGroups = ProductGroup::whereNull('parent_id')->orderBy('name')->get();

        return view('admin.customer_groups.create', compact('parentGroups'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'parent_id' => ['nullable', 'integer', 'exists:product_groups,id'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'status' => ['sometimes', 'string', 'in:active,inactive'],
        ]);

        $validated['slug'] = \Str::slug($validated['name']);
        $validated['status'] = $validated['status'] ?? 'active';
        $validated['sort_order'] = $validated['sort_order'] ?? 0;

        ProductGroup::create($validated);

        return redirect()
            ->route('admin.customer-groups.index')
            ->with('success', 'Customer group created.');
    }

    public function show(ProductGroup $customerGroup): View
    {
        $customerGroup->load('products');

        return view('admin.customer_groups.show', ['group' => $customerGroup]);
    }

    public function edit(ProductGroup $customerGroup): View
    {
        $parentGroups = ProductGroup::whereNull('parent_id')
            ->where('id', '!=', $customerGroup->id)
            ->orderBy('name')
            ->get();

        return view('admin.customer_groups.edit', ['group' => $customerGroup, 'parentGroups' => $parentGroups]);
    }

    public function update(Request $request, ProductGroup $customerGroup): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:product_groups,name,'.$customerGroup->id],
            'description' => ['nullable', 'string', 'max:500'],
            'parent_id' => ['nullable', 'integer', 'exists:product_groups,id'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'status' => ['sometimes', 'string', 'in:active,inactive'],
        ]);

        $validated['slug'] = \Str::slug($validated['name']);

        $customerGroup->update($validated);

        return redirect()
            ->route('admin.customer-groups.show', $customerGroup)
            ->with('success', 'Customer group updated.');
    }

    public function destroy(ProductGroup $customerGroup): RedirectResponse
    {
        $customerGroup->delete();

        return redirect()
            ->route('admin.customer-groups.index')
            ->with('success', 'Customer group deleted.');
    }
}
