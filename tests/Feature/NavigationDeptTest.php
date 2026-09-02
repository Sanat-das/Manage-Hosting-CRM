<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\User;
use ColorlibHQ\AdminLte\AdminLte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * T25 — sidebar/gridSort/department-filter polish.
 */
class NavigationDeptTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Resolve $routeName as the current route, then build the REAL menu from
     * config through the full filter pipeline and report the Tickets group.
     */
    private function ticketsStateFor(string $routeName, array $routeParams = []): array
    {
        $uri = '/verify-'.str_replace(['.', '/'], '-', $routeName);
        Route::get($uri, fn () => 'x')->name($routeName);
        $this->get($uri);

        $adminlte = new AdminLte(
            config('adminlte.menu'),
            app('events'),
            app()
        );

        $state = ['parent' => false, 'all' => false];
        foreach ($adminlte->menu('sidebar') as $item) {
            if (($item['text'] ?? '') !== 'Tickets' || ! isset($item['submenu'])) {
                continue;
            }
            $state['parent'] = ! empty($item['active']);
            foreach ($item['submenu'] as $child) {
                if ($child['text'] === 'All Tickets') {
                    $state['all'] = ! empty($child['active']);
                }
            }
        }

        return $state;
    }

    private function actingAsTicketAdmin(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        $role = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $role->permissions()->sync([
            Permission::firstOrCreate(['name' => 'tickets.view'], ['label' => 'View Tickets'])->id,
        ]);
        $user->roles()->sync([$role->id]);
        $this->actingAs($user);

        return $user;
    }

    private function ticketInDepartment(string $department): Ticket
    {
        $user = User::factory()->create(['role' => 'client']);
        $customer = Customer::create(['user_id' => $user->id, 'status' => 'active']);

        return Ticket::create([
            'ticket_no' => 'TKT-'.random_int(10000, 99999),
            'customer_id' => $customer->id,
            'subject' => 'Test',
            'priority' => 'low',
            'status' => 'open',
            'department' => $department,
            'last_reply_at' => now(),
        ]);
    }

    public function test_sidebar_tickets_active_on_index(): void
    {
        $this->actingAsTicketAdmin();
        $state = $this->ticketsStateFor('admin.tickets.index');

        $this->assertTrue($state['all'], 'All Tickets should be active on admin.tickets.index');
        $this->assertTrue($state['parent'], 'Tickets treeview parent should open');
    }

    public function test_sidebar_tickets_active_on_show(): void
    {
        $this->actingAsTicketAdmin();
        $state = $this->ticketsStateFor('admin.tickets.show');

        $this->assertTrue($state['all'], 'All Tickets should be active on admin.tickets.show');
        $this->assertTrue($state['parent'], 'Tickets treeview parent should open');
    }

    public function test_dead_route_removed(): void
    {
        foreach (config('adminlte.menu') as $item) {
            if (($item['text'] ?? '') !== 'Tickets' || ! isset($item['submenu'])) {
                continue;
            }

            foreach ($item['submenu'] as $child) {
                $this->assertNotSame('Open Tickets', $child['text'] ?? null, 'dead Open Tickets menu item should be removed');
                $active = $child['active'] ?? [];
                foreach ((array) $active as $pattern) {
                    $this->assertStringNotContainsString('admin/tickets/open', $pattern, 'dead admin/tickets/open* active pattern should be removed');
                }
            }
        }
    }

    public function test_department_filter_includes_new(): void
    {
        TicketDepartment::create([
            'name' => 'General',
            'slug' => 'general',
            'enabled' => true,
            'sort_order' => 900,
        ]);
        TicketDepartment::create([
            'name' => 'Abuse',
            'slug' => 'abuse',
            'enabled' => true,
            'sort_order' => 901,
        ]);

        $this->actingAsTicketAdmin();

        $response = $this->get(route('admin.tickets.index'));

        $response->assertOk();
        $departments = $response->viewData('departments');

        $this->assertArrayHasKey('general', $departments);
        $this->assertArrayHasKey('abuse', $departments);
    }

    public function test_gridsort_by_department_works(): void
    {
        $this->actingAsTicketAdmin();

        $this->ticketInDepartment('billing');
        $this->ticketInDepartment('abuse');
        $this->ticketInDepartment('sales');

        $asc = $this->get(route('admin.tickets.index', ['sort' => 'department', 'direction' => 'asc']));
        $asc->assertOk();
        $ascDepartments = $asc->viewData('tickets')->pluck('department')->all();
        $sorted = $ascDepartments;
        sort($sorted);
        $this->assertSame($sorted, $ascDepartments, 'ascending gridSort by department should be sorted');

        $desc = $this->get(route('admin.tickets.index', ['sort' => 'department', 'direction' => 'desc']));
        $desc->assertOk();
        $descDepartments = $desc->viewData('tickets')->pluck('department')->all();
        $sortedDesc = $descDepartments;
        rsort($sortedDesc);
        $this->assertSame($sortedDesc, $descDepartments, 'descending gridSort by department should be sorted');
    }
}
