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

    public function test_roles_index_marks_the_admin_role_as_bypassing_permissions(): void
    {
        $actor = $this->actingAsRoleManager();

        $this->actingAs($actor)
            ->get(route('adminlte.roles.index'))
            ->assertOk()
            ->assertSee('bypasses permission checks', false);
    }

    /**
     * The admin role is not editable at all, so there is no grid of checkboxes
     * that could imply otherwise — the index note is the only surface.
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
     * Pins the behaviour the warning describes: stripping every permission from
     * the admin role does not reduce an admin's access. If this ever fails the
     * bypass has been removed and the warning must come out with it.
     */
    public function test_stripping_the_admin_role_does_not_reduce_admin_access(): void
    {
        $this->seed(AdminLteRbacSeeder::class);

        Role::where('name', 'admin')->firstOrFail()->permissions()->sync([]);

        $user = User::factory()->create(['role' => 'admin']);

        $this->assertTrue($user->hasPermission('settings.manage'));
        $this->assertTrue($user->hasPermission('system.update'));
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
