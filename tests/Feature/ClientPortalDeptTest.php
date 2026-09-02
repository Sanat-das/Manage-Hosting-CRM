<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Database\Seeders\AdminLteRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T24 — client portal reflects the current (possibly transferred) department
 * via `TicketService::departmentLabel`, and clients cannot reach the
 * admin-only transfer route.
 */
class ClientPortalDeptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TicketService::forgetDepartmentCache();
    }

    public function test_client_index_shows_department_label(): void
    {
        [$user, $customer] = $this->clientWithCustomer();

        $ticket = $this->makeTicket($customer, 'billing');

        $response = $this->actingAs($user)->get(route('client.tickets.index'));

        $response->assertOk()
            ->assertSee($ticket->ticket_no)
            ->assertSee(TicketService::departmentLabel('billing'));
    }

    public function test_client_sees_new_department(): void
    {
        $this->seed(AdminLteRbacSeeder::class);

        [$client, $customer] = $this->clientWithCustomer();
        $ticket = $this->makeTicket($customer, 'support');

        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->roles()->syncWithoutDetaching($adminRole);

        $this->actingAs($admin)
            ->post(route('admin.tickets.transfer', $ticket), [
                'target_department' => 'billing',
            ])
            ->assertRedirect(route('admin.tickets.show', $ticket));

        $this->assertSame('billing', $ticket->fresh()->department);

        $response = $this->actingAs($client)->get(route('client.tickets.show', $ticket));

        $response->assertOk()
            ->assertSee(TicketService::departmentLabel('billing'))
            ->assertDontSee(TicketService::departmentLabel('support'));
    }

    public function test_client_cannot_transfer(): void
    {
        $this->seed(AdminLteRbacSeeder::class);

        [$client, $customer] = $this->clientWithCustomer();
        $ticket = $this->makeTicket($customer, 'support');

        $this->actingAs($client)
            ->post(route('admin.tickets.transfer', $ticket), [
                'target_department' => 'billing',
            ])
            ->assertForbidden();

        $this->assertSame('support', $ticket->fresh()->department);
    }

    /**
     * @return array{0: User, 1: Customer}
     */
    private function clientWithCustomer(): array
    {
        $user = User::factory()->create(['role' => 'client']);
        $customer = Customer::create(['user_id' => $user->id, 'status' => 'active']);

        return [$user, $customer];
    }

    private function makeTicket(Customer $customer, string $department): Ticket
    {
        return Ticket::create([
            'ticket_no' => 'TKT-'.random_int(10000, 99999),
            'customer_id' => $customer->id,
            'subject' => 'Test ticket',
            'priority' => 'medium',
            'status' => 'open',
            'department' => $department,
            'last_reply_at' => now(),
        ]);
    }
}
