<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AssetRelationship;
use App\Models\InventoryAsset;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Read-only "Inventory dependency tree" report — the inventory_asset →
 * inventory_asset slice of asset_relationships, assembled into a nested tree.
 * Each parent asset is the root, its linked assets listed beneath it with the
 * relationship_type and label on the child row.
 *
 * A visited-set guard turns a cyclic graph into a bounded walk (the repeated
 * node is marked "already shown"), and a relationship whose asset no longer
 * exists renders as a "missing" placeholder rather than failing.
 */
class InventoryTreeController extends Controller
{
    public function index(): View
    {
        $relationships = AssetRelationship::query()
            ->where('parent_kind', 'inventory_asset')
            ->where('child_kind', 'inventory_asset')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $tags = $this->resolveTags($relationships);

        return view('admin.inventory_tree.index', [
            'relationships' => $relationships,
            'rows' => $this->buildRows($relationships, $tags),
        ]);
    }

    /**
     * asset id => asset_tag for every asset referenced on either side of the
     * given relationships, in one query (no N+1).
     *
     * @param  Collection<int, AssetRelationship>  $relationships
     * @return array<int, string>
     */
    private function resolveTags(Collection $relationships): array
    {
        $ids = [];
        foreach ($relationships as $relationship) {
            $ids[(int) $relationship->parent_id] = true;
            $ids[(int) $relationship->child_id] = true;
        }

        if ($ids === []) {
            return [];
        }

        return InventoryAsset::query()
            ->whereIn('id', array_keys($ids))
            ->pluck('asset_tag', 'id')
            ->mapWithKeys(fn ($tag, $id): array => [(int) $id => (string) $tag])
            ->all();
    }

    /**
     * Walk the relationship graph into a flat, ordered list of rows (depth
     * carries the nesting). Roots are assets with no incoming link; anything
     * left after the roots is a cycle or a rootless orphan chain and is walked
     * afterwards, so nothing is silently dropped.
     *
     * @param  Collection<int, AssetRelationship>  $relationships
     * @param  array<int, string>  $tags
     * @return array<int, array{depth: int, asset_id: int, asset_tag: ?string, relationship_type: ?string, label: ?string, repeated: bool}>
     */
    private function buildRows(Collection $relationships, array $tags): array
    {
        $children = [];
        $hasIncoming = [];

        foreach ($relationships as $relationship) {
            $parentId = (int) $relationship->parent_id;
            $children[$parentId][] = $relationship;
            $hasIncoming[(int) $relationship->child_id] = true;
        }

        $rows = [];
        $visited = [];

        $walk = function (int $assetId, int $depth, ?AssetRelationship $edge) use (&$walk, &$rows, &$visited, $children, $tags): void {
            $repeated = isset($visited[$assetId]);
            $visited[$assetId] = true;

            $rows[] = [
                'depth' => $depth,
                'asset_id' => $assetId,
                'asset_tag' => $tags[$assetId] ?? null,
                'relationship_type' => $edge?->relationship_type,
                'label' => $edge?->label,
                'repeated' => $repeated,
            ];

            if ($repeated) {
                return;
            }

            foreach ($children[$assetId] ?? [] as $childRelationship) {
                $walk((int) $childRelationship->child_id, $depth + 1, $childRelationship);
            }
        };

        foreach (array_keys($children) as $parentId) {
            if (! isset($hasIncoming[$parentId])) {
                $walk($parentId, 0, null);
            }
        }

        foreach ($relationships as $relationship) {
            $parentId = (int) $relationship->parent_id;
            if (! isset($visited[$parentId])) {
                $walk($parentId, 0, null);
            }
        }

        return $rows;
    }
}
