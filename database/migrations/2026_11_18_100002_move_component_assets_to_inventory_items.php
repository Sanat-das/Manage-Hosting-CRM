<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Move component inventory assets into the NetBox-style inventory_items
 * table.
 *
 * Only rows whose asset_type is one of the component types are touched;
 * servers, switches, PDUs, other_hardware, licenses, certificates and
 * domains are left alone. For each moved row the owning device is resolved
 * with COALESCE(parent_asset_id, asset_relationships contains edge). A row
 * with no resolvable parent throws so the migration never guesses.
 *
 * The pass is idempotent: it selects only rows with deleted_at IS NULL,
 * and every moved source row is soft-deleted inside the same transaction,
 * so a re-run selects zero rows. Relationship rows are plain deletes, not
 * soft deletes, and are removed for moved children.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const COMPONENT_TYPES = ['ram_module', 'cpu', 'ssd', 'hdd', 'gpu', 'raid_controller', 'nic'];

    public function up(): void
    {
        DB::transaction(function () {
            $rows = DB::table('inventory_assets')
                ->whereIn('asset_type', self::COMPONENT_TYPES)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->get();

            $moved = 0;

            foreach ($rows as $asset) {
                $deviceId = DB::selectOne(
                    'SELECT COALESCE(?, (SELECT parent_id FROM asset_relationships WHERE child_kind = ? AND relationship_type = ? AND child_id = ? LIMIT 1)) AS device_id',
                    [$asset->parent_asset_id, 'inventory_asset', 'contains', $asset->id],
                )->device_id;

                if ($deviceId === null) {
                    throw new RuntimeException("inventory_items migration: asset id {$asset->id} (asset_type {$asset->asset_type}) has no resolvable parent device via parent_asset_id or a contains relationship; refusing to guess.");
                }

                $manufacturerId = null;

                if ($asset->manufacturer !== null) {
                    $manufacturerId = DB::table('manufacturers')
                        ->whereRaw('LOWER(name) = LOWER(?)', [$asset->manufacturer])
                        ->value('id');
                }

                $itemId = DB::table('inventory_items')->insertGetId([
                    'inventory_asset_id' => (int) $deviceId,
                    'parent_item_id' => null,
                    'name' => $asset->model ?? $asset->asset_tag,
                    'label' => null,
                    'sku' => null,
                    'manufacturer_id' => $manufacturerId,
                    'part_number' => null,
                    'serial_number' => $asset->serial_number,
                    'asset_tag' => $asset->asset_tag,
                    'status' => $asset->status,
                    'discovered' => false,
                    'description' => null,
                    'notes' => $asset->notes,
                    'created_at' => $asset->created_at,
                    'updated_at' => $asset->updated_at,
                ]);

                DB::table('inventory_assets')
                    ->where('id', $asset->id)
                    ->update([
                        'notes' => ($asset->notes ?? '').' [migrated to inventory_items #'.$itemId.']',
                        'deleted_at' => now(),
                        'updated_at' => now(),
                    ]);

                $deletedRelationships = DB::table('asset_relationships')
                    ->where('relationship_type', 'contains')
                    ->where('child_kind', 'inventory_asset')
                    ->where('child_id', $asset->id)
                    ->delete();

                $moved++;

                $this->comment("inventory_items: moved asset_id={$asset->id} ({$asset->asset_type}) -> item_id={$itemId}, device_id={$deviceId}, manufacturer_id=".($manufacturerId ?? 'null').", contains_edges_deleted={$deletedRelationships}");
            }

            $this->comment("inventory_items: migration complete, {$moved} asset row(s) moved, ".($rows->count() - $moved).' skipped');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('This migration is not reversible. Re-run the source migration that soft-deleted rows instead.');
    }

    /**
     * Emit a progress line during `php artisan migrate`. The base Migration
     * class exposes no output API, so this writes straight to STDERR.
     */
    protected function comment(string $line): void
    {
        $stream = defined('STDERR') ? STDERR : fopen('php://stderr', 'wb');

        if ($stream !== false) {
            fwrite($stream, $line.PHP_EOL);

            if ($stream !== STDERR) {
                fclose($stream);
            }
        }
    }
};
