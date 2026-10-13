<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Domain;
use App\Models\HostingAccount;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * The five enterprise dashboard widgets render real data on GET /admin/dashboard.
 *
 * Each widget is a Blade partial under admin/dashboard/partials fed by its
 * ViewModel (see DashboardController::index). These tests seed the tables each
 * widget reads and assert the user-visible figures, so a widget that silently
 * returns zeros -- or a mis-wired partial -- fails loudly.
 *
 * Authorization itself is covered by PanelAccessTest (a client is 403'd, a
 * permissionless panel user reaches no page); it is not repeated here.
 */
final class AdminDashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_renders_all_five_widget_cards(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Outstanding receivables')
            ->assertSee('Upcoming renewals (next 30 days)')
            ->assertSee('Support queue risk')
            ->assertSee('System health')
            ->assertSee('Capacity pressure');
    }

    public function test_outstanding_receivables_lists_the_overdue_invoice_and_hides_the_paid_one(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();

        Invoice::create([
            'customer_id' => $customer->id,
            'invoice_no' => 'INV-OVERDUE-1001',
            'status' => Invoice::STATUS_OVERDUE,
            'total' => 1500.00,
            'paid_amount' => 0.00,
            'due_date' => now()->subDays(20),
        ]);

        Invoice::create([
            'customer_id' => $customer->id,
            'invoice_no' => 'INV-PAID-1001',
            'status' => Invoice::STATUS_PAID,
            'total' => 900.00,
            'paid_amount' => 900.00,
            'due_date' => now()->subDays(10),
            'paid_at' => now()->subDays(5),
        ]);

        $page = $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('INV-OVERDUE-1001')
            ->assertSee('Total outstanding')
            ->assertDontSee('INV-PAID-1001');

        // "1 overdue" is ambiguous: the metrics box (dashboard.blade.php:82) and the
        // widget badge (outstanding-receivables.blade.php:24) both render it, so it
        // cannot prove the widget's own data. "Oldest N days overdue" is rendered
        // only by the widget, and it only renders when the overdue query ran.
        $page->assertSee('Oldest 20 days overdue');
        $this->assertMatchesRegularExpression(
            '/Total outstanding.*?'.preg_quote($this->rendersCurrency(1500.00), '/').'/s',
            $page->getContent()
        );
    }

    public function test_outstanding_receivables_clamps_an_overpaid_invoice_to_zero(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();

        Invoice::create([
            'customer_id' => $customer->id,
            'invoice_no' => 'INV-CLAMP-OVERDUE-1002',
            'status' => Invoice::STATUS_OVERDUE,
            'total' => 1500.00,
            'paid_amount' => 0.00,
            'due_date' => now()->subDays(10),
        ]);

        Invoice::create([
            'customer_id' => $customer->id,
            'invoice_no' => 'INV-CLAMP-OVERPAID-1003',
            'status' => Invoice::STATUS_OVERDUE,
            'total' => 500.00,
            'paid_amount' => 1200.00,
            'due_date' => now()->subDays(40),
        ]);

        Invoice::create([
            'customer_id' => $customer->id,
            'invoice_no' => 'INV-CLAMP-PAID-1004',
            'status' => Invoice::STATUS_PAID,
            'total' => 900.00,
            'paid_amount' => 900.00,
            'due_date' => now()->subDays(60),
            'paid_at' => now()->subDays(5),
        ]);

        $page = $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('INV-CLAMP-OVERDUE-1002')
            ->assertDontSee('INV-CLAMP-PAID-1004')
            ->assertSee('Total outstanding')
            ->assertSee('Oldest 40 days overdue');

        $content = $page->getContent();

        // The overpaid invoice must contribute 0, so the total is the unpaid
        // invoice's 1500.00 -- not 1700.00 and not 800.00 (1500 - 700). Both the
        // total anchor and the money figure come from the app's own rendering.
        $this->assertMatchesRegularExpression(
            '/Total outstanding.*?'.preg_quote($this->rendersCurrency(1500.00), '/').'/s',
            $content,
            'the outstanding total must clamp the overpaid invoice to zero'
        );

        // The overpaid invoice's own row shows the clamped amount, never the
        // negative balance an unclamped subtraction would render.
        $this->assertMatchesRegularExpression(
            '/'.preg_quote('INV-CLAMP-OVERPAID-1003', '/').'.*?'.preg_quote($this->rendersCurrency(0.00), '/').'/s',
            $content,
            'the overpaid invoice row must render a clamped zero, not a negative amount'
        );

        $this->assertSame(false, strpos($content, '₹-700.00'));
        $this->assertSame(false, strpos($content, '₹-1,200.00'));
    }

    public function test_upcoming_renewals_lists_the_hosting_account_and_domain_and_the_projected_value(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();

        HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => 1,
            'host_name' => 'host-renewal-001',
            'status' => 'active',
            'next_due_date' => now()->addDays(15),
        ]);

        Domain::create([
            'customer_id' => $customer->id,
            'name' => 'renew-demo.test',
            'status' => 'active',
            'recurring_amount' => 1234.50,
            'next_due_date' => now()->addDays(10),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertSee('host-renewal-001')
            ->assertSee('renew-demo.test')
            ->assertSee('₹1,234.50');
    }

    public function test_support_queue_risk_surfaces_the_unassigned_and_awaiting_reply_figures(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();

        Ticket::create([
            'customer_id' => $customer->id,
            'ticket_no' => 'TKT-UNASSIGNED-1',
            'subject' => 'Unassigned open ticket',
            'priority' => 'urgent',
            'status' => 'open',
            'assigned_to' => null,
        ]);

        Ticket::create([
            'customer_id' => $customer->id,
            'ticket_no' => 'TKT-AWAITING-1',
            'subject' => 'Customer replied, awaiting a staff reply',
            'priority' => 'urgent',
            'status' => 'customer_reply',
            'assigned_to' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertSee('1 urgent / high priority')
            ->assertSee('1 customers waiting on urgent / high priority')
            ->assertDontSee('Support queue is healthy');
    }

    public function test_the_widget_grid_renders_each_wrapper_with_its_documented_default_span(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // The wrapper tags are the layout contract the JS controller resizes:
        // grid-column is the value it overrides from localStorage, and the grid
        // container is the one it scopes by. Asserted against the rendered
        // markup, so a dropped widget or a changed default span fails here.
        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('<div class="dashboard-widget-grid" data-dashboard-widget-grid>', false)
            ->assertSee('<div class="dashboard-widget" data-widget="receivables" style="grid-column: span 4">', false)
            ->assertSee('<div class="dashboard-widget" data-widget="renewals" style="grid-column: span 4">', false)
            ->assertSee('<div class="dashboard-widget" data-widget="support" style="grid-column: span 4">', false)
            ->assertSee('<div class="dashboard-widget" data-widget="health" style="grid-column: span 6">', false)
            ->assertSee('<div class="dashboard-widget" data-widget="capacity" style="grid-column: span 6">', false);
    }

    public function test_the_pre_paint_script_declares_the_two_storage_keys_the_module_reads(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // The deferred dashboard-widgets module saves and restores under exactly
        // these two keys (resources/js/dashboard-widgets.js:26). The inline
        // pre-paint pass must name the same two, or a remembered span or hidden
        // widget is written under one key and read back from the other.
        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('adminlte.dashboardWidgetSpans.v1', false)
            ->assertSee('adminlte.dashboardWidgetHidden.v1', false);
    }

    public function test_the_widget_grid_and_the_equal_height_rows_carry_their_layout_classes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $html = $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertSame(
            1,
            substr_count($html, 'class="dashboard-widget-grid" data-dashboard-widget-grid'),
            'the grid container must carry both the dashboard-widget-grid class and the data hook the module selects'
        );

        $this->assertSame(
            3,
            substr_count($html, 'dashboard-equal'),
            'the charts, activity/quick-actions and orders/domains rows must each carry dashboard-equal so their cards stretch to one height'
        );
    }

    public function test_the_widget_toggles_render_for_a_panel_user_without_the_gated_permissions(): void
    {
        // PanelAccessTest's permission-stripping approach: the support role is
        // stripped to the one permission that reaches the dashboard.
        // AdminMiddleware refuses a role holding no permissions at all, so
        // exactly `dashboard.view` is granted and nothing else.
        Role::where('name', 'support')->firstOrFail()->permissions()->sync(
            [Permission::where('name', 'dashboard.view')->firstOrFail()->id]
        );

        $viewer = User::factory()->create(['role' => 'support']);

        $this->assertTrue($viewer->hasPermission('dashboard.view'), 'precondition: the viewer must reach the dashboard');

        foreach (['reports.view', 'tickets.view', 'cron.view', 'hosting.view'] as $absent) {
            $this->assertFalse($viewer->hasPermission($absent), "precondition: the viewer must not hold [{$absent}]");
        }

        $html = $this->actingAs($viewer)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertSame(
            5,
            substr_count($html, 'data-widget-toggle'),
            'the hide-widget toggle sits outside every @can, so all five must render regardless of permissions'
        );

        // Scoped to the grid on purpose: the rest of the page renders its own
        // "View all" links outside the widgets (the Total customers small box,
        // for one), so a page-wide absence check would be false by construction.
        $grid = $this->widgetGridSlice($html);

        foreach ([
            'receivables' => route('admin.reports.revenue'),
            'renewals' => route('admin.reports.domains'),
            'support' => route('admin.tickets.index'),
            'health' => route('admin.cron.index'),
            'capacity' => route('admin.servers.index'),
        ] as $widget => $viewAllLink) {
            $this->assertStringNotContainsString(
                $viewAllLink,
                $grid,
                "the [{$widget}] widget's gated View all link must not render for this viewer"
            );
        }

        $this->assertStringNotContainsString('View all', $grid, 'no widget may render a View all link for this viewer');
    }

    public function test_each_widget_id_appears_exactly_once_in_the_document(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $html = $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        // A duplicated data-widget value would leave the controller's scoping
        // ambiguous: one id, two subtrees, one set of remembered spans.
        preg_match_all('/data-widget="([^"]+)"/', $html, $ids);

        $this->assertCount(5, $ids[1], 'the document must render exactly five widget wrappers');

        foreach (['receivables', 'renewals', 'support', 'health', 'capacity'] as $id) {
            $this->assertSame(
                1,
                substr_count($html, 'data-widget="'.$id.'"'),
                "widget id [{$id}] must appear exactly once"
            );
        }
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create(['role' => 'client']);

        return Customer::create(['user_id' => $user->id, 'status' => 'active']);
    }

    /**
     * Render money through the app's own currency partial, so an expected
     * figure is the string the dashboard actually emits rather than a
     * hand-written guess at the symbol, grouping and decimals.
     */
    private function rendersCurrency(float $value): string
    {
        return Blade::render('<x-adminlte.partials.currency :value="'.$value.'" />');
    }

    /**
     * The widget-grid slice of the rendered dashboard: from the grid container
     * open tag to the pre-paint restore script that follows it, so an
     * assertion about the widgets never reads the rest of the page.
     */
    private function widgetGridSlice(string $html): string
    {
        $start = strpos($html, 'data-dashboard-widget-grid');
        $end = strpos($html, 'adminlte.dashboardWidgetSpans.v1');

        $this->assertNotFalse($start, 'precondition: the widget grid container must render');
        $this->assertNotFalse($end, 'precondition: the pre-paint restore script must render');

        return substr($html, $start, $end - $start);
    }
}
