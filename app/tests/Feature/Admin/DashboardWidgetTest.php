<?php

namespace Tests\Feature\Admin;

use App\Models\DashboardWidget;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin dashboard widget grid — first-visit defaults, snapshot persistence
 * (order + enabled flags) and the permission gate on the dashboard routes.
 */
class DashboardWidgetTest extends TestCase
{
    use RefreshDatabase;

    private const WIDGET_KEYS = [
        'kpi_metrics',
        'revenue_trend',
        'recent_orders',
        'pending_invoices',
        'open_tickets',
        'server_status',
    ];

    /**
     * Create an admin user, optionally granted dashboard.view.
     */
    private function makeAdminUser(bool $withPermission = true): User
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);

        if ($withPermission) {
            $permission = Permission::firstOrCreate(
                ['name' => 'dashboard.view'],
                ['label' => 'View Dashboard'],
            );
            $adminRole->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->assignRole('admin');

        return $user;
    }

    public function test_guest_is_redirected_away_from_dashboard(): void
    {
        $this->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_dashboard_requires_dashboard_view_permission(): void
    {
        $this->actingAs($this->makeAdminUser(withPermission: false))
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }

    public function test_dashboard_renders_all_default_widgets_on_first_visit(): void
    {
        $this->actingAs($this->makeAdminUser())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('data-widget-key="kpi_metrics"', false)
            ->assertSee('data-widget-key="revenue_trend"', false)
            ->assertSee('data-widget-key="recent_orders"', false)
            ->assertSee('data-widget-key="pending_invoices"', false)
            ->assertSee('data-widget-key="open_tickets"', false)
            ->assertSee('data-widget-key="server_status"', false);
    }

    public function test_widget_bodies_bind_their_data(): void
    {
        // Regression guard: widget provider payloads must be keyed by the
        // partial's variable name, or @include($view, $data) drops them and
        // every widget renders its empty state regardless of real data.
        $this->actingAs($this->makeAdminUser())
            ->get(route('admin.dashboard'))
            ->assertOk()
            // KPI widget: the metric-cards body renders each KPI label.
            ->assertSee('Customers')
            ->assertSee('Open Tickets')
            // Revenue widget: the ApexCharts div is only emitted when the
            // chartConfig payload actually reached the partial.
            ->assertSee('data-apexchart', false)
            ->assertSee('Monthly revenue trend.');
    }

    public function test_update_persists_widget_order_and_disables_unlisted(): void
    {
        $user = $this->makeAdminUser();
        $this->actingAs($user);

        // Establish a full grid first (all six enabled, catalogue order).
        foreach (self::WIDGET_KEYS as $index => $key) {
            DashboardWidget::create([
                'user_id' => $user->id,
                'widget_key' => $key,
                'sort_order' => $index,
                'enabled' => true,
            ]);
        }

        $this->postJson(route('admin.dashboard.widgets.update'), [
            'widgets' => [
                ['key' => 'recent_orders', 'order' => 0, 'enabled' => true],
                ['key' => 'open_tickets', 'order' => 1, 'enabled' => true],
            ],
        ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        // The submitted widgets are upserted with the snapshot's sort order.
        $this->assertDatabaseHas('dashboard_widgets', [
            'user_id' => $user->id,
            'widget_key' => 'recent_orders',
            'sort_order' => 0,
            'enabled' => 1,
        ]);
        $this->assertDatabaseHas('dashboard_widgets', [
            'user_id' => $user->id,
            'widget_key' => 'open_tickets',
            'sort_order' => 1,
            'enabled' => 1,
        ]);

        // Unlisted previously-enabled widgets are disabled, not deleted.
        foreach (['kpi_metrics', 'revenue_trend', 'pending_invoices', 'server_status'] as $key) {
            $this->assertDatabaseHas('dashboard_widgets', [
                'user_id' => $user->id,
                'widget_key' => $key,
                'enabled' => 0,
            ]);
        }
    }

    public function test_update_ignores_unknown_widget_key(): void
    {
        $user = $this->makeAdminUser();
        $this->actingAs($user);

        $this->postJson(route('admin.dashboard.widgets.update'), [
            'widgets' => [
                ['key' => 'not_a_real_widget', 'enabled' => true],
            ],
        ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseMissing('dashboard_widgets', [
            'user_id' => $user->id,
            'widget_key' => 'not_a_real_widget',
        ]);
    }

    public function test_update_rejects_invalid_payloads(): void
    {
        $this->actingAs($this->makeAdminUser());

        // Missing widgets entirely.
        $this->postJson(route('admin.dashboard.widgets.update'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('widgets');

        // widgets is not an array.
        $this->postJson(route('admin.dashboard.widgets.update'), ['widgets' => 'nope'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('widgets');

        // widget key is not a string.
        $this->postJson(route('admin.dashboard.widgets.update'), [
            'widgets' => [['key' => 123, 'enabled' => true]],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('widgets.0.key');
    }

    public function test_dashboard_reflects_saved_order_and_hides_disabled(): void
    {
        $user = $this->makeAdminUser();
        $this->actingAs($user);

        // Save a snapshot: two enabled, the rest disabled.
        $this->postJson(route('admin.dashboard.widgets.update'), [
            'widgets' => [
                ['key' => 'recent_orders', 'enabled' => true],
                ['key' => 'open_tickets', 'enabled' => true],
                ['key' => 'kpi_metrics', 'enabled' => false],
                ['key' => 'revenue_trend', 'enabled' => false],
                ['key' => 'pending_invoices', 'enabled' => false],
                ['key' => 'server_status', 'enabled' => false],
            ],
        ])->assertOk();

        $response = $this->get(route('admin.dashboard'));
        $response->assertOk();

        $content = $response->getContent();

        // Enabled widgets render in the saved order.
        $posRecent = strpos($content, 'data-widget-key="recent_orders"');
        $posOpen = strpos($content, 'data-widget-key="open_tickets"');
        $this->assertNotFalse($posRecent, 'recent_orders widget not rendered');
        $this->assertNotFalse($posOpen, 'open_tickets widget not rendered');
        $this->assertLessThan($posOpen, $posRecent, 'recent_orders must render before open_tickets');

        // Disabled widgets are hidden from the grid…
        $response->assertDontSee('data-widget-key="kpi_metrics"', false)
            ->assertDontSee('data-widget-key="revenue_trend"', false)
            ->assertDontSee('data-widget-key="pending_invoices"', false)
            ->assertDontSee('data-widget-key="server_status"', false)
            // …but remain available to re-add.
            ->assertSee('kpi_metrics')
            ->assertSee('revenue_trend')
            ->assertSee('pending_invoices')
            ->assertSee('server_status');
    }
}