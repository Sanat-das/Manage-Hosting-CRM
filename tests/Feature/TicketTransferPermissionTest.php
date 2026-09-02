<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AdminLteRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T4 — tickets.transfer permission + role matrix.
 *
 * Acceptance: (a) admin has tickets.transfer, (b) support has it,
 * (c) sales does not, (d) seeder re-run does not duplicate.
 */
class TicketTransferPermissionTest extends TestCase
{
    use RefreshDatabase;

    private function seedRbac(): void
    {
        $this->seed(AdminLteRbacSeeder::class);
    }

    public function test_admin_has_transfer(): void
    {
        $this->seedRbac();

        $this->assertDatabaseHas('adminlte_permissions', ['name' => 'tickets.transfer']);

        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $this->assertTrue($adminRole->hasPermission('tickets.transfer'), 'admin role should have tickets.transfer');

        $user = User::factory()->create(['role' => 'admin']);
        $user->roles()->syncWithoutDetaching($adminRole);
        $this->assertTrue($user->hasPermission('tickets.transfer'), 'admin user should have tickets.transfer via role');
    }

    public function test_support_has_transfer(): void
    {
        $this->seedRbac();

        $supportRole = Role::where('name', 'support')->firstOrFail();
        $this->assertTrue($supportRole->hasPermission('tickets.transfer'), 'support role should have tickets.transfer');

        $user = User::factory()->create(['role' => 'support']);
        $user->roles()->syncWithoutDetaching($supportRole);
        $this->assertTrue($user->hasPermission('tickets.transfer'), 'support user should have tickets.transfer via role');
    }

    public function test_sales_lacks_transfer(): void
    {
        $this->seedRbac();

        $salesRole = Role::where('name', 'sales')->firstOrFail();
        $this->assertFalse($salesRole->hasPermission('tickets.transfer'), 'sales role must NOT have tickets.transfer');

        $user = User::factory()->create(['role' => 'sales']);
        $user->roles()->syncWithoutDetaching($salesRole);
        $this->assertFalse($user->hasPermission('tickets.transfer'), 'sales user must NOT have tickets.transfer');

        // Also ensure marketing/editor/viewer do not have it (guard against over-grant).
        foreach (['marketing', 'editor', 'viewer'] as $roleName) {
            $role = Role::where('name', $roleName)->first();
            if ($role !== null) {
                $this->assertFalse($role->hasPermission('tickets.transfer'), "{$roleName} role must NOT have tickets.transfer");
            }
        }
    }

    public function test_seeder_rerun_does_not_duplicate(): void
    {
        $this->seedRbac();

        $countBefore = Permission::count();
        $permIdBefore = Permission::where('name', 'tickets.transfer')->value('id');
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $supportRole = Role::where('name', 'support')->firstOrFail();
        $adminPivotBefore = $adminRole->permissions()->count();
        $supportPivotBefore = $supportRole->permissions()->count();

        // Re-run seeder — must be idempotent (firstOrCreate + syncWithoutDetaching).
        $this->seed(AdminLteRbacSeeder::class);

        $countAfter = Permission::count();
        $permIdAfter = Permission::where('name', 'tickets.transfer')->value('id');

        $this->assertSame($countBefore, $countAfter, 'permission count must not grow on re-seed');
        $this->assertSame($permIdBefore, $permIdAfter, 'tickets.transfer id must be stable on re-seed');
        $this->assertSame(1, Permission::where('name', 'tickets.transfer')->count(), 'tickets.transfer must not duplicate');

        $adminRole->refresh();
        $supportRole->refresh();
        $this->assertSame($adminPivotBefore, $adminRole->permissions()->count(), 'admin pivot must not duplicate on re-seed');
        $this->assertSame($supportPivotBefore, $supportRole->permissions()->count(), 'support pivot must not duplicate on re-seed');
    }
}
