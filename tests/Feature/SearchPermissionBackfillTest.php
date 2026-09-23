<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\AdminLteRbacSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the activation of the dormant `search` permission.
 *
 * Before this change `search` was declared in AdminLteRbacSeeder's inventory
 * but granted to no role, while `/admin/search` was gated on `dashboard.view`.
 * The permission becomes enforceable with the global search rollout, so:
 *
 *  - the seeder (fresh installs) must grant it to exactly the roles that hold
 *    `dashboard.view` today, preserving current access;
 *  - the backfill migration (existing installs, where the updater runs
 *    `migrate --force` and never `db:seed`) must grant it to every role that
 *    currently holds `dashboard.view` and must be idempotent.
 *
 * Everything runs on sqlite :memory: via RefreshDatabase (`phpunit.xml`), no
 * MySQL, matching the SeederIntegrityTest conventions.
 */
final class SearchPermissionBackfillTest extends TestCase
{
    use RefreshDatabase;

    private const BACKFILL_MIGRATION = 'migrations/2026_09_23_000001_backfill_search_permission.php';

    /**
     * Roles that hold `dashboard.view` in AdminLteRbacSeeder today — the exact
     * set that must keep reach to the search surface.
     *
     * @var list<string>
     */
    private const DASHBOARD_ROLES = ['admin', 'support', 'sales', 'marketing', 'staff', 'editor', 'viewer'];

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

        $this->assertFileExists($path, 'The search-permission backfill migration is missing.');

        /** @var Migration $migration */
        $migration = require $path;

        $migration->up();
    }

    private function searchPermissionId(): int
    {
        $id = Permission::where('name', 'search')->value('id');

        $this->assertNotNull($id, 'Permission [search] is not present in adminlte_permissions.');

        return (int) $id;
    }

    private function searchPivotCount(): int
    {
        return DB::table('adminlte_permission_role')
            ->where('permission_id', $this->searchPermissionId())
            ->count();
    }

    /**
     * @return Collection<int, Role>
     */
    private function rolesHoldingDashboardView()
    {
        return Role::whereHas('permissions', static fn ($query) => $query->where('name', 'dashboard.view'))->get();
    }

    // ─────────────────────────────────────────────────────────────────
    // Seeder (fresh-install path)
    // ─────────────────────────────────────────────────────────────────

    public function test_seeder_grants_search_to_support(): void
    {
        $this->seed(AdminLteRbacSeeder::class);

        $support = Role::where('name', 'support')->first();

        $this->assertNotNull($support, 'Role [support] was not seeded.');
        $this->assertTrue(
            $support->hasPermission('search'),
            'Role [support] must hold the search permission (it holds dashboard.view).'
        );
    }

    public function test_seeder_grants_search_to_staff(): void
    {
        $this->seed(AdminLteRbacSeeder::class);

        $staff = Role::where('name', 'staff')->first();

        $this->assertNotNull($staff, 'Role [staff] was not seeded.');
        $this->assertTrue(
            $staff->hasPermission('search'),
            'Role [staff] must hold the search permission (it holds dashboard.view).'
        );
    }

    public function test_every_role_holding_dashboard_view_holds_search_after_seeding(): void
    {
        $this->seed(AdminLteRbacSeeder::class);

        $roles = $this->rolesHoldingDashboardView();

        $this->assertNotEmpty($roles, 'No role holds dashboard.view after seeding — role matrix is broken.');

        foreach (self::DASHBOARD_ROLES as $name) {
            $this->assertTrue(
                $roles->contains('name', $name),
                "Regression anchor: role [{$name}] must hold dashboard.view."
            );
        }

        foreach ($roles as $role) {
            $this->assertTrue(
                $role->hasPermission('search'),
                "Role [{$role->name}] holds dashboard.view but was not granted search."
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Backfill migration (upgrade path)
    // ─────────────────────────────────────────────────────────────────

    public function test_backfill_migration_grants_search_to_dashboard_view_roles_idempotently(): void
    {
        $this->seed(AdminLteRbacSeeder::class);

        // Simulate a pre-backfill install: the role matrix exists but the
        // search permission and every one of its pivot rows are absent.
        DB::table('adminlte_permission_role')
            ->where('permission_id', $this->searchPermissionId())
            ->delete();
        DB::table('adminlte_permissions')->where('name', 'search')->delete();

        $this->assertSame(0, Permission::where('name', 'search')->count(), 'Precondition: search permission removed.');
        $this->assertSame(0, DB::table('adminlte_permission_role')
            ->whereIn('permission_id', Permission::where('name', 'search')->pluck('id'))
            ->count(), 'Precondition: no search pivot rows.');

        $this->runBackfillMigration();

        $this->assertSame(1, Permission::where('name', 'search')->count(), 'The migration must create exactly one search permission row.');
        $this->assertSame(
            'Use Global Search',
            Permission::where('name', 'search')->value('label'),
            'The backfilled permission label must match the seeder inventory.'
        );

        $roles = $this->rolesHoldingDashboardView();
        $this->assertNotEmpty($roles, 'No role holds dashboard.view after seeding — role matrix is broken.');

        foreach ($roles as $role) {
            $this->assertTrue(
                $role->hasPermission('search'),
                "Backfill missed role [{$role->name}], which holds dashboard.view."
            );
        }

        // Idempotency: a second run must change NOTHING — no duplicate
        // permission rows, no duplicate pivot rows.
        $permissionCount = Permission::where('name', 'search')->count();
        $pivotCount = $this->searchPivotCount();
        $totalPivotCount = DB::table('adminlte_permission_role')->count();

        $this->runBackfillMigration();

        $this->assertSame(1, Permission::where('name', 'search')->count(), 'Re-running the migration duplicated the search permission row.');
        $this->assertSame($pivotCount, $this->searchPivotCount(), 'Re-running the migration changed the search pivot row count.');
        $this->assertSame($totalPivotCount, DB::table('adminlte_permission_role')->count(), 'Re-running the migration changed the total pivot row count.');
        $this->assertSame($permissionCount, Permission::where('name', 'search')->count());
    }

    public function test_backfill_migration_does_not_grant_search_to_roles_without_dashboard_view(): void
    {
        $this->seed(AdminLteRbacSeeder::class);

        // A role that deliberately holds no dashboard.view (mirrors `client`).
        $client = Role::firstOrCreate(['name' => 'client'], ['label' => 'Client']);

        DB::table('adminlte_permission_role')
            ->where('permission_id', $this->searchPermissionId())
            ->delete();
        DB::table('adminlte_permissions')->where('name', 'search')->delete();

        $this->runBackfillMigration();

        $this->assertFalse(
            $client->hasPermission('search'),
            'Role [client] lacks dashboard.view and must NOT receive the search permission.'
        );
        $this->assertFalse(
            $client->fresh()->hasPermission('search'),
            'Role [client] lacks dashboard.view and must NOT receive the search permission after refresh.'
        );
    }
}
