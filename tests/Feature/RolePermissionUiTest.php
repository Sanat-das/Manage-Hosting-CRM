<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AdminLteRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Roles screen must not imply control it does not have.
 *
 * `User::hasPermission()` returns true for the admin role before reading any
 * pivot row, so the permission grid is inert for that one role — unticking a
 * box changes nothing. The page says so rather than leaving an admin to
 * discover it by failing to restrict someone.
 */
final class RolePermissionUiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRoleManager(): User
    {
        $this->seed(AdminLteRbacSeeder::class);

        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * The admin role no longer bypasses anything, so the index must not say so.
     */
    public function test_roles_index_no_longer_claims_the_admin_role_bypasses_permissions(): void
    {
        $actor = $this->actingAsRoleManager();

        $this->actingAs($actor)
            ->get(route('adminlte.roles.index'))
            ->assertOk()
            ->assertDontSee('bypasses permission checks', false);
    }

    /**
     * The admin role is not editable at all, so there is no grid of checkboxes
     * that could imply otherwise.
     */
    public function test_admin_role_cannot_be_edited(): void
    {
        $actor = $this->actingAsRoleManager();
        $admin = Role::where('name', 'admin')->firstOrFail();

        $this->actingAs($actor)
            ->get(route('adminlte.roles.edit', $admin))
            ->assertForbidden();
    }

    public function test_other_roles_remain_editable(): void
    {
        $actor = $this->actingAsRoleManager();
        $support = Role::where('name', 'support')->firstOrFail();

        $this->actingAs($actor)
            ->get(route('adminlte.roles.edit', $support))
            ->assertOk()
            ->assertDontSee('bypasses permission checks', false);
    }

    /**
     * An admin's access comes from the admin role's rows, not from a bypass.
     *
     * hasPermission() used to return true for admins before reading anything,
     * which made this role's permissions unenforceable and hid the installer
     * granting it 97 of 103. Stripping the role must now actually take the
     * access away -- that is the whole point of removing the short-circuit.
     */
    public function test_stripping_the_admin_role_removes_admin_access(): void
    {
        $this->seed(AdminLteRbacSeeder::class);

        $user = User::factory()->create(['role' => 'admin']);

        $this->assertTrue($user->hasPermission('settings.manage'));
        $this->assertTrue($user->hasPermission('system.update'));

        Role::where('name', 'admin')->firstOrFail()->permissions()->sync([]);

        $this->assertFalse($user->fresh()->hasPermission('settings.manage'));
        $this->assertFalse($user->fresh()->hasPermission('system.update'));
    }

    /**
     * The admin role must hold every permission, since nothing grants them now.
     */
    public function test_admin_role_holds_every_permission(): void
    {
        $this->seed(AdminLteRbacSeeder::class);

        $user = User::factory()->create(['role' => 'admin']);

        $ungranted = \App\Models\Permission::pluck('name')
            ->reject(fn (string $name): bool => $user->hasPermission($name))
            ->values()
            ->all();

        $this->assertSame(
            [],
            $ungranted,
            'An administrator no longer passes [' . implode(', ', $ungranted) . '] -- with the '
                . 'isAdmin() short-circuit gone, every permission must be on the admin role.'
        );
    }

    /**
     * A staff account created through the Users form must land on a real role.
     */
    public function test_staff_role_grants_a_usable_baseline(): void
    {
        $this->seed(AdminLteRbacSeeder::class);

        $staff = Role::where('name', 'staff')->firstOrFail();

        $this->assertGreaterThan(0, $staff->permissions()->count());
        $this->assertTrue($staff->hasPermission('dashboard.view'));
        $this->assertFalse($staff->hasPermission('settings.manage'));
    }
}
