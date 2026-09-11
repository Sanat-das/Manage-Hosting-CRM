<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who gets the admin panel shell.
 *
 * `AdminMiddleware` is the door to /admin. It used to admit anyone holding a
 * panel role name, regardless of whether that role still granted anything, so
 * a role stripped of its permissions in the Roles screen left its users
 * looking at a working panel that 403'd on every page they clicked.
 */
final class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_staff_user_can_reach_the_panel(): void
    {
        $user = User::factory()->create(['role' => 'staff']);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertOk();
    }

    /**
     * Denied by AdminMiddleware itself, not merely by the route's own gate.
     *
     * Asserted against a route with no `permission:` middleware of its own, so
     * the only thing that can turn the user away is the door.
     */
    public function test_a_panel_user_whose_role_grants_nothing_is_denied_at_the_door(): void
    {
        Role::where('name', 'support')->firstOrFail()->permissions()->sync([]);

        $user = User::factory()->create(['role' => 'support']);

        $this->assertFalse($user->hasAnyPermission(), 'precondition: the user holds no permissions');

        $this->actingAs($user)
            ->get(route('admin.profile'))
            ->assertForbidden();
    }

    public function test_a_permissionless_panel_user_reaches_no_admin_page_at_all(): void
    {
        Role::where('name', 'support')->firstOrFail()->permissions()->sync([]);

        $user = User::factory()->create(['role' => 'support']);

        $reachable = [];

        // Never skip an unknown name. An earlier version of this test silently
        // passed over `admin.profile.edit` (no such route) and so never probed
        // the one page that had no permission gate of its own -- it reported
        // "reaches nothing" while /admin/profile answered 200.
        foreach (['admin.dashboard', 'admin.profile', 'admin.notifications.index', 'admin.search.index'] as $name) {
            $this->assertTrue(
                app('router')->has($name),
                "Route [{$name}] does not exist, so this test is not probing what it claims."
            );

            $status = $this->actingAs($user)->get(route($name))->getStatusCode();

            if ($status < 400) {
                $reachable[] = "{$name} => {$status}";
            }
        }

        $this->assertSame(
            [],
            $reachable,
            'A panel user holding no permissions reached: ' . implode(', ', $reachable)
        );
    }

    public function test_a_client_is_denied(): void
    {
        $user = User::factory()->create(['role' => 'client']);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }

    public function test_a_normal_support_user_still_reaches_the_panel(): void
    {
        $user = User::factory()->create(['role' => 'support']);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertOk();
    }
}
