<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rename the inventory permissions to devices permissions (Stage B of the
 * NetBox alignment plan). Patterned on 2026_10_09_000005_backfill_logs_permissions
 * but a RENAME, not an insert:
 *
 *   inventory.view   -> devices.view   ('View Devices')
 *   inventory.manage -> devices.manage  ('Manage Devices')
 *
 * Pivots in adminlte_permission_role survive the rename because permission
 * ids are stable. Labels must match AdminLteRbacSeeder character-for-character;
 * SeederIntegrityTest fails on any drift. The in-app updater runs
 * `php artisan migrate --force` and never `db:seed`, so THIS migration is the
 * backfill.
 *
 * Per pair:
 *   - devices.* missing AND inventory.* present -> UPDATE the inventory.* row in place.
 *   - BOTH exist (edge) -> re-point pivots from the old id to the new id, then
 *     delete the now-redundant inventory.* row.
 *   - ONLY devices.* exists (fresh install post-seeder-update) -> no-op.
 *
 * NO devices.* row is ever deleted. Old inventory.* rows end this migration
 * with zero references. Guarded on the three adminlte_* tables existing; a
 * no-op otherwise (and a no-op on a fresh install).
 *
 * down() reverses the rename, mirroring up(). Pivot rows stay attached.
 */
return new class extends Migration
{
    /** inventory.* => [devices.*, label], matching AdminLteRbacSeeder exactly. */
    private const RENAMES = [
        'inventory.view' => ['devices.view', 'View Devices'],
        'inventory.manage' => ['devices.manage', 'Manage Devices'],
    ];

    /** Reverse of self::RENAMES, restoring the pre-migration labels. */
    private const RENAMES_REVERSED = [
        'devices.view' => ['inventory.view', 'View Inventory'],
        'devices.manage' => ['inventory.manage', 'Manage Inventory'],
    ];

    public function up(): void
    {
        $this->rename(self::RENAMES);
    }

    public function down(): void
    {
        $this->rename(self::RENAMES_REVERSED);
    }

    /**
     * @param  array<string, array{0: string, 1: string}>  $renames
     */
    private function rename(array $renames): void
    {
        foreach (['adminlte_roles', 'adminlte_permissions', 'adminlte_permission_role'] as $table) {
            if (! Schema::hasTable($table)) {
                return;
            }
        }

        foreach ($renames as $from => [$to, $label]) {
            $fromId = DB::table('adminlte_permissions')->where('name', $from)->value('id');
            $toId = DB::table('adminlte_permissions')->where('name', $to)->value('id');

            if ($toId === null && $fromId !== null) {
                DB::table('adminlte_permissions')->where('id', $fromId)->update([
                    'name' => $to,
                    'label' => $label,
                    'updated_at' => now(),
                ]);

                continue;
            }

            if ($toId !== null && $fromId !== null) {
                // Both exist: re-point pivots from the old id to the new id,
                // then remove the duplicate. Insert-or-ignore semantics by
                // manually skipping role ids already granted the new permission.
                $existingRoleIds = DB::table('adminlte_permission_role')->where('permission_id', $toId)->pluck('role_id');

                DB::table('adminlte_permission_role')
                    ->where('permission_id', $fromId)
                    ->whereNotIn('role_id', $existingRoleIds)
                    ->update(['permission_id' => $toId]);

                DB::table('adminlte_permission_role')->where('permission_id', $fromId)->delete();
                DB::table('adminlte_permissions')->where('id', $fromId)->delete();
            }

            // ONLY $toId !== null: no-op.
        }
    }
};
