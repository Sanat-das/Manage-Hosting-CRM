<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAssetRelationshipRequest;
use App\Models\AssetRelationship;
use App\Models\Datacenter;
use App\Models\HostingAccount;
use App\Models\InventoryAsset;
use App\Models\IpSubnet;
use App\Models\License;
use App\Models\Product;
use App\Models\Rack;
use App\Models\ResourcePool;
use App\Models\Server;
use App\Models\Vlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Admin CRUD for asset_relationships — polymorphic reporting links between
 * assets (servers, products, datacenters, ...). Read-only reporting data:
 * no billing, order, or orchestration coupling.
 */
class AssetRelationshipController extends Controller
{
    private const PER_PAGE = 20;

    /**
     * Asset kind => [model class, display column, show route]. Mirrors the
     * resolution maps used by ServerHostingTreeController / ProductHostedOnController
     * and HostingController::ASSET_DISPLAY: the index resolves each side of a
     * relationship to a display name and a link. Kinds absent here, or rows
     * whose asset no longer resolves, fall back to "Kind #id" in the view.
     *
     * @var array<string, array{class-string, string, string}>
     */
    private const ASSET_DISPLAY = [
        'product' => [Product::class, 'name', 'admin.products.show'],
        'server' => [Server::class, 'name', 'admin.servers.show'],
        'hosting_account' => [HostingAccount::class, 'host_name', 'admin.hosting.show'],
        'datacenter' => [Datacenter::class, 'name', 'admin.datacenters.show'],
        'rack' => [Rack::class, 'name', 'admin.racks.show'],
        'ip_subnet' => [IpSubnet::class, 'name', 'admin.ip-subnets.show'],
        'vlan' => [Vlan::class, 'name', 'admin.vlans.show'],
        'license' => [License::class, 'license_key', 'admin.licenses.show'],
        'resource_pool' => [ResourcePool::class, 'name', 'admin.resource-pools.show'],
        'inventory_asset' => [InventoryAsset::class, 'asset_tag', 'admin.inventory-assets.show'],
    ];

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
            ->gridSort([
                'parent' => 'parent_kind',
                'relationship_type' => 'relationship_type',
                'child' => 'child_kind',
                'label' => 'label',
                'sort_order' => 'sort_order',
            ])
            ->orderBy('parent_kind')
            ->orderBy('parent_id')
            ->orderBy('child_kind')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.asset_relationships.index', [
            'relationships' => $relationships,
            'assetReferences' => $this->resolveAssetReferences($relationships->getCollection()),
            'search' => $search,
            'parentKind' => $parentKind,
            'childKind' => $childKind,
            'relationshipType' => $relationshipType,
            'kinds' => AssetRelationship::ASSET_KINDS,
            'types' => AssetRelationship::RELATIONSHIP_TYPES,
        ]);
    }

    /**
     * Resolve the display name and link for both sides of every listed
     * relationship, one query per kind (no N+1). The result is keyed by
     * relationship id: [id => ['parent' => [name, url], 'child' => [name, url]]].
     * A null name means the kind is unmapped or the asset no longer exists, so
     * the view keeps its "#id" fallback next to the kind badge.
     *
     * @param  Collection<int, AssetRelationship>  $relationships
     * @return array<int, array{parent: array{name: ?string, url: ?string}, child: array{name: ?string, url: ?string}}>
     */
    private function resolveAssetReferences(Collection $relationships): array
    {
        $resolved = [];

        foreach ($relationships as $relationship) {
            $resolved[$relationship->id] = [
                'parent' => ['name' => null, 'url' => null],
                'child' => ['name' => null, 'url' => null],
            ];
        }

        foreach (['parent' => 'parent_kind', 'child' => 'child_kind'] as $side => $kindColumn) {
            $idsByKind = [];
            foreach ($relationships as $relationship) {
                $kind = $relationship->{$kindColumn};

                if (array_key_exists($kind, self::ASSET_DISPLAY)) {
                    $idsByKind[$kind][(int) $relationship->{$side.'_id'}] = true;
                }
            }

            foreach ($idsByKind as $kind => $ids) {
                [$model, $column, $routeName] = self::ASSET_DISPLAY[$kind];
                $names = $model::query()->whereIn('id', array_keys($ids))->pluck($column, 'id');

                foreach ($relationships as $relationship) {
                    if ($relationship->{$kindColumn} !== $kind) {
                        continue;
                    }

                    $assetId = (int) $relationship->{$side.'_id'};

                    if (! $names->has($assetId)) {
                        continue;
                    }

                    $resolved[$relationship->id][$side] = [
                        'name' => (string) $names[$assetId],
                        'url' => route($routeName, $assetId),
                    ];
                }
            }
        }

        return $resolved;
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
     * the Assets tab), return there with the tab re-opened; when it came from
     * an inventory asset show page (inline child/parent management), return to
     * that asset; otherwise fall back to the asset relationships index.
     */
    private function redirectAfterSave(Request $request, string $message): RedirectResponse
    {
        $referer = (string) $request->headers->get('referer');

        if (preg_match('#/admin/hosting/(\d+)#', $referer, $matches)) {
            return redirect()
                ->route('admin.hosting.show', ['hostingAccount' => (int) $matches[1], 'tab' => 'assets'])
                ->with('success', $message);
        }

        if (preg_match('#/admin/inventory-assets/(\d+)#', $referer, $matches)) {
            return redirect()
                ->route('admin.inventory-assets.show', ['inventoryAsset' => (int) $matches[1]])
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
