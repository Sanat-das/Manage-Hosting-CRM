<?php

namespace Tests\Feature;

use App\Events\TicketReply as TicketReplyEvent;
use App\Jobs\SendEmail;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * T14: department signatures on outbound staff replies, and that a transfer's
 * internal note never triggers customer-facing mail.
 */
class OutboundSignatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TicketService::forgetDepartmentCache();
    }

    public function test_signature_is_appended_when_the_department_has_one(): void
    {
        Queue::fake();

        TicketDepartment::where('slug', 'support')->update(['signature' => "Thanks,\nSupport Team"]);

        $ticket = $this->ticketInDepartment('support');
        app(TicketService::class)->reply($ticket, $this->staff(), 'Working on it.');

        Queue::assertPushed(SendEmail::class, fn (SendEmail $job) => str_contains($job->body, "\n\n--\nThanks,\nSupport Team"));
    }

    public function test_no_signature_block_when_the_department_signature_is_blank(): void
    {
        Queue::fake();

        TicketDepartment::where('slug', 'support')->update(['signature' => '']);

        $ticket = $this->ticketInDepartment('support');
        app(TicketService::class)->reply($ticket, $this->staff(), 'Working on it.');

        Queue::assertPushed(SendEmail::class, fn (SendEmail $job) => ! str_contains($job->body, '--'));
    }

    public function test_post_transfer_reply_uses_the_target_departments_address(): void
    {
        Queue::fake();

        TicketDepartment::where('slug', 'sales')->update(['email_address' => 'sales@example.test']);
        TicketDepartment::where('slug', 'billing')->update(['email_address' => 'billing@example.test']);

        $ticket = $this->ticketInDepartment('sales');
        $staff = $this->staff();

        app(TicketService::class)->transferDepartment($ticket, 'billing', $staff);

        $ticket->refresh();
        $this->assertSame('billing', $ticket->department);

        app(TicketService::class)->reply($ticket, $staff, 'Moved to billing, here is the update.');

        Queue::assertPushed(SendEmail::class, fn (SendEmail $job) => $job->fromEmail === 'billing@example.test');
    }

    public function test_add_note_does_not_dispatch_the_ticket_reply_event(): void
    {
        Event::fake([TicketReplyEvent::class]);

        $ticket = $this->ticketInDepartment('support');
        $staff = $this->staff();

        app(TicketService::class)->addNote($ticket, $staff, 'internal only');

        Event::assertNotDispatched(TicketReplyEvent::class);
    }

    public function test_transfer_does_not_dispatch_the_ticket_reply_event(): void
    {
        Event::fake([TicketReplyEvent::class]);

        $ticket = $this->ticketInDepartment('sales');
        $staff = $this->staff();

        app(TicketService::class)->transferDepartment($ticket, 'billing', $staff);

        Event::assertNotDispatched(TicketReplyEvent::class);
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
