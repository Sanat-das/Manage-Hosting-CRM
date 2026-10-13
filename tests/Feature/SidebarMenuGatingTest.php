<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sidebar `can` gates on SUBMENU children.
 *
 * The menu filter pipeline only ever hands a filter a whole top-level item,
 * so the package's GateFilter never reaches a child's `can` key and a parent
 * that passes its own gate used to render every child unconditionally. These
 * tests render the real dashboard for a real panel user and assert on the
 * sidebar HTML that comes back — see App\Support\AdminLte\CanFilter.
 */
class SidebarMenuGatingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build a panel user granted $role through the adminlte_role_user pivot.
     *
     * Tests\TestCase::seedPanelRbac() has already seeded the role and its
     * permission set, so the gates are exercised for real rather than waved
     * through. `users.role` is an enum that predates the RBAC roles and has
     * no 'viewer' value, so a viewer account uses the generic staff column
     * value and takes its permissions from the pivot alone.
     */
    private function actingAsPanelUser(string $role): void
    {
        $column = $role === 'viewer' ? 'staff' : $role;

        $user = User::factory()->create(['role' => $column]);

        $user->roles()->sync([Role::where('name', $role)->firstOrFail()->id]);

        $this->actingAs($user);
    }

    public function test_support_user_keeps_the_tickets_group_but_loses_the_settings_gated_departments_child(): void
    {
        $this->actingAsPanelUser('support');

        $response = $this->get('/admin/dashboard');

        $response->assertOk();
        $response->assertSee('admin/tickets');
        $response->assertDontSee('admin/ticket-departments');
    }

    public function test_viewer_user_loses_the_whole_logs_group_when_every_child_is_denied(): void
    {
        $this->actingAsPanelUser('viewer');

        $response = $this->get('/admin/dashboard');

        $response->assertOk();
        $response->assertDontSee('admin/audit-log');
        $response->assertDontSee('admin/consent-log');
    }

    public function test_admin_user_still_sees_the_audit_trail_child(): void
    {
        $this->actingAsPanelUser('admin');

        $response = $this->get('/admin/dashboard');

        $response->assertOk();
        $response->assertSee('admin/audit-log');
    }

    /**
     * Gating every menu item must not turn into a permission-lookup storm.
     *
     * Each `can` key costs an ability check and now that children are gated
     * too, one render walks ~100 of them against a single User instance.
     * HasRoles resolves that user's role and permission names once, so the
     * whole build is a handful of queries; before it, the same render ran
     * more than 700.
     */
    public function test_sidebar_gate_runs_a_query_bounded_number_of_checks(): void
    {
        $this->actingAsPanelUser('viewer');

        DB::flushQueryLog();
        DB::enableQueryLog();

        app('adminlte')->menu('sidebar');

        // 4 observed after the memoization in HasRoles -- a generous ceiling,
        // but one a per-item lookup storm (700+ queries) blows straight
        // through.
        $this->assertLessThan(20, count(DB::getQueryLog()));
    }
}
