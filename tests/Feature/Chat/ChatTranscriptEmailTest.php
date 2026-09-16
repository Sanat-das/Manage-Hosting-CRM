<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Jobs\SendEmail;
use App\Models\ChatConversation;
use App\Models\ChatSetting;
use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Services\ChatService;
use App\Services\ChatTranscriptEmailService;
use App\Services\TicketService;
use App\Support\ChatMessagePayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesChatUsers;
use Tests\TestCase;

/**
 * The transcript email sent when a customer conversation is closed.
 *
 * Two things are being tested, and the second matters more: that it sends at
 * all, and that what it contains is only what the customer already saw.
 */
class ChatTranscriptEmailTest extends TestCase
{
    use CreatesChatUsers;
    use RefreshDatabase;

    private ChatService $chat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chat = app(ChatService::class);
        TicketDepartment::firstOrCreate(['slug' => 'support'], ['name' => 'Support', 'enabled' => true]);
        TicketService::forgetDepartmentCache();
    }

    private function enableTranscripts(): void
    {
        ChatSetting::current()->update(['send_transcript_on_close' => true]);
    }

    private function guestConversation(string $body = 'My site is down'): ChatConversation
    {
        return $this->chat->startCustomerChat(
            ['name' => 'Jo Visitor', 'email' => 'jo@example.com'],
            'support',
            $body,
        );
    }

    // --- the switch ----------------------------------------------------------

    public function test_nothing_is_sent_until_an_admin_turns_transcripts_on(): void
    {
        // This ships to live installs. Closing a chat must not start emailing
        // customers the moment the migration runs.
        Queue::fake();

        $this->chat->closeConversation($this->guestConversation());

        Queue::assertNotPushed(SendEmail::class);
    }

    public function test_closing_a_conversation_queues_the_transcript(): void
    {
        Queue::fake();
        $this->enableTranscripts();

        $this->chat->closeConversation($this->guestConversation());

        Queue::assertPushed(SendEmail::class, fn (SendEmail $job) => $this->recipientOf($job) === 'jo@example.com');
    }

    public function test_a_staff_channel_is_never_emailed_to_anyone(): void
    {
        Queue::fake();
        $this->enableTranscripts();

        $channel = $this->chat->createChannel('general', $this->chatUser('chat.view'));
        $this->chat->closeConversation($channel);

        Queue::assertNotPushed(SendEmail::class);
    }

    public function test_a_conversation_with_no_address_is_skipped_rather_than_failing(): void
    {
        Queue::fake();
        $this->enableTranscripts();

        $conversation = $this->guestConversation();
        $conversation->forceFill(['guest_email' => null])->save();

        $this->chat->closeConversation($conversation->fresh());

        Queue::assertNotPushed(SendEmail::class);
    }

    public function test_a_missing_template_does_not_break_closing_a_chat(): void
    {
        Queue::fake();
        $this->enableTranscripts();
        EmailTemplate::where('name', ChatTranscriptEmailService::TEMPLATE)->delete();

        $conversation = $this->guestConversation();
        $this->chat->closeConversation($conversation);

        // Closed, just not emailed. An operator clicking Close must not be
        // told the close failed because a template row is missing.
        $this->assertSame(ChatConversation::STATUS_CLOSED, $conversation->fresh()->status);
        Queue::assertNotPushed(SendEmail::class);
    }

    public function test_an_inactive_template_is_treated_as_off(): void
    {
        Queue::fake();
        $this->enableTranscripts();
        EmailTemplate::where('name', ChatTranscriptEmailService::TEMPLATE)->update(['status' => 'inactive']);

        $this->chat->closeConversation($this->guestConversation());

        Queue::assertNotPushed(SendEmail::class);
    }

    public function test_closing_through_the_operator_endpoint_sends_it_too(): void
    {
        Queue::fake();
        $this->enableTranscripts();

        $conversation = $this->guestConversation();
        $operator = $this->chatUser('chat.view', 'chat.manage');

        $this->actingAs($operator)
            ->postJson(route('admin.chat.inbox.close', ['conversation' => $conversation->id]))
            ->assertOk();

        Queue::assertPushed(SendEmail::class);
    }

    // --- what it contains ----------------------------------------------------

    public function test_the_operators_real_name_never_reaches_the_customer(): void
    {
        $this->enableTranscripts();

        $conversation = $this->guestConversation();
        $operator = $this->chatUser('chat.view', 'chat.manage');
        $operator->update(['first_name' => 'Priya', 'last_name' => 'Nair']);

        $this->chat->assignOperator($conversation, $operator);
        $this->chat->sendMessage($conversation->fresh(), $operator, 'Rebooting your server now.');

        $variables = $this->variablesFor($conversation->fresh());

        $this->assertStringContainsString(ChatMessagePayload::OPERATOR_LABEL, $variables['transcript_text']);
        $this->assertStringNotContainsString('Priya', $variables['transcript_text']);
        $this->assertStringNotContainsString('Priya', $variables['transcript_html']);
    }

    public function test_a_signed_in_customer_keeps_their_own_name_on_their_own_words(): void
    {
        // A guest has no user_id, but a signed-in customer posts under their
        // own. Labelling on "has a user id" alone would print "Support" over
        // the customer's own messages in their own transcript.
        $this->enableTranscripts();

        $user = User::factory()->create(['role' => 'client', 'first_name' => 'Sam', 'last_name' => 'Client']);
        $customer = Customer::create(['user_id' => $user->id, 'status' => 'active']);

        $conversation = $this->chat->startCustomerChat(
            ['customer_id' => $customer->id],
            'support',
            'Where is my invoice?',
        );

        $variables = $this->variablesFor($conversation->fresh());

        $this->assertStringContainsString('Sam Client: Where is my invoice?', $variables['transcript_text']);
    }

    public function test_staff_thread_replies_are_not_disclosed(): void
    {
        // The widget only ever renders top-level messages, so a thread reply is
        // a conversation the customer never saw. Emailing it now would be a
        // disclosure, not a record.
        $this->enableTranscripts();

        $conversation = $this->guestConversation();
        $operator = $this->chatUser('chat.view', 'chat.manage');
        $this->chat->addMember($conversation, $operator);

        $first = $conversation->messages()->orderBy('id')->firstOrFail();
        $this->chat->sendMessage($conversation, $operator, 'Internal: check their billing first', $first->id);

        $variables = $this->variablesFor($conversation->fresh());

        $this->assertStringNotContainsString('check their billing first', $variables['transcript_text']);
        $this->assertStringNotContainsString('check their billing first', $variables['transcript_html']);
    }

    public function test_a_retracted_message_stays_retracted(): void
    {
        $this->enableTranscripts();

        $conversation = $this->guestConversation();
        $operator = $this->chatUser('chat.view', 'chat.manage');
        $this->chat->addMember($conversation, $operator);

        $mistake = $this->chat->sendMessage($conversation, $operator, 'Wrong customer, ignore this');
        $this->chat->deleteMessage($mistake);

        $variables = $this->variablesFor($conversation->fresh());

        $this->assertStringNotContainsString('Wrong customer', $variables['transcript_text']);
    }

    public function test_the_message_body_is_escaped_on_the_way_into_the_html(): void
    {
        // This is the one email in the app that quotes customer input back.
        $this->enableTranscripts();

        $conversation = $this->guestConversation('<script>alert(1)</script>');

        $variables = $this->variablesFor($conversation->fresh());

        $this->assertStringNotContainsString('<script>', $variables['transcript_html']);
        $this->assertStringContainsString('&lt;script&gt;', $variables['transcript_html']);
    }

    public function test_the_branding_variables_are_present_so_no_placeholder_is_emailed_raw(): void
    {
        // The order emails shipped with a three-variable map once, and mailed
        // customers a literal {{app_logo_url}}.
        $this->enableTranscripts();

        $variables = $this->variablesFor($this->guestConversation()->fresh());

        foreach (['app_name', 'app_logo_url', 'company_name', 'customer_name', 'chat_date', 'chat_message_count'] as $key) {
            $this->assertArrayHasKey($key, $variables);
        }
    }

    /**
     * @return array<string, string>
     */
    private function variablesFor(ChatConversation $conversation): array
    {
        $service = app(ChatTranscriptEmailService::class);

        $messages = $conversation->messages()
            ->whereNull('parent_id')
            ->with('user')
            ->orderBy('id')
            ->get();

        return $service->buildVariables($conversation, $messages);
    }

    private function recipientOf(SendEmail $job): string
    {
        return (string) $job->toEmail;
    }
}
