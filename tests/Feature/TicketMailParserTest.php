<?php

namespace Tests\Feature;

use App\Jobs\SendEmail;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\TicketMailParser;
use App\Services\TicketMailService;
use App\Services\TicketService;
use App\Settings\EmailSettings;
use App\Support\InboundEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Inbound ticket mail: matching, sender authorisation, quote stripping and the
 * guards that stop an autoresponder looping with us.
 */
class TicketMailParserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The reply this creates would otherwise queue an outbound email.
        Queue::fake();
    }

    public function test_a_reply_is_matched_by_in_reply_to_and_added_to_the_ticket(): void
    {
        [$ticket, $customer] = $this->ticketWithCustomer();
        $outbound = $this->outboundReply($ticket, 'ticket-1-outbound@example.test');

        $result = $this->parse([
            'messageId' => 'inbound-1@mail.example',
            'inReplyTo' => 'ticket-1-outbound@example.test',
            'subject' => 'Re: totally rewritten subject',
            'fromEmail' => 'client@example.test',
            'body' => "Still broken, please look again.\n",
        ]);

        $this->assertSame(TicketMailParser::STATUS_CREATED, $result['status']);
        $this->assertSame('Still broken, please look again.', $result['reply']->message);
        $this->assertFalse((bool) $result['reply']->is_staff);
        $this->assertSame('inbound-1@mail.example', $result['reply']->email_message_id);
        $this->assertSame('ticket-1-outbound@example.test', $result['reply']->email_in_reply_to);

        // Customer reply flags the conversation as waiting on staff.
        $this->assertSame(TicketService::STATUS_CUSTOMER_REPLY, $ticket->fresh()->status);
        $this->assertNotNull($outbound);
    }

    public function test_a_matched_reply_stores_the_html_body_and_raw_source(): void
    {
        [$ticket] = $this->ticketWithCustomer();
        $this->outboundReply($ticket, 'ticket-html-outbound@example.test');

        $result = $this->parse([
            'messageId' => 'inbound-html-1@mail.example',
            'inReplyTo' => 'ticket-html-outbound@example.test',
            'subject' => 'Re: totally rewritten subject',
            'fromEmail' => 'client@example.test',
            'body' => "Still broken, please look again.\n",
            'htmlBody' => '<p>Still broken, please look again.</p>',
            'rawSource' => "From: client@example.test\r\nSubject: hi\r\n\r\nStill broken, please look again.",
        ]);

        $this->assertSame(TicketMailParser::STATUS_CREATED, $result['status']);
        $this->assertSame('<p>Still broken, please look again.</p>', $result['reply']->html_body);
        $this->assertStringContainsString('From: client@example.test', $result['reply']->raw_source);
    }

    public function test_a_reply_with_no_html_body_or_raw_source_stores_null(): void
    {
        [$ticket] = $this->ticketWithCustomer();
        $this->outboundReply($ticket, 'ticket-plain-outbound@example.test');

        $result = $this->parse([
            'messageId' => 'inbound-plain-1@mail.example',
            'inReplyTo' => 'ticket-plain-outbound@example.test',
            'subject' => 'Re: totally rewritten subject',
            'fromEmail' => 'client@example.test',
            'body' => 'Plain text only.',
        ]);

        $this->assertSame(TicketMailParser::STATUS_CREATED, $result['status']);
        $this->assertNull($result['reply']->html_body);
        $this->assertNull($result['reply']->raw_source);
    }

    public function test_a_new_ticket_opened_by_mail_stores_the_html_body_and_raw_source(): void
    {
        $result = $this->parse([
            'messageId' => 'inbound-html-open@mail.example',
            'subject' => 'Need help urgently',
            'fromEmail' => 'newclient@example.test',
            'body' => 'Please help.',
            'htmlBody' => '<p>Please help.</p>',
            'rawSource' => "From: newclient@example.test\r\nSubject: Need help urgently\r\n\r\nPlease help.",
        ]);

        $this->assertSame(TicketMailParser::STATUS_TICKET_OPENED, $result['status']);
        $reply = $result['ticket']->replies()->orderBy('id')->first();
        $this->assertSame('<p>Please help.</p>', $reply->html_body);
        $this->assertStringContainsString('Subject: Need help urgently', $reply->raw_source);
    }

    public function test_a_matched_reply_stores_to_and_cc_addresses(): void
    {
        [$ticket] = $this->ticketWithCustomer();
        $this->outboundReply($ticket, 'ticket-toc-outbound@example.test');

        $result = $this->parse([
            'messageId' => 'inbound-toc-1@mail.example',
            'inReplyTo' => 'ticket-toc-outbound@example.test',
            'subject' => 'Re: totally rewritten subject',
            'fromEmail' => 'client@example.test',
            'body' => 'CCing my manager.',
            'toEmails' => ['Support@Example.Test'],
            'ccEmails' => ['manager@example.test', 'manager@example.test'],
        ]);

        $this->assertSame(TicketMailParser::STATUS_CREATED, $result['status']);
        $this->assertSame(['support@example.test'], $result['reply']->to);
        $this->assertSame(['manager@example.test'], $result['reply']->cc);
    }

    public function test_a_reply_with_no_to_or_cc_stores_null(): void
    {
        [$ticket] = $this->ticketWithCustomer();
        $this->outboundReply($ticket, 'ticket-noc-outbound@example.test');

        $result = $this->parse([
            'messageId' => 'inbound-noc-1@mail.example',
            'inReplyTo' => 'ticket-noc-outbound@example.test',
            'subject' => 'Re: totally rewritten subject',
            'fromEmail' => 'client@example.test',
            'body' => 'No cc here.',
        ]);

        $this->assertSame(TicketMailParser::STATUS_CREATED, $result['status']);
        $this->assertNull($result['reply']->to);
        $this->assertNull($result['reply']->cc);
    }

    public function test_a_matched_reply_stores_its_attachments_on_disk(): void
    {
        Storage::fake('local');

        [$ticket] = $this->ticketWithCustomer();
        $this->outboundReply($ticket, 'ticket-att-outbound@example.test');

        $result = $this->parse([
            'messageId' => 'inbound-att-1@mail.example',
            'inReplyTo' => 'ticket-att-outbound@example.test',
            'subject' => 'Re: totally rewritten subject',
            'fromEmail' => 'client@example.test',
            'body' => 'See attached.',
            'attachments' => [
                [
                    'filename' => 'screenshot.png',
                    'mimeType' => 'image/png',
                    'content' => 'fake-png-bytes',
                    'isInline' => false,
                ],
                [
                    'filename' => 'inline.jpg',
                    'mimeType' => 'image/jpeg',
                    'content' => 'fake-jpg-bytes',
                    'isInline' => true,
                    'contentId' => 'img1',
                ],
            ],
        ]);

        $this->assertSame(TicketMailParser::STATUS_CREATED, $result['status']);
        $reply = $result['reply'];
        $this->assertCount(2, $reply->attachments);

        $file = $reply->attachments()->where('filename', 'screenshot.png')->first();
        $this->assertNotNull($file);
        $this->assertSame('image/png', $file->mime_type);
        $this->assertFalse($file->is_inline);
        $this->assertSame(strlen('fake-png-bytes'), $file->size_bytes);
        Storage::disk('local')->assertExists($file->path);
        $this->assertSame('fake-png-bytes', Storage::disk('local')->get($file->path));

        $inline = $reply->attachments()->where('filename', 'inline.jpg')->first();
        $this->assertTrue($inline->is_inline);
        $this->assertSame('img1', $inline->content_id);
    }

    public function test_an_attachment_filename_cannot_traverse_outside_its_directory(): void
    {
        Storage::fake('local');

        [$ticket] = $this->ticketWithCustomer();
        $this->outboundReply($ticket, 'ticket-traversal-outbound@example.test');

        $result = $this->parse([
            'messageId' => 'inbound-traversal-1@mail.example',
            'inReplyTo' => 'ticket-traversal-outbound@example.test',
            'subject' => 'Re: totally rewritten subject',
            'fromEmail' => 'client@example.test',
            'body' => 'Nice try.',
            'attachments' => [
                [
                    'filename' => '../../../../etc/passwd',
                    'mimeType' => 'text/plain',
                    'content' => 'malicious',
                ],
            ],
        ]);

        $file = $result['reply']->attachments()->first();
        $this->assertSame('passwd', $file->filename);
        $this->assertStringStartsWith("ticket-attachments/{$ticket->id}/", $file->path);
    }

    public function test_a_reply_is_matched_by_the_subject_tag_when_headers_are_stripped(): void
    {
        [$ticket] = $this->ticketWithCustomer();

        $result = $this->parse([
            'messageId' => 'inbound-2@mail.example',
            'subject' => 'Re: ['.$ticket->ticket_no.'] Cannot reach my site',
            'fromEmail' => 'client@example.test',
            'body' => 'Any update?',
        ]);

        $this->assertSame(TicketMailParser::STATUS_CREATED, $result['status']);
        $this->assertSame($ticket->id, $result['reply']->ticket_id);
    }

    public function test_references_are_used_when_in_reply_to_is_absent(): void
    {
        [$ticket] = $this->ticketWithCustomer();
        $this->outboundReply($ticket, 'ticket-1-first@example.test');

        $result = $this->parse([
            'messageId' => 'inbound-3@mail.example',
            'references' => ['<unrelated@elsewhere>', '<ticket-1-first@example.test>'],
            'subject' => 'Re: no tag here',
            'fromEmail' => 'client@example.test',
            'body' => 'Following up.',
        ]);

        $this->assertSame(TicketMailParser::STATUS_CREATED, $result['status']);
        $this->assertSame($ticket->id, $result['reply']->ticket_id);
    }

    public function test_a_message_matching_no_ticket_opens_one_in_the_mailboxs_department(): void
    {
        $this->ticketWithCustomer();

        $result = $this->parse([
            'messageId' => 'inbound-4@mail.example',
            'subject' => 'Re: Fwd: Hello, I have a question',
            'fromEmail' => 'client@example.test',
            'body' => 'Can you help?',
        ], 'sales');

        $this->assertSame(TicketMailParser::STATUS_TICKET_OPENED, $result['status']);
        $this->assertSame('sales', $result['ticket']->department);
        // Reply/forward prefixes are stripped from the new ticket's subject.
        $this->assertSame('Hello, I have a question', $result['ticket']->subject);

        // The opening reply keeps the sender's Message-ID, so re-fetching the
        // same mail is a duplicate rather than a second ticket.
        $opening = $result['ticket']->replies()->orderBy('id')->first();
        $this->assertSame('inbound-4@mail.example', $opening->email_message_id);
        $this->assertFalse((bool) $opening->is_staff);

        // The acknowledgement threads against that same id, so the sender's
        // next reply cites it in References and matches this ticket even if the
        // subject tag is mangled.
        Queue::assertPushed(SendEmail::class, fn (SendEmail $job) => ($job->headers['inReplyTo'] ?? null) === 'inbound-4@mail.example'
            && in_array('inbound-4@mail.example', (array) ($job->headers['references'] ?? []), true));
    }

    public function test_a_reply_to_our_acknowledgement_lands_on_the_mail_opened_ticket(): void
    {
        $this->enableAutoCreate();
        $opened = $this->parse([
            'messageId' => 'inbound-thread-1@mail.example',
            'subject' => 'Quote please',
            'fromEmail' => 'jane@newco.test',
            'body' => 'How much?',
        ], 'sales');

        $this->assertSame(TicketMailParser::STATUS_TICKET_OPENED, $opened['status']);

        // Their client answers our acknowledgement: In-Reply-To is our ack's id
        // (which we never stored), but References still carries the original.
        $followUp = $this->parse([
            'messageId' => 'inbound-thread-2@mail.example',
            'inReplyTo' => 'ticket-ack-we-did-not-store@example.test',
            'references' => ['inbound-thread-1@mail.example', 'ticket-ack-we-did-not-store@example.test'],
            'subject' => 'no tag at all',
            'fromEmail' => 'jane@newco.test',
            'body' => 'Any update?',
        ], 'sales');

        $this->assertSame(TicketMailParser::STATUS_CREATED, $followUp['status']);
        $this->assertSame($opened['ticket']->id, $followUp['reply']->ticket_id);
        $this->assertSame(1, Ticket::count(), 'The follow-up must join the ticket, not open a second one.');
    }

    public function test_the_same_message_does_not_open_two_tickets(): void
    {
        $payload = [
            'messageId' => 'inbound-4b@mail.example',
            'subject' => 'Hello',
            'fromEmail' => 'client@example.test',
            'body' => 'Can you help?',
        ];

        $this->ticketWithCustomer();

        $this->assertSame(TicketMailParser::STATUS_TICKET_OPENED, $this->parse($payload, 'sales')['status']);
        $this->assertSame(TicketMailParser::STATUS_DUPLICATE, $this->parse($payload, 'sales')['status']);
        $this->assertSame(1, Ticket::where('department', 'sales')->count());
    }

    public function test_a_department_can_refuse_new_tickets_by_email(): void
    {
        $this->ticketWithCustomer();
        TicketDepartment::where('slug', 'sales')->update(['allow_new_tickets' => false]);

        $result = $this->parse([
            'messageId' => 'inbound-4c@mail.example',
            'subject' => 'Hello',
            'fromEmail' => 'client@example.test',
            'body' => 'Can you help?',
        ], 'sales');

        $this->assertSame(TicketMailParser::STATUS_UNMATCHED, $result['status']);
        $this->assertStringContainsString('does not accept new tickets', $result['reason']);
        $this->assertDatabaseCount('ticket_replies', 1);
    }

    public function test_an_unknown_sender_is_registered_and_gets_a_ticket(): void
    {
        $this->enableAutoCreate();
        $result = $this->parse([
            'messageId' => 'inbound-4d@mail.example',
            'subject' => 'Quote please',
            'fromEmail' => 'jane.doe@newco.test',
            'fromName' => 'Jane Doe',
            'body' => 'How much for 5 sites?',
        ], 'sales');

        $this->assertSame(TicketMailParser::STATUS_TICKET_OPENED, $result['status']);

        $user = User::where('email', 'jane.doe@newco.test')->first();
        $this->assertNotNull($user);
        $this->assertSame('Jane', $user->first_name);
        $this->assertSame('Doe', $user->last_name);
        $this->assertSame('client', $user->role);
        // Registered, but with no usable password — reachable only via reset.
        $this->assertNotEmpty($user->password_hash);
        $this->assertDatabaseHas('customers', ['user_id' => $user->id, 'status' => 'active']);
        $this->assertSame($user->id, $result['ticket']->customer->user_id);
    }

    public function test_a_registered_sender_without_a_display_name_gets_one_from_the_address(): void
    {
        $this->enableAutoCreate();
        $result = $this->parse([
            'messageId' => 'inbound-4e@mail.example',
            'subject' => 'Hi',
            'fromEmail' => 'john.smith@newco.test',
            'body' => 'Question.',
        ], 'sales');

        $this->assertSame(TicketMailParser::STATUS_TICKET_OPENED, $result['status']);

        $user = User::where('email', 'john.smith@newco.test')->firstOrFail();
        $this->assertSame('John', $user->first_name);
        $this->assertSame('Smith', $user->last_name);
    }

    public function test_auto_registration_can_be_switched_off_to_hold_unknown_senders(): void
    {
        $settings = app(EmailSettings::class);
        $settings->fill(['imap_auto_create_customers' => false]);
        $settings->save();

        $result = $this->parse([
            'messageId' => 'inbound-4f@mail.example',
            'subject' => 'Quote please',
            'fromEmail' => 'stranger@newco.test',
            'body' => 'How much?',
        ], 'sales');

        // With guest flow, unknown senders create guest tickets instead of being held
        $this->assertSame(TicketMailParser::STATUS_TICKET_OPENED, $result['status']);
        $this->assertStringContainsString('guest ticket', $result['reason']);
        $this->assertDatabaseMissing('users', ['email' => 'stranger@newco.test']);
        $this->assertDatabaseCount('tickets', 1);
        $this->assertSame('stranger@newco.test', $result['ticket']->guest_email);
        $this->assertNull($result['ticket']->customer_id);
    }

    public function test_the_global_mailbox_uses_the_configured_default_department(): void
    {
        $settings = app(EmailSettings::class);
        $settings->fill(['imap_default_department' => 'billing']);
        $settings->save();

        $result = $this->parse([
            'messageId' => 'inbound-4g@mail.example',
            'subject' => 'Invoice question',
            'fromEmail' => 'someone@newco.test',
            'body' => 'About invoice 12.',
        ]);

        $this->assertSame(TicketMailParser::STATUS_TICKET_OPENED, $result['status']);
        $this->assertSame('billing', $result['ticket']->department);
    }

    public function test_the_global_mailbox_falls_back_to_the_first_enabled_department(): void
    {
        // T6: departmentFor now prefers is_default over ordered first. To verify
        // the ordered fallback still works, clear the default first — otherwise
        // the seeded support (is_default=true) would win over sales (lowest sort_order).
        TicketDepartment::query()->update(['is_default' => false]);
        TicketService::forgetDepartmentCache();

        $result = $this->parse([
            'messageId' => 'inbound-4h@mail.example',
            'subject' => 'Anything',
            'fromEmail' => 'someone@newco.test',
            'body' => 'Hello.',
        ]);

        $this->assertSame(TicketMailParser::STATUS_TICKET_OPENED, $result['status']);
        $this->assertSame('sales', $result['ticket']->department, 'sales has the lowest sort_order.');
    }

    public function test_a_dry_run_opens_nothing_and_registers_nobody(): void
    {
        $result = app(TicketMailParser::class)->handle(InboundEmail::fromArray([
            'messageId' => 'inbound-4i@mail.example',
            'subject' => 'Quote please',
            'fromEmail' => 'nobody@newco.test',
            'body' => 'How much?',
        ]), dryRun: true, mailboxDepartment: 'sales');

        $this->assertSame(TicketMailParser::STATUS_WOULD_OPEN_TICKET, $result['status']);
        $this->assertDatabaseMissing('users', ['email' => 'nobody@newco.test']);
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_a_staff_address_with_no_customer_record_is_held_not_registered(): void
    {
        User::factory()->create(['email' => 'agent@example.test', 'role' => 'admin']);

        $result = $this->parse([
            'messageId' => 'inbound-4j@mail.example',
            'subject' => 'Note to self',
            'fromEmail' => 'agent@example.test',
            'body' => 'Reminder.',
        ], 'sales');

        // Staff address with no customer still creates guest ticket (unknown user) rather than holding
        $this->assertSame(TicketMailParser::STATUS_TICKET_OPENED, $result['status']);
        $this->assertDatabaseCount('tickets', 1);
        $this->assertSame('agent@example.test', $result['ticket']->guest_email);
    }

    public function test_a_stranger_cannot_post_into_someone_elses_ticket(): void
    {
        [$ticket] = $this->ticketWithCustomer();
        User::factory()->create(['email' => 'stranger@example.test', 'role' => 'client']);

        $result = $this->parse([
            'messageId' => 'inbound-5@mail.example',
            'subject' => 'Re: ['.$ticket->ticket_no.'] Cannot reach my site',
            'fromEmail' => 'stranger@example.test',
            'body' => 'Give me their server details.',
        ]);

        $this->assertSame(TicketMailParser::STATUS_UNKNOWN_SENDER, $result['status']);
        $this->assertDatabaseCount('ticket_replies', 1);
    }

    public function test_a_staff_address_may_reply_to_any_ticket(): void
    {
        [$ticket] = $this->ticketWithCustomer();
        User::factory()->create(['email' => 'agent@example.test', 'role' => 'admin']);

        $result = $this->parse([
            'messageId' => 'inbound-6@mail.example',
            'subject' => 'Re: ['.$ticket->ticket_no.'] Cannot reach my site',
            'fromEmail' => 'agent@example.test',
            'body' => 'Looking into it now.',
        ]);

        $this->assertSame(TicketMailParser::STATUS_CREATED, $result['status']);
        $this->assertTrue((bool) $result['reply']->is_staff);
        $this->assertSame(TicketService::STATUS_ANSWERED, $ticket->fresh()->status);
    }

    public function test_the_same_message_is_never_filed_twice(): void
    {
        [$ticket] = $this->ticketWithCustomer();

        $payload = [
            'messageId' => 'inbound-7@mail.example',
            'subject' => 'Re: ['.$ticket->ticket_no.'] Cannot reach my site',
            'fromEmail' => 'client@example.test',
            'body' => 'Same message twice.',
        ];

        $this->assertSame(TicketMailParser::STATUS_CREATED, $this->parse($payload)['status']);
        $this->assertSame(TicketMailParser::STATUS_DUPLICATE, $this->parse($payload)['status']);
        $this->assertDatabaseCount('ticket_replies', 2);
    }

    /**
     * @param  array<string, string>  $headers
     */
    #[DataProvider('automatedHeaderProvider')]
    public function test_machine_mail_is_ignored(array $headers, string $from): void
    {
        [$ticket] = $this->ticketWithCustomer();

        $result = $this->parse([
            'messageId' => 'inbound-auto@mail.example',
            'subject' => 'Re: ['.$ticket->ticket_no.'] Cannot reach my site',
            'fromEmail' => $from,
            'body' => 'I am out of the office.',
            'headers' => $headers,
        ]);

        $this->assertSame(TicketMailParser::STATUS_IGNORED, $result['status']);
        $this->assertDatabaseCount('ticket_replies', 1);
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function automatedHeaderProvider(): array
    {
        return [
            'out of office' => [['Auto-Submitted' => 'auto-replied'], 'client@example.test'],
            'bulk' => [['Precedence' => 'bulk'], 'client@example.test'],
            'mailing list' => [['List-Id' => '<news.example.test>'], 'client@example.test'],
            'bounce' => [['X-Failed-Recipients' => 'client@example.test'], 'client@example.test'],
            'mailer daemon' => [[], 'mailer-daemon@example.test'],
            'noreply' => [[], 'no-reply@example.test'],
        ];
    }

    public function test_auto_submitted_no_is_a_real_person(): void
    {
        [$ticket] = $this->ticketWithCustomer();

        $result = $this->parse([
            'messageId' => 'inbound-8@mail.example',
            'subject' => 'Re: ['.$ticket->ticket_no.'] Cannot reach my site',
            'fromEmail' => 'client@example.test',
            'body' => 'Written by hand.',
            'headers' => ['Auto-Submitted' => 'no'],
        ]);

        $this->assertSame(TicketMailParser::STATUS_CREATED, $result['status']);
    }

    public function test_a_closed_ticket_is_reopened_by_a_reply(): void
    {
        [$ticket] = $this->ticketWithCustomer();
        $ticket->update(['status' => TicketService::STATUS_CLOSED]);

        $result = $this->parse([
            'messageId' => 'inbound-9@mail.example',
            'subject' => 'Re: ['.$ticket->ticket_no.'] Cannot reach my site',
            'fromEmail' => 'client@example.test',
            'body' => 'It came back.',
        ]);

        $this->assertSame(TicketMailParser::STATUS_CREATED, $result['status']);
        $this->assertSame(TicketService::STATUS_CUSTOMER_REPLY, $ticket->fresh()->status);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        [$ticket] = $this->ticketWithCustomer();

        $result = app(TicketMailParser::class)->handle(InboundEmail::fromArray([
            'messageId' => 'inbound-10@mail.example',
            'subject' => 'Re: ['.$ticket->ticket_no.'] Cannot reach my site',
            'fromEmail' => 'client@example.test',
            'body' => 'Dry run only.',
        ]), dryRun: true);

        $this->assertSame(TicketMailParser::STATUS_WOULD_CREATE, $result['status']);
        $this->assertDatabaseCount('ticket_replies', 1);
    }

    public function test_quoted_history_is_stripped_at_our_marker(): void
    {
        $parser = app(TicketMailParser::class);

        $body = "Thanks, that worked.\n\n".TicketMailService::REPLY_MARKER."\n\nHi there,\n\nWe have fixed it.";

        $this->assertSame('Thanks, that worked.', $parser->stripQuotedText($body));
    }

    public function test_quoted_history_is_stripped_at_common_quote_openers(): void
    {
        $parser = app(TicketMailParser::class);

        $this->assertSame(
            'Still broken.',
            $parser->stripQuotedText("Still broken.\n\nOn Fri, 28 Aug 2026 at 18:00, Support <support@x.test> wrote:\n> Have you tried rebooting?")
        );

        $this->assertSame(
            'Nope.',
            $parser->stripQuotedText("Nope.\n\n-----Original Message-----\nFrom: Support\n> old text")
        );

        $this->assertSame(
            'Cheers.',
            $parser->stripQuotedText("Cheers.\n-- \nJane Doe\nCTO")
        );
    }

    /**
     * A reply typed UNDER the quoted history. The marker used to truncate the
     * body before anything else looked at it, so everything the customer wrote
     * was thrown away, the result was empty, and the message was dropped and
     * flagged Seen — the reply simply never arrived.
     */
    public function test_a_reply_typed_under_the_quoted_history_is_kept(): void
    {
        $parser = app(TicketMailParser::class);

        $body = "On Sun, 6 Sep 2026 at 12:04, Support <support@x.test> wrote:\n"
            ."\n"
            ."> Hi there,\n"
            ."> \n"
            ."> There is a new reply on your support ticket.\n"
            ."> \n"
            .'> '.TicketMailService::REPLY_MARKER."\n"
            ."\n"
            .'Yes please go ahead and do that.';

        $this->assertSame('Yes please go ahead and do that.', $parser->stripQuotedText($body));
    }

    /**
     * Outlook's shape: history under `-----Original Message-----`, the reply
     * below it. This used to file OUR OWN trailer as if the customer had
     * written it, so the ticket showed the wrong text entirely.
     */
    public function test_a_reply_below_an_original_message_block_is_kept_not_our_own_text(): void
    {
        $parser = app(TicketMailParser::class);

        $body = "-----Original Message-----\n"
            ."From: support@x.test\n"
            ."\n"
            ."Hi there, there is a new reply.\n"
            ."\n"
            .TicketMailService::REPLY_MARKER."\n"
            ."\n"
            .'Thanks, that works for me.';

        $this->assertSame('Thanks, that works for me.', $parser->stripQuotedText($body));
    }

    public function test_a_reply_interleaved_through_the_quote_is_still_kept(): void
    {
        $parser = app(TicketMailParser::class);

        $body = "On Sun, 6 Sep 2026 at 12:04, Support <support@x.test> wrote:\n"
            ."\n"
            ."> Can you confirm the domain?\n"
            ."example.com\n"
            ."\n"
            ."> And the plan?\n"
            ."Business plan please.\n"
            ."\n"
            .'> '.TicketMailService::REPLY_MARKER;

        $this->assertSame("example.com\n\nBusiness plan please.", $parser->stripQuotedText($body));
    }

    public function test_an_empty_body_after_stripping_is_not_filed(): void
    {
        [$ticket] = $this->ticketWithCustomer();

        $result = $this->parse([
            'messageId' => 'inbound-11@mail.example',
            'subject' => 'Re: ['.$ticket->ticket_no.'] Cannot reach my site',
            'fromEmail' => 'client@example.test',
            'body' => TicketMailService::REPLY_MARKER."\n\n> everything here is quoted",
        ]);

        $this->assertSame(TicketMailParser::STATUS_EMPTY, $result['status']);
    }

    public function test_guest_reply_from_guest_email_is_allowed(): void
    {
        $ticket = $this->guestTicket('guest@example.test');

        $result = $this->parse([
            'messageId' => 'guest-reply-1@mail.example',
            'subject' => 'Re: ['.$ticket->ticket_no.'] Guest question',
            'fromEmail' => 'guest@example.test',
            'body' => 'My follow-up as guest.',
        ]);

        $this->assertSame(TicketMailParser::STATUS_CREATED, $result['status']);
        $this->assertSame($ticket->id, $result['reply']->ticket_id);
        $this->assertFalse((bool) $result['reply']->is_staff);
        $this->assertSame('guest@example.test', $result['reply']->from_email);
        $this->assertSame(TicketService::STATUS_CUSTOMER_REPLY, $ticket->fresh()->status);
    }

    public function test_guest_reply_from_other_email_is_rejected(): void
    {
        $ticket = $this->guestTicket('guest@example.test');

        $result = $this->parse([
            'messageId' => 'guest-reply-2@mail.example',
            'subject' => 'Re: ['.$ticket->ticket_no.'] Guest question',
            'fromEmail' => 'other@example.test',
            'body' => 'Trying to hijack.',
        ]);

        $this->assertSame(TicketMailParser::STATUS_UNKNOWN_SENDER, $result['status']);
        $this->assertDatabaseCount('ticket_replies', 1);
    }

    public function test_guest_reply_matching_is_case_insensitive(): void
    {
        $ticket = $this->guestTicket('Guest@Example.Test');

        $result = $this->parse([
            'messageId' => 'guest-reply-3@mail.example',
            'subject' => 'Re: ['.$ticket->ticket_no.'] Guest question',
            'fromEmail' => 'guest@example.test',
            'body' => 'Case check.',
        ]);

        $this->assertSame(TicketMailParser::STATUS_CREATED, $result['status']);
    }

    public function test_guest_reply_without_user_row_still_lands(): void
    {
        $ticket = $this->guestTicket('noguestuser@example.test');
        $this->assertDatabaseMissing('users', ['email' => 'noguestuser@example.test']);

        $result = $this->parse([
            'messageId' => 'guest-reply-4@mail.example',
            'subject' => 'Re: ['.$ticket->ticket_no.'] Guest question',
            'fromEmail' => 'noguestuser@example.test',
            'body' => 'No user row.',
        ]);

        $this->assertSame(TicketMailParser::STATUS_CREATED, $result['status']);
        $this->assertNull($result['reply']->user_id);
        $this->assertFalse((bool) $result['reply']->is_staff);
    }

    private function enableAutoCreate(): void
    {
        $s = app(EmailSettings::class);
        $s->fill(['imap_auto_create_customers' => true]);
        $s->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  string|null  $mailboxDepartment  department whose mailbox the mail arrived in
     * @return array{status: string, reason: string, reply: ?TicketReply, ticket: ?Ticket}
     */
    private function parse(array $payload, ?string $mailboxDepartment = null): array
    {
        return app(TicketMailParser::class)->handle(
            InboundEmail::fromArray($payload),
            false,
            $mailboxDepartment
        );
    }

    /**
     * @return array{0: Ticket, 1: Customer}
     */
    private function ticketWithCustomer(): array
    {
        $user = User::factory()->create(['email' => 'client@example.test', 'role' => 'client']);
        $customer = Customer::create(['user_id' => $user->id, 'status' => 'active']);

        $ticket = Ticket::create([
            'ticket_no' => 'TKT-00042',
            'customer_id' => $customer->id,
            'subject' => 'Cannot reach my site',
            'priority' => 'high',
            'status' => 'open',
            'department' => 'support',
            'last_reply_at' => now(),
        ]);

        TicketReply::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'message' => 'It has been down since noon.',
            'is_staff' => false,
        ]);

        return [$ticket, $customer];
    }

    private function guestTicket(string $guestEmail = 'guest@example.test'): Ticket
    {
        $ticket = Ticket::create([
            'ticket_no' => 'TKT-'.str_pad((string) random_int(10000, 99999), 5, '0', STR_PAD_LEFT),
            'customer_id' => null,
            'guest_email' => $guestEmail,
            'guest_name' => 'Guest User',
            'subject' => 'Guest question',
            'priority' => 'medium',
            'status' => 'open',
            'department' => 'support',
            'last_reply_at' => now(),
        ]);

        TicketReply::create([
            'ticket_id' => $ticket->id,
            'user_id' => null,
            'message' => 'Opening guest message.',
            'is_staff' => false,
            'from_email' => $guestEmail,
        ]);

        return $ticket->fresh();
    }

    /**
     * A staff reply that already went out by email, carrying the Message-ID an
     * inbound reply will point at.
     */
    private function outboundReply(Ticket $ticket, string $messageId): TicketReply
    {
        $staff = User::factory()->create(['role' => 'admin']);

        return TicketReply::create([
            'ticket_id' => $ticket->id,
            'user_id' => $staff->id,
            'message' => 'We have fixed it.',
            'is_staff' => true,
            'email_message_id' => $messageId,
        ]);
    }
}
