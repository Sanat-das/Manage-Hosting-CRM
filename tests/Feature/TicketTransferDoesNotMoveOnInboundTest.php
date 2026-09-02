<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\TicketMailParser;
use App\Services\TicketService;
use App\Support\InboundEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Transfer is a staff-only action (TicketService::transferDepartment). Once a
 * ticket has been moved, no inbound mail — however it matches the ticket, and
 * whichever department's mailbox it arrives on — may move it back or onward.
 */
class TicketTransferDoesNotMoveOnInboundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TicketService::forgetDepartmentCache();
    }

    public function test_inbound_does_not_move(): void
    {
        $ticket = $this->ticketInDepartment('sales');
        $staff = $this->staff();

        app(TicketService::class)->transferDepartment($ticket, 'billing', $staff);

        $this->assertSame('billing', $ticket->fresh()->department);

        $outbound = TicketReply::create([
            'ticket_id' => $ticket->id,
            'user_id' => $staff->id,
            'message' => 'Following up.',
            'is_staff' => true,
            'email_message_id' => 'ticket-out-1@example.test',
        ]);

        $result = app(TicketMailParser::class)->handle(InboundEmail::fromArray([
            'messageId' => 'inbound-x@mail.example',
            'inReplyTo' => 'ticket-out-1@example.test',
            'subject' => 'Re: Something',
            'fromEmail' => $ticket->customer->user->email,
            'body' => 'Still broken.',
        ]), false, 'sales');

        $this->assertSame(TicketMailParser::STATUS_CREATED, $result['status']);
        $this->assertSame('billing', $ticket->fresh()->department, 'Inbound mail must never move a ticket away from its staff-assigned department.');
        $this->assertNotNull($outbound->id);
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function ticketInDepartment(string $slug): Ticket
    {
        $user = User::factory()->create(['role' => 'client']);
        $customer = Customer::create(['user_id' => $user->id, 'status' => 'active']);

        $ticket = Ticket::create([
            'ticket_no' => 'TKT-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'customer_id' => $customer->id,
            'subject' => 'Something',
            'priority' => 'medium',
            'status' => 'open',
            'department' => $slug,
            'last_reply_at' => now(),
        ]);

        TicketReply::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'message' => 'Opening message.',
            'is_staff' => false,
        ]);

        return $ticket->fresh(['customer']);
    }
}
