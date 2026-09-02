<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T16 — `Admin\TicketController::index/show/stats` scoped by department for
 * staff via `TicketService::applyVisibility`. Admins bypass the scope, per
 * `Ticket::scopeVisibleTo`.
 */
class AdminTicketVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TicketService::forgetDepartmentCache();
    }

    public function test_sales_scoped_staff_index_sees_only_sales_tickets(): void
    {
        $sales = $this->ticketInDepartment('sales');
        $this->ticketInDepartment('billing');

        $response = $this->actingAsStaff(['sales'])
            ->get(route('admin.tickets.index'));

        $response->assertOk();
        $ids = $response->viewData('tickets')->pluck('id')->all();

        $this->assertSame([$sales->id], $ids);
    }

    public function test_admin_index_sees_all_tickets(): void
    {
        $sales = $this->ticketInDepartment('sales');
        $billing = $this->ticketInDepartment('billing');

        $response = $this->actingAsStaff([], 'admin')
            ->get(route('admin.tickets.index'));

        $response->assertOk();
        $ids = $response->viewData('tickets')->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$sales->id, $billing->id], $ids);
    }

    public function test_staff_show_of_out_of_scope_ticket_is_forbidden(): void
    {
        $billing = $this->ticketInDepartment('billing');

        $this->actingAsStaff(['sales'])
            ->get(route('admin.tickets.show', $billing))
            ->assertForbidden();
    }

    public function test_staff_show_of_in_scope_ticket_succeeds(): void
    {
        $sales = $this->ticketInDepartment('sales');

        $this->actingAsStaff(['sales'])
            ->get(route('admin.tickets.show', $sales))
            ->assertOk();
    }

    public function test_stats_counts_are_scoped_to_staff_department(): void
    {
        $this->ticketInDepartment('sales');
        $this->ticketInDepartment('sales');
        $this->ticketInDepartment('billing');

        $response = $this->actingAsStaff(['sales'])
            ->get(route('admin.tickets.index'));

        $response->assertOk();
        $this->assertSame(2, (int) $response->viewData('stats')->sum());
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

    /**
     * @param  array<int, string>  $departments
     */
    private function actingAsStaff(array $departments, string $roleName = 'sales'): self
    {
        $user = User::factory()->create(['role' => $roleName]);

        $role = Role::firstOrCreate(['name' => $roleName], ['label' => ucfirst($roleName)]);
        foreach (['tickets.view'] as $permName) {
            $permission = Permission::firstOrCreate(['name' => $permName], ['label' => $permName]);
            $role->permissions()->syncWithoutDetaching($permission->id);
        }
        $user->assignRole($roleName);

        foreach ($departments as $slug) {
            TicketDepartment::where('slug', $slug)->firstOrFail()->staff()->attach($user->id);
        }

        return $this->actingAs($user);
    }
}
