<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Services\TicketService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TicketService::assign must reject assigning a ticket to a user who is not
 * a staff member of the ticket's department (admin bypasses the check).
 */
class TicketAssignGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TicketService::forgetDepartmentCache();
    }

    public function test_assign_succeeds_for_a_staff_member_in_the_ticket_department(): void
    {
        $ticket = $this->ticketInDepartment('support');

        $agent = User::factory()->create(['role' => 'staff']);
        TicketDepartment::where('slug', 'support')->firstOrFail()->staff()->attach($agent->id);

        $updated = app(TicketService::class)->assign($ticket, $agent->id);

        $this->assertSame($agent->id, $updated->assigned_to);
        $this->assertSame('open', $updated->status);
    }

    public function test_assign_rejects_a_staff_member_outside_the_ticket_department(): void
    {
        $ticket = $this->ticketInDepartment('support');

        $agent = User::factory()->create(['role' => 'staff']);
        TicketDepartment::where('slug', 'billing')->firstOrFail()->staff()->attach($agent->id);

        $this->expectException(DomainException::class);

        app(TicketService::class)->assign($ticket, $agent->id);
    }

    public function test_assign_rejects_a_client_user(): void
    {
        $ticket = $this->ticketInDepartment('support');

        $client = User::factory()->create(['role' => 'client']);

        $this->expectException(DomainException::class);

        app(TicketService::class)->assign($ticket, $client->id);
    }

    public function test_assign_with_null_clears_the_assignment_without_a_department_check(): void
    {
        $ticket = $this->ticketInDepartment('support');

        $agent = User::factory()->create(['role' => 'staff']);
        TicketDepartment::where('slug', 'support')->firstOrFail()->staff()->attach($agent->id);
        $ticket->update(['assigned_to' => $agent->id]);

        $updated = app(TicketService::class)->assign($ticket, null);

        $this->assertNull($updated->assigned_to);
        $this->assertSame('open', $updated->status);
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
}
