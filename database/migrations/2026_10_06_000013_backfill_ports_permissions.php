<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill the `ports.view` / `ports.manage` permissions introduced with the
 * device port registry and connection management surface.
 *
 * AdminLteRbacSeeder is the single authority for the permission inventory and
 * now declares both, but the in-app updater runs `php artisan migrate --force`
 * and NEVER `db:seed`. The port routes (routes/admin/inventory.php) and the
 * Connections sidebar entry (config/adminlte.php) gate on them, so on every
 * already installed system the seeder alone would leave zero pivot rows and the
 * new surface would simply vanish — for admin as well, because the admin role is
 * not an implicit superuser (HasRoles).
 *
 * Labels must match AdminLteRbacSeeder::$permissions character-for-character;
 * SeederIntegrityTest::test_permission_labels_match_the_seeder_inventory fails
 * on any drift.
 *
 * Same shape as 2026_10_06_000001_backfill_support_inventory_view_permission:
 * idempotent, guarded on the tables existing, and a no-op on a fresh install
 * because migrations run before seeders and the roles do not exist yet.
 */
return new class extends Migration
{
    /** Permission name => label, matching AdminLteRbacSeeder exactly. */
    private const PERMISSIONS = [
        'ports.view' => 'View Ports & Connections',
        'ports.manage' => 'Manage Ports & Connections',
    ];

    /** Role names keyed by the permission each one receives. */
    private const GRANTS = [
        'ports.view' => ['admin', 'support'],
        'ports.manage' => ['admin'],
    ];

    public function up(): void
    {
        foreach (['adminlte_roles', 'adminlte_permissions', 'adminlte_permission_role'] as $table) {
            if (! Schema::hasTable($table)) {
                return;
            }
        }

        $now = now();

        foreach (self::PERMISSIONS as $name => $label) {
            DB::table('adminlte_permissions')->insertOrIgnore([
                'name' => $name,
                'label' => $label,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (self::GRANTS as $permission => $roleNames) {
            $this->grant($permission, $roleNames);
        }
    }

    /**
     * Link a permission to each named role that exists.
     *
     * @param  list<string>  $roleNames
     */
    private function grant(string $permission, array $roleNames): void
    {
        $permissionId = DB::table('adminlte_permissions')->where('name', $permission)->value('id');

        if ($permissionId === null) {
            return;
        }

        $roleIds = DB::table('adminlte_roles')->whereIn('name', $roleNames)->pluck('id');

        foreach ($roleIds as $roleId) {
            DB::table('adminlte_permission_role')->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id' => (int) $roleId,
            ]);
        }
    }

    /**
     * Not reversed: this only ever adds the permission rows and pivot links that
     * are supposed to exist, with no record of whether either predated it.
     * Removing them would take the port surface away on a rollback that was
     * meant to be schema-only. Same rationale as the 2026-09-23 search and
     * 2026-10-06 inventory backfills.
     */
    public function down(): void
    {
        //
    }
};
