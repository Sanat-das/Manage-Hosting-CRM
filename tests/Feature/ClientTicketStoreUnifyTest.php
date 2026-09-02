<?php

namespace Tests\Feature;

use App\Events\TicketCreated;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * `Client\TicketController::store` unified through `TicketService::create`
 * instead of a direct `Ticket::create` + `T-` numbering.
 */
class ClientTicketStoreUnifyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TicketService::forgetDepartmentCache();
    }

    private function clientUser(): User
    {
        $user = User::factory()->create(['role' => 'client']);
        Customer::create(['user_id' => $user->id, 'status' => 'active']);

        return $user;
    }

    public function test_client_create_uses_service(): void
    {
        $user = $this->clientUser();

        $response = $this->actingAs($user)->post(route('client.tickets.store'), [
            'subject' => 'Cannot log in',
            'message' => 'Please help me reset access.',
            'priority' => 'high',
            'department' => 'support',
        ]);

        $ticket = Ticket::firstOrFail();

        $response->assertRedirect(route('client.tickets.show', $ticket));
        $this->assertStringStartsWith('TKT-', $ticket->ticket_no);
        $this->assertSame('support', $ticket->department);
        $this->assertSame('high', $ticket->priority);
        $this->assertNull($ticket->assigned_to);
        $this->assertCount(1, $ticket->replies);
        $this->assertFalse($ticket->replies->first()->is_staff);
    }

    public function test_client_create_dispatches_ticket_created_event(): void
    {
        Event::fake([TicketCreated::class]);

        $user = $this->clientUser();

        $this->actingAs($user)->post(route('client.tickets.store'), [
            'subject' => 'Billing question',
            'message' => 'Why was I charged twice?',
            'department' => 'billing',
        ]);

        $ticket = Ticket::firstOrFail();

        Event::assertDispatched(TicketCreated::class, fn (TicketCreated $event) => $event->ticket->id === $ticket->id);
    }

    public function test_client_create_sets_last_reply_at(): void
    {
        $user = $this->clientUser();

        $this->actingAs($user)->post(route('client.tickets.store'), [
            'subject' => 'Server down',
            'message' => 'My server is not responding.',
            'department' => 'technical',
        ]);

        $ticket = Ticket::firstOrFail();

        $this->assertNotNull($ticket->last_reply_at);
    }

    public function test_invalid_department_rejected(): void
    {
        $user = $this->clientUser();

        $response = $this->actingAs($user)->post(route('client.tickets.store'), [
            'subject' => 'Test',
            'message' => 'Test message.',
            'department' => 'not-a-real-department',
        ]);

        $response->assertSessionHasErrors('department');
        $this->assertSame(0, Ticket::count());
    }
}
