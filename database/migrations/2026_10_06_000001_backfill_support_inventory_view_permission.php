<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill the `inventory.view` permission and attach it to the support role.
 *
 * AdminLteRbacSeeder is the single authority for the permission inventory and
 * now grants `inventory.view` to the support role, but the in-app updater runs
 * `php artisan migrate --force` and NEVER `db:seed`. The inventory index/show
 * routes and the InventoryAssetSearchProvider (config/search.php) gate on
 * `inventory.view`, so on every already installed system the seeder alone would
 * leave zero `inventory.view` pivot rows for support and the inventory surface
 * would simply vanish from that role.
 *
 * The grant is hardcoded to the single role keyed by NAME, not label: labels
 * are editable in the Roles screen, so the name is the stable identifier. No
 * other role is granted -- this is a deliberate support-only decision, mirroring
 * the seeder's matrix.
 *
 * Same shape as 2026_09_23_000001_backfill_search_permission.php: idempotent,
 * guarded on the tables existing, and a no-op on a fresh install because
 * migrations run before seeders and the support role does not exist yet.
 */
return new class extends Migration
{
    /** The permission this migration activates. */
    private const PERMISSION = 'inventory.view';

    /** Label must match AdminLteRbacSeeder::$permissions. */
    private const LABEL = 'View Inventory';

    /** The only role name that gains this permission. */
    private const ROLE = 'support';

    public function up(): void
    {
        foreach (['adminlte_roles', 'adminlte_permissions', 'adminlte_permission_role'] as $table) {
            if (! Schema::hasTable($table)) {
                return;
            }
        }

        $now = now();

        DB::table('adminlte_permissions')->insertOrIgnore([
            'name' => self::PERMISSION,
            'label' => self::LABEL,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $permissionId = DB::table('adminlte_permissions')->where('name', self::PERMISSION)->value('id');
        $roleId = DB::table('adminlte_roles')->where('name', self::ROLE)->value('id');

        if ($permissionId === null || $roleId === null) {
            return;
        }

        DB::table('adminlte_permission_role')->insertOrIgnore([
            'permission_id' => $permissionId,
            'role_id' => (int) $roleId,
        ]);
    }

    /**
     * Not reversed: this only ever adds the permission row and the pivot link
     * that are supposed to exist, and there is no record of whether either
     * predated it. Removing them would take the inventory surface away from the
     * support role on a rollback that was meant to be schema-only. Same
     * rationale as the 2026-09-23 search backfill.
     */
    public function down(): void
    {
        //
    }
};
