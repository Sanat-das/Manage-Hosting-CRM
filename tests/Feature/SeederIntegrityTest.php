<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\Demo\DummyDataConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Guards against seeder drift — the exact regression where routes and the
 * sidebar required `modules.view` / `modules.manage` but neither seeder
 * declared them, leaving the admin role without those rows in the pivot.
 *
 * Each test runs against a fresh sqlite:memory DB provided by
 * RefreshDatabase (`phpunit.xml` sets DB_CONNECTION=sqlite,
 * DB_DATABASE=:memory:) and seeds the full DatabaseSeeder chain via
 * $this->seed(). No MySQL, no external dependencies — CI-ready.
 *
 * Permission gates are never hardcoded: route permissions are scraped from
 * `routes/admin/*.php` via `/permission:([a-z0-9._-]+)/i` and sidebar
 * permissions from `config/adminlte.php` `can` keys, so a future missing
 * permission cannot slip through a stale allow-list.
 */
final class SeederIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Permissions that exist as Gates rather than adminlte_permissions rows.
     *
     * `admin.panel` is defined via Gate::define in AppServiceProvider and
     * guards the sidebar headers; it deliberately does not live in the
     * permission table and must be excluded from inventory assertions.
     *
     * @var list<string>
     */
    private const GATE_ONLY_PERMISSIONS = ['admin.panel'];

    // ─────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * Collect every permission name referenced by admin route middleware.
     *
     * Scans each file in `routes/admin/*.php` for `permission:xxx` and
     * returns the de-duplicated, sorted set.
     *
     * @return list<string>
     */
    private function collectRoutePermissions(): array
    {
        $pattern = '/permission:([a-z0-9._-]+)/i';
        $files = glob(base_path('routes/admin/*.php')) ?: [];
        $names = [];

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);

            if (preg_match_all($pattern, $contents, $matches) === false) {
                continue;
            }

            foreach ($matches[1] as $name) {
                $names[] = (string) $name;
            }
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * Recursively collect every `can` value from the AdminLTE menu config.
     *
     * Loads `config/adminlte.php` via include (mirrors the real menu the
     * application renders) and walks the `menu` tree for `can` keys.
     * Gate-only entries (e.g. `admin.panel`) are filtered out.
     *
     * @return list<string>
     */
    private function collectMenuPermissions(): array
    {
        $config = include base_path('config/adminlte.php');

        if (! is_array($config) || ! isset($config['menu']) || ! is_array($config['menu'])) {
            return [];
        }

        $names = [];
        $this->collectCanKeysRecursive($config['menu'], $names);

        // Filter gate-only pseudo-permissions.
        $names = array_values(array_filter(
            $names,
            static fn (string $name): bool => ! in_array($name, self::GATE_ONLY_PERMISSIONS, true)
        ));

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * Walk a menu tree and push every string `can` value into $out.
     *
     * @param  array<int|string, mixed>  $items
     * @param  list<string>              $out
     */
    private function collectCanKeysRecursive(array $items, array &$out): void
    {
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (isset($item['can']) && is_string($item['can']) && $item['can'] !== '') {
                $out[] = $item['can'];
            }

            if (isset($item['submenu']) && is_array($item['submenu'])) {
                $this->collectCanKeysRecursive($item['submenu'], $out);
            }
        }
    }

    /**
     * Union of every permission the application actually gates on.
     *
     * Combines route middleware permissions and sidebar `can` permissions,
     * de-duplicated and sorted.
     *
     * @return list<string>
     */
    private function collectRequiredPermissions(): array
    {
        $route = $this->collectRoutePermissions();
        $menu = $this->collectMenuPermissions();

        $merged = array_values(array_unique(array_merge($route, $menu)));
        sort($merged);

        return $merged;
    }

    /**
     * Parse the permission inventory declared in a seeder file.
     *
     * Extracts every `'permission.name' =>` key from the source — the
     * canonical declaration in AdminLteRbacSeeder (`$permissions`) and
     * InitialDataSeeder (`$permLabels`). This is a source-level read so
     * drift is caught even before DB seeding is involved.
     *
     * @return list<string>
     */
    private function parseSeederPermissions(string $seederFile): array
    {
        $contents = (string) file_get_contents($seederFile);

        // Match quoted keys before `=>` inside an array context.
        // Narrow to the first `$permissions = [` / `$permLabels = [` block to
        // avoid unrelated array keys elsewhere in the file.
        $blockPattern = '/\$(?:permissions|permLabels)\s*=\s*\[(.*?)\];/s';

        if (preg_match($blockPattern, $contents, $blockMatch) === 1) {
            $block = $blockMatch[1];
        } else {
            $block = $contents;
        }

        preg_match_all('/[\'"]([a-z0-9._-]+)[\'"]\s*=>/i', $block, $matches);

        $names = array_values(array_unique($matches[1] ?? []));
        sort($names);

        return $names;
    }

    /**
     * Snapshot row counts for every business table declared by DummyDataConfig.
     *
     * Inline equivalent of DummyDataSeederTest::snapshotRowCounts() — kept
     * local so this test remains self-contained and focused on seed integrity
     * rather than duplicating the broader foreign-key suite.
     *
     * @return array<string, int>
     */
    private function snapshotRowCounts(): array
    {
        $counts = [];

        foreach (DummyDataConfig::tables() as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    // ─────────────────────────────────────────────────────────────────
    // Tests (exact names required by spec)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Smoke: a fresh migrate + full seed must succeed and create core tables.
     */
    public function test_fresh_migrate_and_seed_succeeds(): void
    {
        $this->seed();

        // Core tables that must exist after migrate:fresh (RefreshDatabase)
        // and hold seeded rows.
        $requiredTables = [
            'users',
            'adminlte_roles',
            'adminlte_permissions',
            'adminlte_permission_role',
            'adminlte_role_user',
            'products',
            'orders',
            'invoices',
        ];

        foreach ($requiredTables as $table) {
            $this->assertTrue(
                Schema::hasTable($table),
                "Required table [{$table}] does not exist after migrate:fresh."
            );
        }

        // Sanity: seeding must have produced at least one permission and one role.
        $this->assertGreaterThan(0, DB::table('adminlte_permissions')->count(), 'adminlte_permissions is empty after seeding.');
        $this->assertGreaterThan(0, DB::table('adminlte_roles')->count(), 'adminlte_roles is empty after seeding.');
    }

    /**
     * Every permission the routes and sidebar gate on must exist in the DB
     * and be granted to the `admin` role.
     *
     * This is the regression gate for missing `modules.view` /
     * `modules.manage`: those permissions are required by
     * `routes/admin/modules.php` and `config/adminlte.php` but were absent
     * from the seeder inventory, leaving the admin pivot empty for them.
     */
    public function test_all_route_and_menu_permissions_exist_and_are_granted_to_admin(): void
    {
        $this->seed();

        $collected = $this->collectRequiredPermissions();

        $this->assertNotEmpty($collected, 'No permissions were collected from routes or menu — scan logic is broken.');

        // Regression anchors — the exact permissions that were missing.
        $this->assertContains('modules.view', $collected, 'Collected permissions must include modules.view (routes/admin/modules.php).');
        $this->assertContains('modules.manage', $collected, 'Collected permissions must include modules.manage (routes/admin/modules.php).');

        // Every collected permission must exist in adminlte_permissions.
        $existingNames = Permission::whereIn('name', $collected)->pluck('name')->all();
        $missing = array_values(array_diff($collected, $existingNames));

        $this->assertSame(
            [],
            $missing,
            'Missing permissions in adminlte_permissions (seed inventory is stale): [' . implode(', ', $missing) . '] — '
                . 'collected from routes/admin/*.php and config/adminlte.php but not present in adminlte_permissions table.'
        );

        $this->assertSame(
            count($collected),
            Permission::whereIn('name', $collected)->count(),
            'Permission count mismatch: not every collected permission exists in adminlte_permissions.'
        );

        // Every collected permission must be attached to the admin role.
        $adminRole = Role::where('name', 'admin')->first();
        $this->assertNotNull($adminRole, 'Role [admin] does not exist after seeding.');

        /** @var \Illuminate\Support\Collection<int, string> $adminPermissionNames */
        $adminPermissionNames = $adminRole->permissions()->pluck('name');
        $adminSet = $adminPermissionNames->all();

        $ungranted = array_values(array_diff($collected, $adminSet));

        $this->assertSame(
            [],
            $ungranted,
            'Permissions not granted to admin role (pivot adminlte_permission_role is incomplete): ['
                . implode(', ', $ungranted) . '] — every route/menu permission must be attached to role admin.'
        );
    }

    /**
     * The full seed chain must be idempotent — a second run on the same DB
     * must not change any business table row count.
     */
    public function test_seed_is_idempotent(): void
    {
        $this->seed();
        $first = $this->snapshotRowCounts();

        // Second run on the SAME database (no RefreshDatabase in between).
        $this->seed();
        $second = $this->snapshotRowCounts();

        $this->assertSame($first, $second, 'Re-running the seed chain changed row counts — seed is not idempotent.');
    }

    /**
     * Every permission present in the DB must be declared in the canonical
     * seeder inventory (prevents drift the other way) and the admin role must
     * hold all permissions (admin is the superuser).
     */
    public function test_admin_permission_inventory_is_exhaustive(): void
    {
        $this->seed();

        $adminLteSeederFile = database_path('seeders/AdminLteRbacSeeder.php');
        $inventory = $this->parseSeederPermissions($adminLteSeederFile);

        $this->assertNotEmpty($inventory, "Could not parse permission inventory from [{$adminLteSeederFile}].");

        // Regression anchor: the fix added modules.* so inventory must contain them.
        $this->assertContains('modules.view', $inventory, 'AdminLteRbacSeeder inventory must declare modules.view.');
        $this->assertContains('modules.manage', $inventory, 'AdminLteRbacSeeder inventory must declare modules.manage.');

        // DB count must reflect the expanded inventory (>= 43 with modules.*).
        // DummyDataConfig::ROWS['adminlte_permissions'] is still 41 at the time
        // of writing — the seeded count is the authoritative expansion.
        $dbCount = DB::table('adminlte_permissions')->count();
        $this->assertGreaterThanOrEqual(
            43,
            $dbCount,
            "adminlte_permissions count [{$dbCount}] is below the expected minimum 43 (modules.view/manage must be seeded)."
        );
        $this->assertGreaterThanOrEqual(
            DummyDataConfig::ROWS['adminlte_permissions'],
            $dbCount,
            'adminlte_permissions count is below DummyDataConfig::ROWS[adminlte_permissions] — ROWS is ahead of the seeders or seeders regressed.'
        );

        // No permission in the DB may be undeclared in the canonical inventory.
        $dbNames = Permission::pluck('name')->all();
        $undeclared = array_values(array_diff($dbNames, $inventory));

        $this->assertSame(
            [],
            $undeclared,
            'Permissions present in adminlte_permissions but not declared in AdminLteRbacSeeder::$permissions: ['
                . implode(', ', $undeclared) . '] — inventory must be exhaustive.'
        );

        // No permission in the inventory may be missing from the DB.
        $notSeeded = array_values(array_diff($inventory, $dbNames));

        $this->assertSame(
            [],
            $notSeeded,
            'Permissions declared in AdminLteRbacSeeder::$permissions but not present in adminlte_permissions table after seeding: ['
                . implode(', ', $notSeeded) . ']'
        );

        // Admin role is superuser — must hold every permission that exists.
        $adminRole = Role::where('name', 'admin')->first();
        $this->assertNotNull($adminRole, 'Role [admin] does not exist after seeding.');

        $adminCount = $adminRole->permissions()->count();
        $permissionCount = Permission::count();

        $this->assertSame(
            $permissionCount,
            $adminCount,
            "Admin role holds [{$adminCount}] permissions but adminlte_permissions has [{$permissionCount}] — admin must be granted every permission."
        );
    }

    /**
     * Lightweight sanity on business-table minima after seeding.
     *
     * Uses DummyDataConfig::ROWS as the source of truth for at least the
     * most critical demo tables without duplicating the full matrix checked
     * by DummyDataSeederTest.
     */
    public function test_dummy_data_minima_still_met(): void
    {
        $this->seed();

        $keys = ['products', 'orders', 'invoices', 'customers', 'users'];

        $short = [];

        foreach ($keys as $table) {
            $minimum = DummyDataConfig::ROWS[$table] ?? 0;
            $actual = DB::table($table)->count();

            if ($actual < $minimum) {
                $short[] = "{$table}: {$actual} < {$minimum}";
            }
        }

        $this->assertSame(
            [],
            $short,
            "Tables below their DummyDataConfig::ROWS minimum:\n  " . implode("\n  ", $short)
        );
    }
}
