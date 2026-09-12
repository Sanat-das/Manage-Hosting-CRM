<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Events\Chat\TypingIndicator;
use App\Models\ChatConversation;
use App\Models\Customer;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Services\ChatService;
use App\Services\TicketService;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesChatUsers;
use Tests\TestCase;

/**
 * Inbound typing: the operator's keystrokes reaching the customer's widget.
 *
 * The outbound half (customer -> operator pane) already worked; this is the
 * return leg, and it is the half with the security surface. A customer has no
 * panel session and may never join a presence channel — the roster of a
 * presence channel IS a list of staff — so the operator's typing has to arrive
 * on the one channel a guest token already entitles them to: their own
 * conversation's private channel.
 *
 * The three things that must stay true, and are asserted here rather than
 * assumed:
 *   - a guest never reaches a presence channel, not even their own inbox's;
 *   - a guest token is good for exactly one conversation;
 *   - staff typing in a staff channel or DM produces nothing a guest could
 *     ever be subscribed to.
 */
class InboundTypingTest extends TestCase
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

    // --- which channels the event targets ------------------------------------

    public function test_an_operator_typing_in_a_customer_inbox_also_reaches_the_customers_own_channel(): void
    {
        $inbox = $this->chat->startCustomerChat(['email' => 'jo@example.com'], 'support', 'Help');
        $operator = $this->chatUser('chat.view', 'chat.manage');

        $channels = (new TypingIndicator($inbox->id, $operator->id, $operator->full_name, true))->broadcastOn();

        $names = array_map(static fn ($channel) => (string) $channel, $channels);

        // The staff presence channel is untouched and still first.
        $this->assertInstanceOf(PresenceChannel::class, $channels[0]);
        $this->assertSame('presence-chat.typing.'.$inbox->id, $names[0]);

        // ...and the customer's own private channel is added alongside it.
        $this->assertContains('private-chat.conversation.'.$inbox->id, $names);
        $this->assertInstanceOf(PrivateChannel::class, $channels[1]);
        $this->assertCount(2, $channels);
    }

    public function test_the_customer_is_not_told_which_operator_is_typing(): void
    {
        $inbox = $this->chat->startCustomerChat(['email' => 'jo@example.com'], 'support', 'Help');
        $operator = $this->chatUser('chat.view', 'chat.manage');
        $operator->forceFill(['first_name' => 'Aoife', 'last_name' => 'Brennan'])->save();

        $payload = (new TypingIndicator($inbox->id, $operator->id, $operator->fresh()->full_name, true))
            ->broadcastWith();

        $this->assertSame('Support', $payload['user_name']);
        $this->assertStringNotContainsString('Aoife', json_encode($payload));
        $this->assertStringNotContainsString('Brennan', json_encode($payload));

        // The payload shape the admin pane reads is unchanged.
        $this->assertSame(
            ['conversation_id', 'user_id', 'user_name', 'typing'],
            array_keys($payload),
        );
    }

    public function test_the_customers_own_heartbeat_is_not_echoed_back_to_the_widget(): void
    {
        $inbox = $this->chat->startCustomerChat(['name' => 'Jo Visitor', 'email' => 'jo@example.com'], 'support', 'Help');

        // userId 0 is what ChatWidgetController dispatches: the customer, who
        // has no user row at all.
        $event = new TypingIndicator($inbox->id, 0, $inbox->displayName(), true);

        $this->assertCount(1, $event->broadcastOn());
        $this->assertInstanceOf(PresenceChannel::class, $event->broadcastOn()[0]);

        // The operator still gets the customer's real name, not "Support".
        $this->assertSame($inbox->displayName(), $event->broadcastWith()['user_name']);
    }

    public function test_staff_typing_in_a_staff_channel_or_dm_never_leaves_the_presence_channel(): void
    {
        $owner = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $owner);
        $dm = ChatConversation::factory()->create(['type' => ChatConversation::TYPE_DM]);

        foreach ([$channel, $dm] as $conversation) {
            $event = new TypingIndicator($conversation->id, $owner->id, $owner->full_name, true);

            $this->assertCount(1, $event->broadcastOn(), 'Staff conversation gained a second channel');
            $this->assertInstanceOf(PresenceChannel::class, $event->broadcastOn()[0]);

            foreach ($event->broadcastOn() as $target) {
                $this->assertNotInstanceOf(PrivateChannel::class, $target);
            }

            // Staff keep seeing each other's real names in staff rooms.
            $this->assertSame($owner->full_name, $event->broadcastWith()['user_name']);
        }
    }

    public function test_typing_is_broadcast_now_and_never_queued(): void
    {
        $event = new TypingIndicator(1, 2, 'Alice', true);

        $this->assertInstanceOf(ShouldBroadcastNow::class, $event);
        $this->assertNotInstanceOf(ShouldQueue::class, $event);

        $source = file_get_contents(app_path('Events/Chat/TypingIndicator.php'));

        $this->assertStringNotContainsString('onQueue(', $source);
        $this->assertStringNotContainsString('$queue', $source);
    }

    // --- what a guest token can and cannot authorise -------------------------

    /**
     * Guest authorisation hands the signing to the configured broadcaster, and
     * the log driver signs nothing, so a SUCCESS case needs reverb selected.
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

    public function test_a_guest_is_refused_every_presence_channel_including_its_own_inboxs(): void
    {
        $mine = $this->chat->startCustomerChat(['email' => 'jo@example.com'], 'support', 'Help');

        $this->useReverbDriver();

        // The control: the one channel the guest IS entitled to.
        $this->postJson(route('chat.guest-auth'), [
            'channel_name' => 'private-chat.conversation.'.$mine->id,
            'socket_id' => '1234.5678',
            'token' => $mine->guest_token,
        ])->assertOk();

        // Presence channels expose their member roster, which is a list of
        // staff. A guest is refused even for the conversation they own.
        foreach ([
            'presence-chat.typing.'.$mine->id,
            'presence-chat.presence',
            'chat.typing.'.$mine->id,
            'presence-chat.conversation.'.$mine->id,
        ] as $channel) {
            $this->postJson(route('chat.guest-auth'), [
                'channel_name' => $channel,
                'socket_id' => '1234.5678',
                'token' => $mine->guest_token,
            ])->assertForbidden();
        }
    }

    public function test_a_guest_token_for_one_conversation_is_refused_for_another(): void
    {
        $mine = $this->chat->startCustomerChat(['email' => 'jo@example.com'], 'support', 'Help');
        $theirs = $this->chat->startCustomerChat(['email' => 'sam@example.com'], 'support', 'Also help');

        $this->useReverbDriver();

        $this->postJson(route('chat.guest-auth'), [
            'channel_name' => 'private-chat.conversation.'.$theirs->id,
            'socket_id' => '1234.5678',
            'token' => $mine->guest_token,
        ])->assertForbidden();

        // A conversation that does not exist is refused the same way, rather
        // than 404ing and confirming which ids are real.
        $this->postJson(route('chat.guest-auth'), [
            'channel_name' => 'private-chat.conversation.999999',
            'socket_id' => '1234.5678',
            'token' => $mine->guest_token,
        ])->assertForbidden();
    }

    // --- the widget actually has somewhere to show it ------------------------

    public function test_the_widget_renders_a_typing_indicator_that_is_hidden_at_rest(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        Customer::create(['user_id' => $user->id, 'status' => 'active']);

        $html = $this->actingAs($user)->get('/client')->assertOk()->getContent();

        $this->assertStringContainsString('id="client-chat-typing"', $html);

        // Conditional, not decorative: it ships hidden, and a naive
        // always-visible element would fail here.
        $this->assertMatchesRegularExpression(
            '/<p[^>]*id="client-chat-typing"[^>]*class="[^"]*\bd-none\b/s',
            $this->normaliseTypingElement($html),
            'The typing indicator is not hidden at rest.',
        );
    }

    public function test_the_widget_script_listens_for_typing_on_the_conversation_channel(): void
    {
        $source = file_get_contents(resource_path('js/client-chat.js'));

        $this->assertStringContainsString("listen('.chat.typing'", $source);
        $this->assertStringContainsString('chat.conversation.', $source);

        // A "typing..." that never clears is this feature's classic bug.
        $this->assertStringContainsString('TYPING_CLEAR_MS', $source);
    }

    /**
     * Put `class` before `id` into one predictable order so the assertion above
     * tests the element rather than the attribute order Blade happened to emit.
     */
    private function normaliseTypingElement(string $html): string
    {
        return (string) preg_replace(
            '/<p\s+class="([^"]*)"\s+id="client-chat-typing"/',
            '<p id="client-chat-typing" class="$1"',
            $html,
        );
    }
}
