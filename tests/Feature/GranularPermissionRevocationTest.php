<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AdminLteRbacSeeder;
use Database\Seeders\InitialDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Granular permission revocation + menu/route gate regression.
 *
 * Covers:
 * - granular infra permissions exist and admin holds them
 * - notifications.view/manage and manage-users exist
 * - seeder uses sync() so removed perms are revoked (not syncWithoutDetaching)
 * - config/adminlte menu can keys are granular and exist in DB
 * - string-role fallback (HasRoles) grants equivalent permissions
 */
final class GranularPermissionRevocationTest extends TestCase
{
    use RefreshDatabase;

    private const GRANULAR = [
        'datacenters.view', 'datacenters.manage',
        'racks.view', 'racks.manage',
        'ip-subnets.view', 'ip-subnets.manage',
        'ip-addresses.view', 'ip-addresses.manage',
        'vlans.view', 'vlans.manage',
        'dns-zones.view', 'dns-zones.manage',
        'dns-records.view', 'dns-records.manage',
        'licenses.view', 'licenses.manage',
        'catalog-products.view', 'catalog-products.manage',
        'subscriptions.view', 'subscriptions.manage',
        'usage-records.view', 'usage-records.manage',
        'resource-types.view', 'resource-types.manage',
        'resource-pools.view', 'resource-pools.manage',
        'asset-relationships.view', 'asset-relationships.manage',
        'inventory.view', 'inventory.manage',
        'tax-rates.view', 'tax-rates.manage',
        'product-bundles.view', 'product-bundles.manage',
        'product-upgrades.view', 'product-upgrades.manage',
        'service-instances.view', 'service-instances.manage',
        'provisioning-events.view', 'provisioning-events.manage',
    ];

    private function seedAdminLte(): void
    {
        $this->seed(AdminLteRbacSeeder::class);
    }

    public function test_granular_permissions_exist_and_admin_has_them(): void
    {
        $this->seedAdminLte();

        foreach (self::GRANULAR as $name) {
            $this->assertDatabaseHas('adminlte_permissions', ['name' => $name]);
        }

        $admin = Role::where('name', 'admin')->firstOrFail();
        foreach (self::GRANULAR as $name) {
            $this->assertTrue($admin->hasPermission($name), "admin should have {$name}");
        }

        // Admin must hold all permissions (superuser)
        $this->assertSame(Permission::count(), $admin->permissions()->count(), 'admin must hold every permission');
    }

    public function test_notifications_and_manage_users_permissions_exist(): void
    {
        $this->seedAdminLte();

        foreach (['notifications.view', 'notifications.manage', 'manage-users'] as $name) {
            $this->assertDatabaseHas('adminlte_permissions', ['name' => $name]);
        }

        $admin = Role::where('name', 'admin')->firstOrFail();
        $this->assertTrue($admin->hasPermission('notifications.view'));
        $this->assertTrue($admin->hasPermission('notifications.manage'));
        $this->assertTrue($admin->hasPermission('manage-users'));
    }

    public function test_seeder_sync_revokes_removed_granular_permission(): void
    {
        $this->seedAdminLte();

        $support = Role::where('name', 'support')->firstOrFail();
        $supportBefore = $support->permissions()->count();

        // Support does NOT include datacenters.manage by default — confirm
        $this->assertFalse($support->hasPermission('datacenters.manage'), 'support must not have datacenters.manage initially');

        // Manually grant it (simulates drift / over-grant)
        $perm = Permission::where('name', 'datacenters.manage')->firstOrFail();
        $support->permissions()->attach($perm);
        $support->refresh();
        $this->assertTrue($support->hasPermission('datacenters.manage'), 'manual attach should grant datacenters.manage');
        $this->assertSame($supportBefore + 1, $support->permissions()->count());

        // Re-run seeder — must use sync(), so the extra granular perm is revoked
        $this->seed(AdminLteRbacSeeder::class);

        $support->refresh();
        $this->assertFalse($support->hasPermission('datacenters.manage'), 'sync() must revoke datacenters.manage from support on re-seed');
        $this->assertSame($supportBefore, $support->permissions()->count(), 'support pivot must revert to original count after sync()');
    }

    public function test_seeder_idempotent_with_granular(): void
    {
        $this->seedAdminLte();
        $countBefore = Permission::count();
        $admin = Role::where('name', 'admin')->firstOrFail();
        $adminPivotBefore = $admin->permissions()->count();

        $this->seed(AdminLteRbacSeeder::class);

        $this->assertSame($countBefore, Permission::count(), 'permission count must not grow on re-seed');
        $admin->refresh();
        $this->assertSame($adminPivotBefore, $admin->permissions()->count(), 'admin pivot must not duplicate on re-seed');
        $this->assertSame(1, Permission::where('name', 'datacenters.view')->count());
        $this->assertSame(1, Permission::where('name', 'notifications.view')->count());
    }

    public function test_initial_data_seeder_also_granular_and_sync(): void
    {
        $this->seed(InitialDataSeeder::class);

        foreach (['datacenters.view', 'ip-subnets.manage', 'dns-zones.view', 'inventory.manage', 'tax-rates.view', 'product-bundles.manage', 'service-instances.view', 'notifications.view', 'manage-users'] as $name) {
            $this->assertDatabaseHas('adminlte_permissions', ['name' => $name]);
        }

        $admin = Role::where('name', 'admin')->firstOrFail();
        $this->assertTrue($admin->hasPermission('datacenters.view'));
        $this->assertTrue($admin->hasPermission('notifications.manage'));

        // Verify InitialDataSeeder also revokes via sync()
        $sales = Role::where('name', 'sales')->firstOrFail();
        $salesBefore = $sales->permissions()->count();
        $perm = Permission::where('name', 'vlans.manage')->firstOrFail();
        $sales->permissions()->attach($perm);
        $this->assertTrue($sales->fresh()->hasPermission('vlans.manage'));

        $this->seed(InitialDataSeeder::class);
        $sales->refresh();
        $this->assertFalse($sales->hasPermission('vlans.manage'), 'InitialDataSeeder sync() must revoke vlans.manage from sales');
        $this->assertSame($salesBefore, $sales->permissions()->count());
    }

    public function test_config_adminlte_menu_granular_can_keys_exist(): void
    {
        $this->seedAdminLte();

        $config = include base_path('config/adminlte.php');
        $menu = $config['menu'] ?? [];
        $canKeys = [];
        $this->collectCanKeys($menu, $canKeys);
        // Filter gate-only
        $canKeys = array_values(array_filter($canKeys, fn ($n) => $n !== 'admin.panel'));
        $canKeys = array_values(array_unique($canKeys));

        $expectedGranularMenu = [
            'notifications.view',
            'datacenters.view',
            'racks.view',
            'ip-subnets.view',
            'ip-addresses.view',
            'vlans.view',
            'dns-zones.view',
            'inventory.view',
            'licenses.view',
            'resource-pools.view',
            'resource-types.view',
            'tax-rates.view',
        ];

        foreach ($expectedGranularMenu as $name) {
            $this->assertContains($name, $canKeys, "config/adminlte menu must contain can={$name}");
            $this->assertDatabaseHas('adminlte_permissions', ['name' => $name]);
        }
    }

    public function test_route_granular_permissions_all_exist(): void
    {
        $this->seedAdminLte();

        $pattern = '/permission:([a-z0-9._-]+)/i';
        $files = glob(base_path('routes/admin/*.php')) ?: [];
        $names = [];
        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);
            if (preg_match_all($pattern, $contents, $m)) {
                foreach ($m[1] as $n) {
                    $names[] = $n;
                }
            }
        }
        $names = array_values(array_unique($names));
        foreach ($names as $name) {
            $this->assertDatabaseHas('adminlte_permissions', ['name' => $name]);
        }

        // Explicit granular route anchors
        foreach (['datacenters.view', 'ip-addresses.manage', 'dns-zones.view', 'dns-records.manage', 'inventory.view', 'tax-rates.manage', 'product-bundles.view', 'service-instances.view'] as $anchor) {
            $this->assertContains($anchor, $names, "routes must gate on {$anchor}");
        }
    }

    public function test_string_role_fallback_grants_granular(): void
    {
        $this->seedAdminLte();

        // Use a valid enum role 'support' with string fallback — ensure no pivot, then check fallback
        $user = User::factory()->create(['role' => 'support']);
        // Ensure no pivot — support string role should grant support's permissions via HasRoles fallback
        $user->roles()->sync([]);
        $this->assertTrue($user->hasPermission('dashboard.view'), 'support string role should grant dashboard.view');
        $this->assertTrue($user->hasPermission('tickets.view'));
        $this->assertFalse($user->hasPermission('datacenters.view'), 'support should not have datacenters.view');
        $this->assertFalse($user->hasPermission('notifications.view'), 'support should not have notifications.view');

        // Also verify that a user with pivot editor role (bypassing enum check via raw insert) gets fallback
        // The users.role enum restricts to admin/support/sales/marketing/client/staff, so editor/viewer
        // must be tested via pivot, not string. Create a user with client string but editor pivot.
        $clientUser = User::factory()->create(['role' => 'client']);
        $editorRole = Role::where('name', 'editor')->firstOrFail();
        $clientUser->roles()->sync([$editorRole->id]);
        $this->assertTrue($clientUser->hasPermission('dashboard.view'));
        $this->assertTrue($clientUser->hasPermission('customers.view'));
        $this->assertFalse($clientUser->hasPermission('datacenters.view'));
    }

    private function collectCanKeys(array $items, array &$out): void
    {
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (isset($item['can']) && is_string($item['can']) && $item['can'] !== '') {
                $out[] = $item['can'];
            }
            if (isset($item['submenu']) && is_array($item['submenu'])) {
                $this->collectCanKeys($item['submenu'], $out);
            }
        }
    }
}
