<?php

namespace Tests\Feature;

use App\Jobs\SendEmail;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\NotificationPreferenceService;
use App\Services\TicketMailService;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Outbound ticket email: the `[TKT-…]` subject tag and the Message-ID /
 * In-Reply-To trail that inbound replies are matched against.
 */
class TicketMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_reply_emails_the_customer_with_the_tag_and_a_message_id(): void
    {
        Queue::fake();

        $customer = $this->customerWithEmail('client@example.test');
        $ticket = $this->ticketFor($customer);

        $reply = app(TicketService::class)->reply($ticket, $this->staff(), 'We have fixed it.');

        Queue::assertPushed(SendEmail::class, function (SendEmail $job) use ($ticket, $reply) {
            return $job->toEmail === 'client@example.test'
                && $job->subject === '['.$ticket->ticket_no.'] '.$ticket->subject
                && $job->headers['messageId'] === $reply->fresh()->email_message_id
                && str_contains($job->body, 'We have fixed it.')
                && str_contains($job->body, TicketMailService::REPLY_MARKER);
        });

        $this->assertNotNull($reply->fresh()->email_message_id);
    }

    public function test_a_staff_reply_is_addressed_to_the_actual_inbound_sender_not_the_account_email(): void
    {
        Queue::fake();

        // The account's login email differs from the address the customer
        // actually wrote in from (a personal inbox, a secondary contact, etc).
        $customer = $this->customerWithEmail('account@example.test');
        $ticket = $this->ticketFor($customer);
        $ticket->replies()->where('is_staff', false)->first()->forceFill([
            'from_email' => 'personal-inbox@example.test',
        ])->save();

        app(TicketService::class)->reply($ticket->fresh(), $this->staff(), 'We have fixed it.');

        Queue::assertPushed(SendEmail::class, fn (SendEmail $job) => $job->toEmail === 'personal-inbox@example.test');
        Queue::assertNotPushed(SendEmail::class, fn (SendEmail $job) => $job->toEmail === 'account@example.test');
    }

    public function test_a_later_reply_threads_onto_the_previous_message_id(): void
    {
        Queue::fake();

        $customer = $this->customerWithEmail('client@example.test');
        $ticket = $this->ticketFor($customer);
        $staff = $this->staff();

        $first = app(TicketService::class)->reply($ticket, $staff, 'First answer.');
        $second = app(TicketService::class)->reply($ticket->fresh(), $staff, 'Second answer.');

        $firstId = $first->fresh()->email_message_id;
        $secondId = $second->fresh()->email_message_id;

        $this->assertNotSame($firstId, $secondId);

        Queue::assertPushed(SendEmail::class, function (SendEmail $job) use ($firstId, $secondId) {
            return ($job->headers['messageId'] ?? null) === $secondId
                && ($job->headers['inReplyTo'] ?? null) === $firstId
                && in_array($firstId, (array) ($job->headers['references'] ?? []), true);
        });
    }

    public function test_a_customer_reply_does_not_email_the_customer_back(): void
    {
        Queue::fake();

        $customer = $this->customerWithEmail('client@example.test');
        $ticket = $this->ticketFor($customer);

        app(TicketService::class)->reply($ticket, $customer->user, 'Any news?');

        Queue::assertNotPushed(SendEmail::class);
    }

    public function test_a_disabled_preference_suppresses_the_ticket_email(): void
    {
        Queue::fake();

        $customer = $this->customerWithEmail('client@example.test');
        app(NotificationPreferenceService::class)->setPreference($customer, 'ticket.reply', false);
        $ticket = $this->ticketFor($customer);

        app(TicketService::class)->reply($ticket, $this->staff(), 'We have fixed it.');

        Queue::assertNotPushed(SendEmail::class);
    }

    public function test_opening_a_ticket_emails_the_customer(): void
    {
        Queue::fake();

        $customer = $this->customerWithEmail('client@example.test');

        $ticket = app(TicketService::class)->create([
            'customer_id' => $customer->id,
            'subject' => 'Cannot reach my site',
            'department' => 'support',
            'priority' => 'high',
        ], 'It has been down since noon.');

        Queue::assertPushed(SendEmail::class, fn (SendEmail $job) => $job->toEmail === 'client@example.test'
            && str_starts_with($job->subject, '['.$ticket->ticket_no.'] '));

        $this->assertNotNull($ticket->replies()->orderBy('id')->first()->email_message_id);
    }

    public function test_a_customer_without_an_email_is_skipped_without_error(): void
    {
        Queue::fake();

        // customers.user_id is NOT NULL, so the unreachable case is a linked
        // user carrying no address rather than a customer with no user.
        $customer = $this->customerWithEmail('');
        $ticket = $this->ticketFor($customer);

        app(TicketService::class)->reply($ticket, $this->staff(), 'We have fixed it.');

        Queue::assertNotPushed(SendEmail::class);
    }

    public function test_opening_a_guest_ticket_emails_the_guest_address(): void
    {
        Queue::fake();

        $ticket = app(TicketService::class)->create([
            'guest_email' => 'guest@example.test',
            'guest_name' => 'Guest User',
            'subject' => 'Cannot reach my site',
            'department' => 'support',
            'priority' => 'high',
        ], 'It has been down since noon.');

        $this->assertTrue($ticket->isGuest());

        Queue::assertPushed(SendEmail::class, fn (SendEmail $job) => $job->toEmail === 'guest@example.test'
            && str_starts_with($job->subject, '['.$ticket->ticket_no.'] '));
    }

    public function test_a_guest_ticket_reply_is_emailed_to_the_guest_address(): void
    {
        Queue::fake();

        $ticket = app(TicketService::class)->create([
            'guest_email' => 'guest@example.test',
            'guest_name' => 'Guest User',
            'subject' => 'Cannot reach my site',
            'department' => 'support',
            'priority' => 'high',
        ], 'It has been down since noon.');

        app(TicketService::class)->reply($ticket->fresh(), $this->staff(), 'We have fixed it.');

        Queue::assertPushed(SendEmail::class, fn (SendEmail $job) => $job->toEmail === 'guest@example.test'
            && str_contains($job->body, 'We have fixed it.'));
    }

    public function test_ticket_numbers_are_parsed_out_of_a_subject(): void
    {
        $this->assertSame(
            ['TKT-00042'],
            TicketMailService::ticketNosFromSubject('Re: [TKT-00042] Cannot reach my site')
        );

        // Prefix-agnostic: ticket_prefix is an admin setting.
        $this->assertSame(['SUP/9001'], TicketMailService::ticketNosFromSubject('Fwd: [SUP/9001] hello'));
        $this->assertSame([], TicketMailService::ticketNosFromSubject('No tag at all'));
    }

    public function test_the_subject_is_not_tagged_twice(): void
    {
        $customer = $this->customerWithEmail('client@example.test');
        $ticket = $this->ticketFor($customer);
        $ticket->update(['subject' => '['.$ticket->ticket_no.'] Already tagged']);

        $this->assertSame(
            '['.$ticket->ticket_no.'] Already tagged',
            app(TicketMailService::class)->taggedSubject($ticket->fresh())
        );
    }

    public function test_original_source_for_returns_the_captured_raw_source_when_present(): void
    {
        Queue::fake();

        $customer = $this->customerWithEmail('client@example.test');
        $ticket = $this->ticketFor($customer);
        $reply = $ticket->replies()->first();
        $reply->forceFill(['raw_source' => "From: client@example.test\r\nSubject: hi\r\n\r\nBody."])->save();

        $original = app(TicketMailService::class)->originalSourceFor($ticket, $reply->fresh());

        $this->assertNotNull($original);
        $this->assertTrue($original['isRaw']);
        $this->assertStringContainsString('From: client@example.test', $original['source']);
    }

    public function test_original_source_for_reconstructs_a_staff_reply_with_no_raw_source(): void
    {
        Queue::fake();

        $customer = $this->customerWithEmail('client@example.test');
        $ticket = $this->ticketFor($customer);
        $reply = app(TicketService::class)->reply($ticket, $this->staff(), 'We have fixed it.', [
            'cc' => ['manager@example.test'],
        ]);

        $original = app(TicketMailService::class)->originalSourceFor($ticket, $reply->fresh());

        $this->assertNotNull($original);
        $this->assertFalse($original['isRaw']);
        $this->assertStringContainsString('To: client@example.test', $original['source']);
        $this->assertStringContainsString('Cc: manager@example.test', $original['source']);
        $this->assertStringContainsString('We have fixed it.', $original['source']);
    }

    public function test_original_source_for_returns_null_for_a_portal_reply_with_no_raw_source(): void
    {
        Queue::fake();

        $customer = $this->customerWithEmail('client@example.test');
        $ticket = $this->ticketFor($customer);
        $reply = app(TicketService::class)->reply($ticket, $customer->user, 'Any update?');

        $original = app(TicketMailService::class)->originalSourceFor($ticket, $reply->fresh());

        $this->assertNull($original);
    }

    public function test_the_send_email_job_puts_the_ids_on_the_wire(): void
    {
        config(['mail.default' => 'array']);

        (new SendEmail('client@example.test', 'Subject', 'Body', null, [
            'messageId' => 'ticket-1-abc@example.test',
            'inReplyTo' => 'ticket-1-previous@example.test',
            'references' => ['ticket-1-first@example.test', 'ticket-1-previous@example.test'],
            'replyTo' => 'support@example.test',
        ]))->handle();

        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);

        $headers = $messages[0]->getOriginalMessage()->getHeaders();

        $this->assertSame('<ticket-1-abc@example.test>', $headers->get('Message-ID')->getBodyAsString());
        $this->assertSame('<ticket-1-previous@example.test>', $headers->get('In-Reply-To')->getBodyAsString());
        $this->assertStringContainsString('<ticket-1-first@example.test>', $headers->get('References')->getBodyAsString());
        $this->assertSame('support@example.test', $headers->get('Reply-To')->getBodyAsString());
    }

    public function test_the_send_email_job_sends_an_html_body_with_cc_bcc_and_a_plaintext_alternative(): void
    {
        config(['mail.default' => 'array']);

        (new SendEmail(
            'client@example.test',
            'Subject',
            'Plain text alternative',
            null,
            [],
            ['manager@example.test'],
            ['audit@example.test'],
            '<p>Rich body</p>'
        ))->handle();

        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);

        $email = $messages[0]->getOriginalMessage();

        $this->assertSame('<p>Rich body</p>', $email->getHtmlBody());
        $this->assertSame('Plain text alternative', $email->getTextBody());
        $this->assertSame('manager@example.test', $email->getCc()[0]->getAddress());
        $this->assertSame('audit@example.test', $email->getBcc()[0]->getAddress());
    }

    public function test_the_send_email_job_attaches_a_file_from_disk(): void
    {
        config(['mail.default' => 'array']);
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::disk('local')->put('ticket-attachments/1/1/0-screenshot.png', 'fake-bytes');

        (new SendEmail(
            'client@example.test',
            'Subject',
            'Body',
            null,
            [],
            [],
            [],
            null,
            [[
                'disk' => 'local',
                'path' => 'ticket-attachments/1/1/0-screenshot.png',
                'filename' => 'screenshot.png',
                'mimeType' => 'image/png',
                'isInline' => false,
                'contentId' => null,
            ]]
        ))->handle();

        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);

        $attachments = $messages[0]->getOriginalMessage()->getAttachments();
        $this->assertCount(1, $attachments);
        $this->assertSame('screenshot.png', $attachments[0]->getPreparedHeaders()->getHeaderParameter('Content-Disposition', 'filename'));
    }

    public function test_a_plain_reply_with_no_html_body_or_attachments_still_uses_the_raw_path(): void
    {
        Queue::fake();

        $customer = $this->customerWithEmail('client@example.test');
        $ticket = $this->ticketFor($customer);

        app(TicketService::class)->reply($ticket, $this->staff(), 'We have fixed it.');

        Queue::assertPushed(SendEmail::class, fn (SendEmail $job) => $job->htmlBody === null && $job->attachments === [] && $job->cc === [] && $job->bcc === []);
    }

    public function test_a_staff_reply_with_html_body_cc_bcc_and_attachments_carries_them_through(): void
    {
        Queue::fake();
        \Illuminate\Support\Facades\Storage::fake('local');

        $customer = $this->customerWithEmail('client@example.test');
        $ticket = $this->ticketFor($customer);

        app(TicketService::class)->reply($ticket, $this->staff(), 'We have fixed it.', [
            'to' => ['client@example.test'],
            'cc' => ['manager@example.test'],
            'bcc' => ['audit@example.test'],
            'html_body' => '<p>We have fixed it.</p>',
        ], [
            \Illuminate\Http\UploadedFile::fake()->image('screenshot.png'),
        ]);

        Queue::assertPushed(SendEmail::class, function (SendEmail $job) {
            return $job->cc === ['manager@example.test']
                && $job->bcc === ['audit@example.test']
                && str_contains((string) $job->htmlBody, '<p>We have fixed it.</p>')
                // The HTML part carries the same trailer as the text part.
                // Without the marker, a client quoting the HTML sends back a
                // reply that stripQuotedText() has nothing to cut against and
                // the whole thread re-accumulates on every round trip.
                && str_contains((string) $job->htmlBody, TicketMailService::REPLY_MARKER)
                && count($job->attachments) === 1
                && $job->attachments[0]['filename'] === 'screenshot.png';
        });
    }

    private function customerWithEmail(string $email): Customer
    {
        $user = User::factory()->create(['email' => $email, 'role' => 'client']);

        return Customer::create(['user_id' => $user->id, 'status' => 'active']);
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function ticketFor(Customer $customer): Ticket
    {
        // Built directly rather than through TicketService::create so the
        // creation email does not muddy assertions about the reply email.
        $ticket = Ticket::create([
            'ticket_no' => 'TKT-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'customer_id' => $customer->id,
            'subject' => 'Cannot reach my site',
            'priority' => 'high',
            'status' => 'open',
            'department' => 'support',
            'last_reply_at' => now(),
        ]);

        TicketReply::create([
            'ticket_id' => $ticket->id,
            'user_id' => $customer->user_id ?? User::factory()->create()->id,
            'message' => 'It has been down since noon.',
            'is_staff' => false,
        ]);

        return $ticket;
    }
}
