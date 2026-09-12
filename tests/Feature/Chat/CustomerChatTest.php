<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\ChatConversation;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Services\ChatService;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\Concerns\CreatesChatUsers;
use Tests\TestCase;

/**
 * The customer inbox: guests, the operator queue, transfers, closing, rating,
 * and conversion to a ticket.
 */
class CustomerChatTest extends TestCase
{
    use CreatesChatUsers;
    use RefreshDatabase;

    private ChatService $chat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chat = app(ChatService::class);

        // firstOrCreate: a `support` department already exists in the baseline
        // this suite boots with.
        TicketDepartment::firstOrCreate(['slug' => 'support'], ['name' => 'Support', 'enabled' => true]);
        TicketDepartment::firstOrCreate(['slug' => 'billing'], ['name' => 'Billing', 'enabled' => true]);
        TicketService::forgetDepartmentCache();
    }

    public function test_a_guest_can_open_a_conversation_and_gets_a_token(): void
    {
        $conversation = $this->chat->startCustomerChat(
            ['name' => 'Jo Visitor', 'email' => 'jo@example.com'],
            'support',
            'My site is down',
        );

        $this->assertSame(ChatConversation::TYPE_CUSTOMER_INBOX, $conversation->type);
        $this->assertSame(ChatConversation::STATUS_WAITING, $conversation->status);
        $this->assertNull($conversation->assigned_operator_id, 'A new conversation is queued, not assigned.');
        $this->assertSame(40, strlen((string) $conversation->guest_token));
        $this->assertSame('jo@example.com', $conversation->guest_email);

        $participant = $conversation->participants()->firstOrFail();
        $this->assertNull($participant->user_id);
        $this->assertSame($conversation->guest_token, $participant->guest_token);

        $message = $conversation->messages()->firstOrFail();
        $this->assertSame('My site is down', $message->body);
        $this->assertSame($conversation->guest_token, $message->guest_token);
        $this->assertNull($message->user_id);
    }

    public function test_a_signed_in_customer_opens_a_conversation_against_their_account(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $customer = Customer::create(['user_id' => $user->id, 'status' => 'active']);

        $conversation = $this->chat->startCustomerChat(
            ['customer_id' => $customer->id],
            'support',
            'Invoice question',
        );

        $this->assertSame($customer->id, $conversation->customer_id);
        $this->assertNull($conversation->guest_email);
        $this->assertSame($user->id, $conversation->participants()->value('user_id'));
        $this->assertSame($user->id, $conversation->messages()->value('user_id'));
    }

    public function test_a_conversation_needs_a_customer_or_an_email(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->chat->startCustomerChat(['name' => 'Anonymous'], 'support', 'Hello?');
    }

    public function test_an_unknown_department_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->chat->startCustomerChat(
            ['email' => 'jo@example.com'],
            'no-such-department',
            'Hello?',
        );
    }

    public function test_assigning_an_operator_activates_and_enrols_them(): void
    {
        $conversation = $this->chat->startCustomerChat(['email' => 'jo@example.com'], 'support', 'Help');
        $operator = $this->chatUser('chat.view', 'chat.manage');

        $this->chat->assignOperator($conversation, $operator);

        $conversation->refresh();
        $this->assertSame($operator->id, $conversation->assigned_operator_id);
        $this->assertSame(ChatConversation::STATUS_ACTIVE, $conversation->status);
        $this->assertTrue($conversation->participants()->where('user_id', $operator->id)->exists());
    }

    public function test_transfer_requeues_and_keeps_the_previous_operator_as_a_reader(): void
    {
        $conversation = $this->chat->startCustomerChat(['email' => 'jo@example.com'], 'support', 'Help');
        $operator = $this->chatUser('chat.view', 'chat.manage');
        $this->chat->assignOperator($conversation, $operator);

        $this->chat->transferConversation($conversation, 'billing');

        $conversation->refresh();
        $this->assertSame('billing', $conversation->department);
        $this->assertNull($conversation->assigned_operator_id);
        $this->assertSame(ChatConversation::STATUS_WAITING, $conversation->status);
        $this->assertTrue(
            $conversation->participants()->where('user_id', $operator->id)->exists(),
            'A transfer is a handover, not a disappearance - the previous operator can still read it.',
        );
    }

    public function test_a_closed_conversation_cannot_be_assigned_or_transferred(): void
    {
        $conversation = $this->chat->startCustomerChat(['email' => 'jo@example.com'], 'support', 'Help');
        $this->chat->closeConversation($conversation);

        try {
            $this->chat->assignOperator($conversation->fresh(), $this->chatUser('chat.manage'));
            $this->fail('A closed conversation was assigned.');
        } catch (RuntimeException) {
            // expected
        }

        $this->expectException(RuntimeException::class);
        $this->chat->transferConversation($conversation->fresh(), 'billing');
    }

    public function test_rating_requires_a_closed_conversation_and_a_sane_value(): void
    {
        $conversation = $this->chat->startCustomerChat(['email' => 'jo@example.com'], 'support', 'Help');

        try {
            $this->chat->rateConversation($conversation, 5);
            $this->fail('An open conversation was rated.');
        } catch (RuntimeException) {
            // expected
        }

        $this->chat->closeConversation($conversation);

        try {
            $this->chat->rateConversation($conversation->fresh(), 9);
            $this->fail('An out-of-range rating was accepted.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->chat->rateConversation($conversation->fresh(), 5);

        $this->assertSame(5, $conversation->fresh()->rating);
        $this->assertNotNull($conversation->fresh()->closed_at);
    }

    public function test_converting_produces_a_ticket_carrying_the_transcript(): void
    {
        $conversation = $this->chat->startCustomerChat(
            ['name' => 'Jo Visitor', 'email' => 'jo@example.com'],
            'support',
            'My site is down and I need help urgently',
        );

        $operator = $this->chatUser('chat.view', 'chat.manage');
        $this->chat->assignOperator($conversation, $operator);
        $this->chat->sendMessage($conversation->fresh(), $operator, 'Looking into it now.');

        $ticket = $this->chat->convertToTicket($conversation->fresh(), app(TicketService::class));

        $this->assertInstanceOf(Ticket::class, $ticket);
        $this->assertSame('jo@example.com', $ticket->guest_email);
        $this->assertSame('support', $ticket->department);
        $this->assertSame($operator->id, $ticket->assigned_to);
        $this->assertStringContainsString('My site is down', $ticket->subject);

        $transcript = $ticket->replies()->value('message');
        $this->assertStringContainsString('My site is down', $transcript);
        $this->assertStringContainsString('Looking into it now.', $transcript);

        // The chat is referenced to the ticket but its own lifecycle is untouched.
        $this->assertDatabaseHas('message_entity_links', [
            'linkable_type' => Ticket::class,
            'linkable_id' => $ticket->id,
        ]);
        $this->assertSame(
            ChatConversation::STATUS_ACTIVE,
            $conversation->fresh()->status,
            'Converting must not close the chat - that is the operator\'s decision.',
        );
    }

    public function test_an_empty_conversation_cannot_be_converted(): void
    {
        $conversation = ChatConversation::factory()->customerInbox()->create();

        $this->expectException(RuntimeException::class);

        $this->chat->convertToTicket($conversation, app(TicketService::class));
    }

    public function test_a_staff_channel_cannot_be_converted(): void
    {
        $owner = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $owner);

        $this->expectException(InvalidArgumentException::class);

        $this->chat->convertToTicket($channel, app(TicketService::class));
    }

    // --- operator endpoints ----------------------------------------------

    public function test_the_inbox_is_only_visible_to_operators(): void
    {
        $this->chat->startCustomerChat(['email' => 'jo@example.com'], 'support', 'Help');

        $this->actingAs($this->chatUser('chat.view'))
            ->getJson(route('admin.chat.inbox'))
            ->assertForbidden();

        $this->actingAs($this->chatUser('chat.view', 'chat.manage'))
            ->getJson(route('admin.chat.inbox'))
            ->assertOk()
            ->assertJsonCount(1, 'conversations')
            ->assertJsonPath('conversations.0.status', ChatConversation::STATUS_WAITING);
    }

    public function test_operator_endpoints_refuse_ordinary_staff(): void
    {
        $conversation = $this->chat->startCustomerChat(['email' => 'jo@example.com'], 'support', 'Help');
        $staff = $this->chatUser('chat.view');

        $this->actingAs($staff)->postJson(route('admin.chat.inbox.assign', $conversation))->assertForbidden();
        $this->actingAs($staff)->postJson(route('admin.chat.inbox.close', $conversation))->assertForbidden();
        $this->actingAs($staff)->postJson(route('admin.chat.inbox.convert', $conversation))->assertForbidden();
        $this->actingAs($staff)
            ->postJson(route('admin.chat.inbox.transfer', $conversation), ['department' => 'billing'])
            ->assertForbidden();
    }

    public function test_an_operator_can_take_transfer_close_and_convert(): void
    {
        $conversation = $this->chat->startCustomerChat(['email' => 'jo@example.com'], 'support', 'Help me please');
        $operator = $this->chatUser('chat.view', 'chat.manage');

        $this->actingAs($operator)
            ->postJson(route('admin.chat.inbox.assign', $conversation))
            ->assertOk()
            ->assertJsonPath('conversation.status', ChatConversation::STATUS_ACTIVE)
            ->assertJsonPath('conversation.operator.id', $operator->id);

        $this->actingAs($operator)
            ->postJson(route('admin.chat.inbox.transfer', $conversation), ['department' => 'billing'])
            ->assertOk()
            ->assertJsonPath('conversation.department', 'billing');

        $this->actingAs($operator)
            ->postJson(route('admin.chat.inbox.convert', $conversation))
            ->assertCreated()
            ->assertJsonStructure(['ticket' => ['id', 'ticket_no', 'url']]);

        $this->actingAs($operator)
            ->postJson(route('admin.chat.inbox.close', $conversation))
            ->assertOk()
            ->assertJsonPath('conversation.status', ChatConversation::STATUS_CLOSED);
    }

    // --- guest channel authorisation --------------------------------------

    /**
     * Guest authorisation hands the signing to the configured broadcaster, and
     * the log driver signs nothing, so these tests need reverb selected.
     */
    private function useReverbDriver(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.app_id' => 'test-app',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
        ]);
    }

    public function test_a_guest_token_authorises_only_its_own_conversation(): void
    {
        $mine = $this->chat->startCustomerChat(['email' => 'jo@example.com'], 'support', 'Help');
        $theirs = $this->chat->startCustomerChat(['email' => 'sam@example.com'], 'support', 'Also help');

        // Switched on only AFTER the fixtures exist: chat events broadcast
        // synchronously, so creating a conversation under the reverb driver
        // would try to publish to a Reverb server that is not running here.
        $this->useReverbDriver();

        $this->postJson(route('chat.guest-auth'), [
            'channel_name' => 'private-chat.conversation.'.$mine->id,
            'socket_id' => '1234.5678',
            'token' => $mine->guest_token,
        ])->assertOk();

        // Someone else's conversation, with a valid token of my own.
        $this->postJson(route('chat.guest-auth'), [
            'channel_name' => 'private-chat.conversation.'.$theirs->id,
            'socket_id' => '1234.5678',
            'token' => $mine->guest_token,
        ])->assertForbidden();
    }

    public function test_a_guest_token_cannot_reach_a_staff_channel(): void
    {
        $owner = $this->chatUser('chat.view', 'chat.create_channel');
        $staffChannel = $this->chat->createChannel('Ops', $owner);
        $mine = $this->chat->startCustomerChat(['email' => 'jo@example.com'], 'support', 'Help');

        $this->useReverbDriver();

        foreach ([
            'private-chat.conversation.'.$staffChannel->id,
            'presence-chat.presence',
            'presence-chat.typing.'.$staffChannel->id,
            'private-App.Models.User.1',
        ] as $channel) {
            $this->postJson(route('chat.guest-auth'), [
                'channel_name' => $channel,
                'socket_id' => '1234.5678',
                'token' => $mine->guest_token,
            ])->assertForbidden();
        }
    }

    public function test_guest_auth_rejects_a_wrong_or_missing_token(): void
    {
        $mine = $this->chat->startCustomerChat(['email' => 'jo@example.com'], 'support', 'Help');

        $this->postJson(route('chat.guest-auth'), [
            'channel_name' => 'private-chat.conversation.'.$mine->id,
            'socket_id' => '1234.5678',
            'token' => str_repeat('x', 40),
        ])->assertForbidden();

        $this->postJson(route('chat.guest-auth'), [
            'channel_name' => 'private-chat.conversation.'.$mine->id,
            'socket_id' => '1234.5678',
        ])->assertStatus(422);
    }

    public function test_a_customer_inbox_never_appears_in_the_staff_channel_list(): void
    {
        $this->chat->startCustomerChat(['email' => 'jo@example.com'], 'support', 'Help');

        $staff = $this->chatUser('chat.view');

        $visible = ChatConversation::query()
            ->whereIn('type', ChatConversation::STAFF_TYPES)
            ->get();

        $this->assertCount(0, $visible);
        $this->assertFalse($staff->can('view', ChatConversation::first()));
    }
}
