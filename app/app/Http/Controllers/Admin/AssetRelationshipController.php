<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAssetRelationshipRequest;
use App\Models\AssetRelationship;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin CRUD for asset_relationships — polymorphic reporting links between
 * assets (servers, products, datacenters, ...). Read-only reporting data:
 * no billing, order, or orchestration coupling.
 */
class AssetRelationshipController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $parentKind = (string) $request->query('parent_kind', '');
        $childKind = (string) $request->query('child_kind', '');
        $relationshipType = (string) $request->query('relationship_type', '');

        $relationships = AssetRelationship::query()
            ->when($search !== '', fn ($query) => $query->where('label', 'like', "%{$search}%"))
            ->when(array_key_exists($parentKind, AssetRelationship::ASSET_KINDS), fn ($query) => $query->where('parent_kind', $parentKind))
            ->when(array_key_exists($childKind, AssetRelationship::ASSET_KINDS), fn ($query) => $query->where('child_kind', $childKind))
            ->when(in_array($relationshipType, AssetRelationship::RELATIONSHIP_TYPES, true), fn ($query) => $query->where('relationship_type', $relationshipType))
            ->orderBy('parent_kind')
            ->orderBy('parent_id')
            ->orderBy('child_kind')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.asset_relationships.index', [
            'relationships' => $relationships,
            'search' => $search,
            'parentKind' => $parentKind,
            'childKind' => $childKind,
            'relationshipType' => $relationshipType,
            'kinds' => AssetRelationship::ASSET_KINDS,
            'types' => AssetRelationship::RELATIONSHIP_TYPES,
        ]);
    }

    public function create(): View
    {
        return view('admin.asset_relationships.create', [
            'kinds' => AssetRelationship::ASSET_KINDS,
            'types' => AssetRelationship::RELATIONSHIP_TYPES,
        ]);
    }

    public function store(StoreAssetRelationshipRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        try {
            AssetRelationship::create($this->attributesFrom($validated));
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['error' => 'Could not create relationship: '.$e->getMessage()]);
        }

        return $this->redirectAfterSave($request, 'Asset relationship created.');
    }

    public function edit(AssetRelationship $assetRelationship): View
    {
        return view('admin.asset_relationships.edit', [
            'relationship' => $assetRelationship,
            'kinds' => AssetRelationship::ASSET_KINDS,
            'types' => AssetRelationship::RELATIONSHIP_TYPES,
        ]);
    }

    public function update(StoreAssetRelationshipRequest $request, AssetRelationship $assetRelationship): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $assetRelationship->update($this->attributesFrom($validated));
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['error' => 'Could not update relationship: '.$e->getMessage()]);
        }

        return $this->redirectAfterSave($request, 'Asset relationship updated.');
    }

    public function destroy(Request $request, AssetRelationship $assetRelationship): RedirectResponse
    {
        $assetRelationship->delete();

        return $this->redirectAfterSave($request, 'Asset relationship deleted.');
    }

    /**
     * Redirect back to the originating page after a successful save. When the
     * request came from an admin hosting show page (inline attach/detach on
     * the Assets tab), return there with the tab re-opened; otherwise fall
     * back to the asset relationships index.
     */
    private function redirectAfterSave(Request $request, string $message): RedirectResponse
    {
        $referer = (string) $request->headers->get('referer');

        if (preg_match('#/admin/hosting/(\d+)#', $referer, $matches)) {
            return redirect()
                ->route('admin.hosting.show', ['hostingAccount' => (int) $matches[1], 'tab' => 'assets'])
                ->with('success', $message);
        }

        return redirect()
            ->route('admin.asset-relationships.index')
            ->with('success', $message);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributesFrom(array $validated): array
    {
        return [
            'parent_kind' => $validated['parent_kind'],
            'parent_id' => $validated['parent_id'],
            'child_kind' => $validated['child_kind'],
            'child_id' => $validated['child_id'],
            'relationship_type' => $validated['relationship_type'],
            'label' => $validated['label'] ?? null,
            'sort_order' => $validated['sort_order'] ?? 0,
            'notes' => $validated['notes'] ?? null,
        ];
    }
}
