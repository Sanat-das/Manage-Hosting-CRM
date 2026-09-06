<?php

namespace Tests\Feature;

use App\Events\TicketReply as TicketReplyEvent;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * `Client\TicketController::reply` unified through `TicketService::reply`
 * instead of a direct `replies()->create` + manual `last_reply_at` update.
 */
class ClientTicketReplyUnifyTest extends TestCase
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

    private function ticketFor(User $user, array $overrides = []): Ticket
    {
        return Ticket::create(array_merge([
            'customer_id' => $user->customer->id,
            'ticket_no' => 'TKT-'.random_int(100000, 999999),
            'subject' => 'Test ticket',
            'department' => 'support',
            'priority' => 'medium',
            'status' => 'answered',
        ], $overrides));
    }

    public function test_client_reply_moves_ticket_to_customer_reply(): void
    {
        $user = $this->clientUser();
        $ticket = $this->ticketFor($user, ['status' => 'answered']);

        $response = $this->actingAs($user)->post(route('client.tickets.reply', $ticket->id), [
            'message' => 'Following up on this.',
        ]);

        $response->assertRedirect(route('client.tickets.show', $ticket));
        $ticket->refresh();
        $this->assertSame('customer_reply', $ticket->status);
        $this->assertNotNull($ticket->last_reply_at);
        $this->assertCount(1, $ticket->replies);
        $this->assertFalse($ticket->replies->first()->is_staff);
    }

    public function test_client_reply_dispatches_ticket_reply_event(): void
    {
        Event::fake([TicketReplyEvent::class]);

        $user = $this->clientUser();
        $ticket = $this->ticketFor($user, ['status' => 'open']);

        $this->actingAs($user)->post(route('client.tickets.reply', $ticket->id), [
            'message' => 'Any update?',
        ]);

        Event::assertDispatched(TicketReplyEvent::class, fn (TicketReplyEvent $event) => $event->ticket->id === $ticket->id);
    }

    public function test_reply_on_closed_ticket_returns_error(): void
    {
        $user = $this->clientUser();
        $ticket = $this->ticketFor($user, ['status' => 'closed']);

        $response = $this->actingAs($user)->post(route('client.tickets.reply', $ticket->id), [
            'message' => 'Please reopen this.',
        ]);

        $response->assertSessionHasErrors('error');
        $ticket->refresh();
        $this->assertSame('closed', $ticket->status);
        $this->assertCount(0, $ticket->replies);
    }
}
