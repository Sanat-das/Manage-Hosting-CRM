<?php

namespace Tests\Feature;

use App\Jobs\SendEmail;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\TicketMailParser;
use App\Services\TicketService;
use App\Support\InboundEmail;
use App\Support\MailboxConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Per-department mailboxes: which inboxes get polled, and which address a
 * department's outbound ticket mail carries. Global mailbox has been removed —
 * only department mailboxes are polled.
 */
class TicketMailboxRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TicketService::forgetDepartmentCache();
    }

    public function test_no_mailbox_when_no_department_has_one(): void
    {
        $mailboxes = MailboxConfig::listForFetch();

        $this->assertCount(0, $mailboxes);
    }

    public function test_department_mailboxes_are_polled(): void
    {
        $this->giveMailbox('sales', 'sales@example.test');
        $this->giveMailbox('billing', 'billing@example.test');

        $mailboxes = MailboxConfig::listForFetch();

        $this->assertSame(
            ['Sales mailbox', 'Billing mailbox'],
            array_map(fn (MailboxConfig $m) => $m->label, $mailboxes)
        );
        $this->assertSame(['sales', 'billing'], array_map(fn (MailboxConfig $m) => $m->departmentSlug, $mailboxes));
    }

    public function test_departments_sharing_same_inbox_are_deduped(): void
    {
        $this->giveMailbox('sales', 'shared@example.test');
        $this->giveMailbox('support', 'shared@example.test');

        $mailboxes = MailboxConfig::listForFetch();

        $this->assertCount(1, $mailboxes);
        $this->assertSame('Sales mailbox', $mailboxes[0]->label);
    }

    public function test_a_disabled_department_is_not_polled(): void
    {
        $this->giveMailbox('sales', 'sales@example.test');
        TicketDepartment::where('slug', 'sales')->update(['enabled' => false]);

        $mailboxes = MailboxConfig::listForFetch();

        $this->assertSame([], array_map(fn (MailboxConfig $m) => $m->label, $mailboxes));
    }

    public function test_a_department_switched_on_without_a_host_is_not_polled(): void
    {
        TicketDepartment::where('slug', 'sales')->update(['imap_enabled' => true, 'imap_host' => '']);

        $mailboxes = MailboxConfig::listForFetch();

        $this->assertSame([], array_map(fn (MailboxConfig $m) => $m->label, $mailboxes));
    }

    public function test_the_department_option_narrows_the_run(): void
    {
        $this->giveMailbox('sales', 'sales@example.test');
        $this->giveMailbox('billing', 'billing@example.test');

        $mailboxes = MailboxConfig::listForFetch('billing');

        $this->assertSame(['Billing mailbox'], array_map(fn (MailboxConfig $m) => $m->label, $mailboxes));
    }

    public function test_a_switched_off_global_mailbox_is_skipped_unless_forced(): void
    {
        // Global mailbox no longer exists — list is department-only regardless of force
        $this->assertSame([], MailboxConfig::listForFetch());
        $this->assertSame([], MailboxConfig::listForFetch(null, null, true));
    }

    public function test_encryption_none_becomes_false_for_the_imap_client(): void
    {
        $this->giveMailbox('sales', 'sales@example.test');
        TicketDepartment::where('slug', 'sales')->update(['imap_encryption' => 'none']);

        $config = MailboxConfig::listForFetch('sales')[0]->toClientConfig();

        $this->assertFalse($config['encryption']);
        $this->assertSame('imap', $config['protocol']);
    }

    /**
     * A credential still sitting in the database as plaintext — an older dump,
     * a seeder, or any `where(...)->update()`, all of which bypass the cast —
     * must still be usable. The plain `encrypted` cast threw on read, and that
     * exception escaped `listForFetch()` and stopped inbound mail for every
     * department at once.
     */
    public function test_a_plaintext_password_is_still_readable(): void
    {
        $this->giveMailbox('sales', 'sales@example.test');

        $this->assertSame('secret', MailboxConfig::listForFetch('sales')[0]->password);
    }

    public function test_a_password_saved_through_the_model_round_trips_encrypted(): void
    {
        $this->giveMailbox('sales', 'sales@example.test');

        $department = TicketDepartment::where('slug', 'sales')->firstOrFail();
        $department->imap_password = 'rotated-secret';
        $department->save();

        $stored = DB::table('ticket_departments')->where('slug', 'sales')->value('imap_password');

        $this->assertNotSame('rotated-secret', $stored, 'The credential must not be written in the clear.');
        $this->assertSame('rotated-secret', MailboxConfig::listForFetch('sales')[0]->password);
    }

    public function test_the_command_reports_when_nothing_is_configured(): void
    {
        $this->artisan('tickets:fetch-mail')
            ->expectsOutputToContain('No ticket mailboxes configured')
            ->assertSuccessful();
    }

    public function test_the_command_fails_for_a_department_without_its_own_mailbox(): void
    {
        $this->artisan('tickets:fetch-mail --department=sales')
            ->expectsOutputToContain('has no enabled mailbox of its own')
            ->assertFailed();
    }

    public function test_outbound_mail_uses_the_departments_address_as_sender_and_reply_to(): void
    {
        Queue::fake();

        TicketDepartment::where('slug', 'billing')->update(['email_address' => 'billing@example.test']);

        $ticket = $this->ticketInDepartment('billing');
        app(TicketService::class)->reply($ticket, $this->staff(), 'Invoice reissued.');

        Queue::assertPushed(SendEmail::class, fn (SendEmail $job) => $job->fromEmail === 'billing@example.test'
            && ($job->headers['replyTo'] ?? null) === 'billing@example.test');
    }

    public function test_a_department_without_an_address_falls_back_to_mail_from(): void
    {
        Queue::fake();

        // No department email_address, no global imap_username — falls back to mail_from_address or null
        $ticket = $this->ticketInDepartment('technical');
        app(TicketService::class)->reply($ticket, $this->staff(), 'Patched.');

        Queue::assertPushed(SendEmail::class, fn (SendEmail $job) => $job->fromEmail === null);
    }

    public function test_a_reply_stays_on_its_own_ticket_department_whichever_inbox_it_arrived_in(): void
    {
        Queue::fake();

        // Ticket lives in Support; the customer answers the Sales address.
        $ticket = $this->ticketInDepartment('support');
        $staff = $this->staff();

        $outbound = TicketReply::create([
            'ticket_id' => $ticket->id,
            'user_id' => $staff->id,
            'message' => 'Any luck?',
            'is_staff' => true,
            'email_message_id' => 'ticket-out-1@example.test',
        ]);

        $result = app(TicketMailParser::class)->handle(InboundEmail::fromArray([
            'messageId' => 'inbound-x@mail.example',
            'inReplyTo' => 'ticket-out-1@example.test',
            'subject' => 'Re: anything',
            'fromEmail' => $ticket->customer->user->email,
            'body' => 'Still broken.',
        ]));

        $this->assertSame(TicketMailParser::STATUS_CREATED, $result['status']);
        $this->assertSame('support', $ticket->fresh()->department, 'The arriving mailbox must never move a ticket between departments.');
        $this->assertNotNull($outbound->id);
    }

    private function giveMailbox(string $slug, string $username): void
    {
        TicketDepartment::where('slug', $slug)->update([
            'imap_enabled' => true,
            'imap_host' => 'mail.example.test',
            'imap_port' => 993,
            'imap_username' => $username,
            'imap_password' => 'secret',
            'imap_folder' => 'INBOX',
        ]);
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
