<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\AdminLteRbacSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the activation of `inventory.view` for the support role.
 *
 * `inventory.view` is declared in AdminLteRbacSeeder's inventory and gates the
 * inventory index/show routes and the InventoryAssetSearchProvider. It is
 * granted to the support role ONLY, so:
 *
 *  - the seeder (fresh installs) must attach it to support and to no other
 *    non-admin role;
 *  - the backfill migration (existing installs, where the updater runs
 *    `migrate --force` and never `db:seed`) must attach it to support and must
 *    be idempotent.
 *
 * Everything runs on sqlite :memory: via RefreshDatabase (`phpunit.xml`), no
 * MySQL, matching the SearchPermissionBackfillTest conventions.
 */
final class InventoryPermissionBackfillTest extends TestCase
{
    use RefreshDatabase;

    private const BACKFILL_MIGRATION = 'migrations/2026_10_06_000001_backfill_support_inventory_view_permission.php';

    private const PERMISSION = 'inventory.view';

    /**
     * Run the real backfill migration against the current (seeded) database.
     *
     * RefreshDatabase already ran it once during migrate:fresh — when the
     * adminlte_* tables existed but were empty — so this replays it on a
     * populated install, which is what an upgrade does.
     */
    private function runBackfillMigration(): void
    {
        $path = database_path(self::BACKFILL_MIGRATION);

        $this->assertFileExists($path, 'The inventory-permission backfill migration is missing.');

        /** @var Migration $migration */
        $migration = require $path;

        $migration->up();
    }

    private function inventoryPermissionId(): int
    {
        $id = Permission::where('name', self::PERMISSION)->value('id');

        $this->assertNotNull($id, 'Permission [inventory.view] is not present in adminlte_permissions.');

        return (int) $id;
    }

    private function inventoryPivotCount(): int
    {
        return DB::table('adminlte_permission_role')
            ->where('permission_id', $this->inventoryPermissionId())
            ->count();
    }

    // ─────────────────────────────────────────────────────────────────
    // Seeder (fresh-install path)
    // ─────────────────────────────────────────────────────────────────

    public function test_seeder_grants_inventory_view_to_support_only(): void
    {
        $this->seed(AdminLteRbacSeeder::class);

        $support = Role::where('name', 'support')->first();

        $this->assertNotNull($support, 'Role [support] was not seeded.');
        $this->assertTrue(
            $support->hasPermission(self::PERMISSION),
            'Role [support] must hold the inventory.view permission.'
        );

        foreach (['staff', 'viewer'] as $name) {
            $role = Role::where('name', $name)->first();

            $this->assertNotNull($role, "Role [{$name}] was not seeded.");
            $this->assertFalse(
                $role->hasPermission(self::PERMISSION),
                "Role [{$name}] must NOT hold inventory.view (support-only grant)."
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Backfill migration (upgrade path)
    // ─────────────────────────────────────────────────────────────────

    public function test_backfill_migration_grants_inventory_view_to_support_idempotently(): void
    {
        $this->seed(AdminLteRbacSeeder::class);

        // Simulate a pre-backfill install: the role matrix exists but the
        // inventory permission and every one of its pivot rows are absent.
        DB::table('adminlte_permission_role')
            ->where('permission_id', $this->inventoryPermissionId())
            ->delete();
        DB::table('adminlte_permissions')->where('name', self::PERMISSION)->delete();

        $this->assertSame(0, Permission::where('name', self::PERMISSION)->count(), 'Precondition: inventory permission removed.');

        $this->runBackfillMigration();

        $this->assertSame(1, Permission::where('name', self::PERMISSION)->count(), 'The migration must create exactly one inventory permission row.');
        $this->assertSame(
            'View Inventory',
            Permission::where('name', self::PERMISSION)->value('label'),
            'The backfilled permission label must match the seeder inventory.'
        );

        $support = Role::where('name', 'support')->first();

        $this->assertNotNull($support, 'Role [support] was not seeded.');
        $this->assertTrue(
            $support->hasPermission(self::PERMISSION),
            'Backfill must grant inventory.view to role [support].'
        );
        // Support-only: no other role gains the pivot from this backfill.
        $this->assertSame(1, $this->inventoryPivotCount(), 'The migration must attach inventory.view to the support role and no other.');

        // Idempotency: a second run must change NOTHING — no duplicate
        // permission rows, no duplicate pivot rows.
        $permissionCount = Permission::where('name', self::PERMISSION)->count();
        $pivotCount = $this->inventoryPivotCount();
        $totalPivotCount = DB::table('adminlte_permission_role')->count();

        $this->runBackfillMigration();

        $this->assertSame($permissionCount, Permission::where('name', self::PERMISSION)->count(), 'Re-running the migration duplicated the inventory permission row.');
        $this->assertSame($pivotCount, $this->inventoryPivotCount(), 'Re-running the migration changed the inventory pivot row count.');
        $this->assertSame($totalPivotCount, DB::table('adminlte_permission_role')->count(), 'Re-running the migration changed the total pivot row count.');
    }

    public function test_backfill_migration_is_a_noop_when_the_support_role_is_absent(): void
    {
        $this->seed(AdminLteRbacSeeder::class);

        $supportId = Role::where('name', 'support')->value('id');

        // Simulate a fresh install: no support role, no inventory permission.
        DB::table('adminlte_permission_role')
            ->where('permission_id', $this->inventoryPermissionId())
            ->delete();
        DB::table('adminlte_permissions')->where('name', self::PERMISSION)->delete();

        if ($supportId !== null) {
            DB::table('adminlte_permission_role')->where('role_id', $supportId)->delete();
            DB::table('adminlte_roles')->where('id', $supportId)->delete();
        }

        $this->assertNull(Role::where('name', 'support')->value('id'), 'Precondition: support role removed.');

        $this->runBackfillMigration();

        $this->assertNull(
            Role::where('name', 'support')->value('id'),
            'The migration must not recreate the support role.'
        );

        // The permission row may be (re)declared, but with no support role the
        // grant must be a no-op.
        $permissionId = Permission::where('name', self::PERMISSION)->value('id');

        $this->assertNotNull($permissionId, 'The migration should still declare the permission row.');
        $this->assertSame(
            0,
            DB::table('adminlte_permission_role')->where('permission_id', $permissionId)->count(),
            'No inventory.view pivot may be attached while the support role is absent.'
        );
    }
}
