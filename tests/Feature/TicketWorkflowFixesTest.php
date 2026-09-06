<?php

namespace Tests\Feature;

use App\Jobs\SendEmail;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Notifications\TicketCreatedNotification;
use App\Notifications\TicketReplyNotification;
use App\Services\TicketService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Regressions for the ticket workflow audit.
 *
 * Each test here pins a bug that was silent — nothing errored, the wrong thing
 * simply happened — so they are the only thing standing between these and a
 * quiet reintroduction.
 */
class TicketWorkflowFixesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Cc used to be applied only on the rich (HTML/attachment) send path. A
     * plain-text reply with a Cc reported success and delivered to the To
     * alone.
     */
    public function test_a_plain_text_message_still_carries_its_cc_and_bcc(): void
    {
        config(['mail.default' => 'array']);

        (new SendEmail(
            'client@example.test',
            'Subject',
            'Body',
            null,
            [],
            ['manager@example.test'],
            ['audit@example.test'],
        ))->handle();

        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);

        $sent = $messages[0]->getOriginalMessage();

        $this->assertSame('manager@example.test', $sent->getCc()[0]->getAddress());
        $this->assertSame('audit@example.test', $sent->getBcc()[0]->getAddress());
    }

    /** The Cc/Bcc a message went out with are now recoverable from the log. */
    public function test_the_email_log_records_cc_and_bcc(): void
    {
        config(['mail.default' => 'array']);

        (new SendEmail(
            'client@example.test',
            'Subject',
            'Body',
            null,
            [],
            ['manager@example.test'],
            ['audit@example.test'],
        ))->handle();

        $this->assertDatabaseHas('emails', [
            'to_email' => 'client@example.test',
            'cc_emails' => 'manager@example.test',
            'bcc_emails' => 'audit@example.test',
            'status' => 'sent',
        ]);
    }

    /**
     * Assignment is routing, not workflow: a ticket waiting on staff must not
     * silently look like a fresh one just because it was handed to someone.
     */
    public function test_assigning_preserves_status(): void
    {
        $ticket = $this->ticket('support', TicketService::STATUS_CUSTOMER_REPLY);
        $staff = $this->departmentStaff('support');

        $result = app(TicketService::class)->assign($ticket, $staff->id);

        $this->assertSame(TicketService::STATUS_CUSTOMER_REPLY, $result->fresh()->status);
        $this->assertSame($staff->id, $result->fresh()->assigned_to);
    }

    /** Assigning used to reopen a closed ticket with no audit trail at all. */
    public function test_assigning_a_closed_ticket_is_rejected(): void
    {
        $ticket = $this->ticket('support', TicketService::STATUS_CLOSED);
        $staff = $this->departmentStaff('support');

        $this->expectException(DomainException::class);

        app(TicketService::class)->assign($ticket, $staff->id);
    }

    /**
     * The assignee and the department's own staff are the people who need to
     * know a customer answered. Notifications used to go to `role = 'admin'`
     * and nobody else.
     */
    public function test_a_customer_reply_notifies_the_assignee_not_only_admins(): void
    {
        Notification::fake();

        $staff = $this->departmentStaff('support');
        $ticket = $this->ticket('support', TicketService::STATUS_OPEN, $staff->id);
        $client = $ticket->customer->user;

        app(TicketService::class)->reply($ticket, $client, 'Any news?');

        Notification::assertSentTo($staff, TicketReplyNotification::class);
    }

    /**
     * A ticket opened by a customer produced no staff-facing signal of any
     * kind — the only way to find it was to go looking at the list.
     */
    public function test_opening_a_ticket_notifies_the_department_staff(): void
    {
        Notification::fake();

        $staff = $this->departmentStaff('support');
        $customer = $this->customer();

        app(TicketService::class)->create([
            'customer_id' => $customer->id,
            'subject' => 'Help please',
            'department' => 'support',
        ], 'It is broken.');

        Notification::assertSentTo($staff, TicketCreatedNotification::class);
    }

    /**
     * A `-- ` line in the middle of what the customer wrote is a separator,
     * not the end of their message. The parser used to `break` at the FIRST
     * one and discard everything below it; now only a short trailing block
     * (see TicketMailParser::MAX_SIGNATURE_LINES) reads as a signature, and
     * anything longer is the customer still talking.
     */
    public function test_a_dash_separator_mid_message_does_not_truncate_the_body(): void
    {
        $body = implode("\n", array_merge(
            ['Here is the first thing.', '-- '],
            array_map(fn (int $n) => "Second half line {$n}, still the customer writing.", range(1, 11)),
            ['line twelve.'],
        ));

        $stripped = app(\App\Services\TicketMailParser::class)->stripQuotedText($body);

        $this->assertStringContainsString('first thing', $stripped);
        $this->assertStringContainsString('line twelve', $stripped);
    }

    /** A genuine trailing signature block is still removed. */
    public function test_a_real_signature_block_is_still_stripped(): void
    {
        $body = "Please take a look.\n-- \nJane Doe\nAcme Ltd\n+44 1234 567890";

        $stripped = app(\App\Services\TicketMailParser::class)->stripQuotedText($body);

        $this->assertSame('Please take a look.', $stripped);
    }

    private function customer(): Customer
    {
        $user = User::factory()->create(['role' => 'client']);

        return Customer::create(['user_id' => $user->id, 'status' => 'active']);
    }

    /** A staff user who is a member of the given department. */
    private function departmentStaff(string $slug): User
    {
        $staff = User::factory()->create(['role' => 'support']);

        $department = TicketDepartment::firstOrCreate(
            ['slug' => $slug],
            ['name' => ucfirst($slug), 'enabled' => true, 'sort_order' => 1]
        );

        $department->staff()->syncWithoutDetaching([$staff->id]);

        return $staff;
    }

    private function ticket(string $department, string $status, ?int $assignedTo = null): Ticket
    {
        $customer = $this->customer();

        return Ticket::create([
            'ticket_no' => 'TKT-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'customer_id' => $customer->id,
            'subject' => 'Subject',
            'department' => $department,
            'priority' => 'medium',
            'status' => $status,
            'assigned_to' => $assignedTo,
            'last_reply_at' => now(),
        ]);
    }
}
