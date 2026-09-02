<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductGroupRequest;
use App\Models\ProductGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Admin product group management.
 *
 * Ported from the reference ProductGroupModel: auto-slug generation with
 * uniqueness suffixing, sort_order driven listing, product counts.
 *
 * Divergence from the reference: deleting a group that still contains
 * products is blocked (the local `products.product_group_id` column has no
 * FK constraint, so an unguarded delete would leave orphaned references).
 */
class ProductGroupController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));

        $groups = ProductGroup::query()
            ->with('parent')
            ->withCount('products')
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->gridSort([
                'name' => 'name',
                'slug' => 'slug',
                'parent' => 'parent_id',
                'sort_order' => 'sort_order',
                'status' => 'status',
                'created_at' => 'created_at',
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.product-groups.index', compact('groups', 'search'));
    }

    public function create(): View
    {
        $parents = ProductGroup::query()->where('status', 'active')->orderBy('name')->get();

        return view('admin.product-groups.create', compact('parents'));
    }

    public function store(ProductGroupRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $group = ProductGroup::create([
                'name' => $validated['name'],
                'slug' => $this->uniqueSlug($validated['slug'] ?? null, $validated['name']),
                'description' => $validated['description'] ?? null,
                'parent_id' => $validated['parent_id'] ?? null,
                'sort_order' => (int) ($validated['sort_order'] ?? 0),
                'status' => $validated['status'],
                'is_hosting' => $request->boolean('is_hosting'),
            ]);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['error' => 'Could not create group: '.$e->getMessage()]);
        }

        return redirect()
            ->route('admin.product-groups.index')
            ->with('success', "Group {$group->name} created.");
    }

    public function edit(ProductGroup $productGroup): View
    {
        $parents = ProductGroup::query()
            ->where('status', 'active')
            ->where('id', '!=', $productGroup->id)
            ->orderBy('name')
            ->get();

        return view('admin.product-groups.edit', compact('productGroup', 'parents'));
    }

    public function update(ProductGroupRequest $request, ProductGroup $productGroup): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $productGroup->update([
                'name' => $validated['name'],
                'slug' => $this->uniqueSlug($validated['slug'] ?? null, $validated['name'], $productGroup->id),
                'description' => $validated['description'] ?? null,
                'parent_id' => $validated['parent_id'] ?? null,
                'sort_order' => (int) ($validated['sort_order'] ?? 0),
                'status' => $validated['status'],
                'is_hosting' => $request->boolean('is_hosting'),
            ]);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['error' => 'Could not update group: '.$e->getMessage()]);
        }

        return redirect()
            ->route('admin.product-groups.index')
            ->with('success', "Group {$productGroup->name} updated.");
    }

    public function destroy(Request $request, ProductGroup $productGroup): RedirectResponse
    {
        $productCount = $productGroup->products()->count();

        if ($productCount > 0) {
            return back()
                ->with('error', "Cannot delete group {$productGroup->name}: it still contains {$productCount} product(s).");
        }

        $productGroup->delete();

        return redirect()
            ->route('admin.product-groups.index')
            ->with('success', "Group {$productGroup->name} deleted.");
    }

    /**
     * Slugify the given value (or derive it from the name) while keeping it
     * unique — appends a numeric suffix on collision (reference generateSlug).
     */
    private function uniqueSlug(?string $slug, string $name, int $excludeId = 0): string
    {
        $base = Str::slug($slug !== null && $slug !== '' ? $slug : $name);
        if ($base === '') {
            $base = 'group-'.Str::lower(Str::random(5));
        }

        $candidate = $base;
        $counter = 1;

        while (ProductGroup::query()
            ->where('slug', $candidate)
            ->when($excludeId > 0, fn ($query) => $query->where('id', '!=', $excludeId))
            ->exists()) {
            $candidate = $base.'-'.$counter++;
        }

        return $candidate;
    }
}
