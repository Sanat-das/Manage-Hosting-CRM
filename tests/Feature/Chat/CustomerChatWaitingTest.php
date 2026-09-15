<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Events\Chat\CustomerChatWaiting;
use App\Models\ChatConversation;
use App\Models\User;
use App\Services\ChatPresence;
use App\Services\ChatService;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\CreatesChatUsers;
use Tests\TestCase;

/**
 * The signal that makes a queued customer chat discoverable.
 *
 * Before this existed a customer could open a conversation and no operator
 * screen changed in any way: the room has no staff participant, so it raises no
 * unread badge, and every other chat event broadcasts on
 * `chat.conversation.{id}` — a channel nobody subscribes to until they have
 * opened a room they do not yet know about. The only way to find a waiting
 * customer was to reload /admin/chat.
 *
 * Two independent paths have to keep working, because most installs have no
 * websocket at all (`BROADCAST_CONNECTION=log` ships as the default): the
 * CustomerChatWaiting broadcast, and the `inbox` key on the sidebar poll.
 */
class CustomerChatWaitingTest extends TestCase
{
    use CreatesChatUsers;
    use RefreshDatabase;

    public function test_starting_a_customer_chat_announces_it_to_the_operator_queue(): void
    {
        Event::fake([CustomerChatWaiting::class]);

        $conversation = app(ChatService::class)->startCustomerChat(
            ['name' => 'Ada', 'email' => 'ada@example.com'],
            null,
            'My site is down.',
        );

        Event::assertDispatched(
            CustomerChatWaiting::class,
            fn (CustomerChatWaiting $event) => (int) $event->conversation->id === (int) $conversation->id,
        );
    }

    public function test_the_announcement_targets_the_shared_operator_channel(): void
    {
        $conversation = ChatConversation::factory()->customerInbox()->create();

        $channels = (new CustomerChatWaiting($conversation))->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-chat.inbox', (string) $channels[0]);
    }

    /**
     * The channel is shared by every operator and long-lived, so the
     * announcement carries only enough to put a row in a sidebar. The text the
     * customer wrote is fetched later, through the endpoint that re-checks the
     * policy per request.
     */
    public function test_the_announcement_carries_no_message_body(): void
    {
        $conversation = app(ChatService::class)->startCustomerChat(
            ['name' => 'Ada', 'email' => 'ada@example.com'],
            null,
            'Nuclear launch codes are 0000.',
        );

        $payload = json_encode((new CustomerChatWaiting($conversation))->broadcastWith());

        $this->assertStringNotContainsString('Nuclear launch codes', (string) $payload);
        $this->assertStringNotContainsString($conversation->guest_token, (string) $payload);
    }

    public function test_the_sidebar_poll_carries_the_queue_for_an_operator(): void
    {
        $operator = $this->chatUser('chat.view', 'chat.manage');

        $waiting = ChatConversation::factory()->customerInbox()->create([
            'status' => ChatConversation::STATUS_WAITING,
            'guest_name' => 'Ada',
        ]);

        $this->actingAs($operator)
            ->getJson(route('admin.chat.unread'))
            ->assertOk()
            ->assertJsonPath('inbox.0.id', $waiting->id)
            ->assertJsonPath('inbox.0.name', 'Ada');
    }

    /**
     * The same gate ChatConversationPolicy::view() applies to a customer inbox.
     * A badge-count endpoint is exactly the sort of place a listing quietly
     * appears without anyone re-checking who may read it.
     */
    public function test_the_sidebar_poll_shows_no_queue_to_a_non_operator(): void
    {
        $staff = $this->chatUser('chat.view');

        ChatConversation::factory()->customerInbox()->create([
            'status' => ChatConversation::STATUS_WAITING,
        ]);

        $this->actingAs($staff)
            ->getJson(route('admin.chat.unread'))
            ->assertOk()
            ->assertJsonPath('inbox', []);
    }

    public function test_an_answered_conversation_is_not_in_the_queue(): void
    {
        $operator = $this->chatUser('chat.view', 'chat.manage');

        ChatConversation::factory()->customerInbox()->create([
            'status' => ChatConversation::STATUS_ACTIVE,
        ]);

        $this->actingAs($operator)
            ->getJson(route('admin.chat.unread'))
            ->assertOk()
            ->assertJsonPath('inbox', []);
    }

    // --- presence roster ---------------------------------------------------

    public function test_two_operators_both_survive_the_roster(): void
    {
        $presence = app(ChatPresence::class);

        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->assertTrue($presence->heartbeat($first));
        $this->assertTrue($presence->heartbeat($second));
        $this->assertFalse($presence->heartbeat($first), 'A repeat heartbeat is not a new arrival.');

        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            $presence->onlineIds(),
        );

        $presence->leave($first);

        $this->assertSame([$second->id], $presence->onlineIds());
    }

    /**
     * A heartbeat that cannot get the lock must still be recorded.
     *
     * The lock exists to stop two overlapping heartbeats erasing each other,
     * but it must never become a way to lose a heartbeat outright: a presence
     * dot that flickers is a cosmetic problem, while a dropped write makes
     * someone vanish from the roster for a guaranteed 90 seconds. Holding the
     * lock from outside is the only way to reach that branch deterministically.
     */
    public function test_a_heartbeat_still_lands_when_the_roster_lock_is_held(): void
    {
        $presence = app(ChatPresence::class);
        $user = User::factory()->create();

        $lock = Cache::lock('chat:presence:roster:lock', 30);
        $this->assertTrue($lock->get(), 'Could not take the lock — the test proves nothing.');

        try {
            $presence->heartbeat($user);
        } finally {
            $lock->forceRelease();
        }

        $this->assertTrue($presence->isOnline($user->id));
    }
}
