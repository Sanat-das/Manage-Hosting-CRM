<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Rows created by this migration carry this label; down() removes exactly
     * them, so an imported hierarchy edge is never deleted by a rollback.
     */
    private const BACKFILL_LABEL = 'Backfilled hierarchy';

    /**
     * Imported data may carry a legacy `inventory_assets.parent_asset_id` with
     * no matching `asset_relationships` edge. Mirror each such parent link as a
     * `contains` edge so the canonical relationship graph is complete. The
     * unique (parent, child, type) index plus the per-chunk existence check
     * make the run idempotent: running it twice inserts nothing the second time.
     */
    public function up(): void
    {
        $now = now();

        DB::table('inventory_assets')
            ->whereNotNull('parent_asset_id')
            ->whereNull('deleted_at')
            ->select(['id', 'parent_asset_id'])
            ->chunkById(500, function ($assets) use ($now): void {
                $parentIds = $assets->pluck('parent_asset_id')
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->all();

                // Only link to a parent that still exists and is not soft-deleted.
                $liveParentIds = DB::table('inventory_assets')
                    ->whereIn('id', $parentIds)
                    ->whereNull('deleted_at')
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                $childIds = $assets->pluck('id')->map(fn ($id) => (int) $id)->all();

                $existing = DB::table('asset_relationships')
                    ->where('parent_kind', 'inventory_asset')
                    ->where('child_kind', 'inventory_asset')
                    ->where('relationship_type', 'contains')
                    ->whereIn('child_id', $childIds)
                    ->get(['parent_id', 'child_id'])
                    ->map(fn ($edge) => $edge->parent_id.'-'.$edge->child_id)
                    ->all();

                $rows = [];

                foreach ($assets as $asset) {
                    $parentId = (int) $asset->parent_asset_id;
                    $childId = (int) $asset->id;

                    if (! in_array($parentId, $liveParentIds, true)) {
                        continue;
                    }

                    if (in_array($parentId.'-'.$childId, $existing, true)) {
                        continue;
                    }

                    $rows[] = [
                        'parent_kind' => 'inventory_asset',
                        'parent_id' => $parentId,
                        'child_kind' => 'inventory_asset',
                        'child_id' => $childId,
                        'relationship_type' => 'contains',
                        'label' => self::BACKFILL_LABEL,
                        'sort_order' => 0,
                        'notes' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('asset_relationships')->insertOrIgnore($rows);
                }
            });
    }

    public function down(): void
    {
        DB::table('asset_relationships')
            ->where('label', self::BACKFILL_LABEL)
            ->delete();
    }
};
