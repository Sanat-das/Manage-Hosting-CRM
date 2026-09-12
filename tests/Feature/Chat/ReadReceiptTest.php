<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Events\Chat\UserPresence;
use App\Models\ChatParticipant;
use App\Services\ChatPresence;
use App\Services\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\CreatesChatUsers;
use Tests\TestCase;

/**
 * Read cursors, unread counts and presence.
 */
class ReadReceiptTest extends TestCase
{
    use CreatesChatUsers;
    use RefreshDatabase;

    private ChatService $chat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chat = app(ChatService::class);
    }

    public function test_unread_counts_messages_from_other_people_only(): void
    {
        $alice = $this->chatUser('chat.view', 'chat.create_channel');
        $bob = $this->chatUser('chat.view');

        $channel = $this->chat->createChannel('Ops', $alice);
        $this->chat->addMember($channel, $bob);

        $this->chat->sendMessage($channel, $alice, 'one');
        $this->chat->sendMessage($channel, $alice, 'two');
        $this->chat->sendMessage($channel, $alice, 'three');

        $this->assertSame(3, $this->chat->unreadCount($channel, $bob));
        $this->assertSame(
            0,
            $this->chat->unreadCount($channel, $alice),
            'You do not have unread messages from yourself.',
        );
    }

    public function test_marking_read_clears_the_count(): void
    {
        $alice = $this->chatUser('chat.view', 'chat.create_channel');
        $bob = $this->chatUser('chat.view');

        $channel = $this->chat->createChannel('Ops', $alice);
        $this->chat->addMember($channel, $bob);

        $this->chat->sendMessage($channel, $alice, 'one');
        $last = $this->chat->sendMessage($channel, $alice, 'two');

        $this->assertSame(2, $this->chat->unreadCount($channel, $bob));

        $cursor = $this->chat->markRead($channel, $bob, $last->id);

        $this->assertSame($last->id, $cursor);
        $this->assertSame(0, $this->chat->unreadCount($channel, $bob));
    }

    public function test_the_read_cursor_never_moves_backwards(): void
    {
        $alice = $this->chatUser('chat.view', 'chat.create_channel');
        $bob = $this->chatUser('chat.view');

        $channel = $this->chat->createChannel('Ops', $alice);
        $this->chat->addMember($channel, $bob);

        $first = $this->chat->sendMessage($channel, $alice, 'one');
        $second = $this->chat->sendMessage($channel, $alice, 'two');

        $this->chat->markRead($channel, $bob, $second->id);

        // A late heartbeat from a tab that was behind must not resurrect the badge.
        $cursor = $this->chat->markRead($channel, $bob, $first->id);

        $this->assertSame($second->id, $cursor);
        $this->assertSame(0, $this->chat->unreadCount($channel, $bob));
    }

    public function test_marking_read_without_an_id_catches_up_to_the_latest(): void
    {
        $alice = $this->chatUser('chat.view', 'chat.create_channel');
        $bob = $this->chatUser('chat.view');

        $channel = $this->chat->createChannel('Ops', $alice);
        $this->chat->addMember($channel, $bob);

        $this->chat->sendMessage($channel, $alice, 'one');
        $latest = $this->chat->sendMessage($channel, $alice, 'two');

        $this->assertSame($latest->id, $this->chat->markRead($channel, $bob));
        $this->assertSame(0, $this->chat->unreadCount($channel, $bob));
    }

    public function test_a_non_participant_has_no_cursor_and_is_not_enrolled_by_reading(): void
    {
        $alice = $this->chatUser('chat.view', 'chat.create_channel');
        $bystander = $this->chatUser('chat.view');

        $channel = $this->chat->createChannel('General', $alice);
        $this->chat->sendMessage($channel, $alice, 'hello');

        $this->assertSame(0, $this->chat->markRead($channel, $bystander));
        $this->assertSame(0, $this->chat->unreadCount($channel, $bystander));
        $this->assertDatabaseMissing('chat_participants', [
            'conversation_id' => $channel->id,
            'user_id' => $bystander->id,
        ]);
    }

    public function test_a_deleted_message_stops_being_unread(): void
    {
        $alice = $this->chatUser('chat.view', 'chat.create_channel');
        $bob = $this->chatUser('chat.view');

        $channel = $this->chat->createChannel('Ops', $alice);
        $this->chat->addMember($channel, $bob);

        $message = $this->chat->sendMessage($channel, $alice, 'oops');
        $this->assertSame(1, $this->chat->unreadCount($channel, $bob));

        $this->chat->deleteMessage($message);

        $this->assertSame(0, $this->chat->unreadCount($channel, $bob));
    }

    public function test_unread_counts_for_the_whole_sidebar_come_from_one_query(): void
    {
        $alice = $this->chatUser('chat.view', 'chat.create_channel');
        $bob = $this->chatUser('chat.view');

        $first = $this->chat->createChannel('One', $alice);
        $second = $this->chat->createChannel('Two', $alice);
        $quiet = $this->chat->createChannel('Quiet', $alice);

        foreach ([$first, $second, $quiet] as $channel) {
            $this->chat->addMember($channel, $bob);
        }

        $this->chat->sendMessage($first, $alice, 'a');
        $this->chat->sendMessage($first, $alice, 'b');
        $this->chat->sendMessage($second, $alice, 'c');

        $counts = $this->chat->unreadCounts($bob);

        $this->assertSame(2, $counts[$first->id]);
        $this->assertSame(1, $counts[$second->id]);
        $this->assertArrayNotHasKey($quiet->id, $counts, 'A conversation with nothing unread is simply absent.');
    }

    // --- endpoints --------------------------------------------------------

    public function test_the_read_endpoint_moves_the_cursor(): void
    {
        $alice = $this->chatUser('chat.view', 'chat.create_channel');
        $bob = $this->chatUser('chat.view');

        $channel = $this->chat->createChannel('Ops', $alice);
        $this->chat->addMember($channel, $bob);
        $message = $this->chat->sendMessage($channel, $alice, 'hello');

        $this->actingAs($bob)
            ->postJson(route('admin.chat.read', $channel), ['message_id' => $message->id])
            ->assertOk()
            ->assertJson(['last_read_message_id' => $message->id, 'unread' => 0]);

        $this->assertSame(
            $message->id,
            ChatParticipant::where('conversation_id', $channel->id)->where('user_id', $bob->id)
                ->value('last_read_message_id'),
        );
    }

    public function test_the_read_endpoint_refuses_a_conversation_you_cannot_see(): void
    {
        $alice = $this->chatUser('chat.view', 'chat.create_channel');
        $private = $this->chat->createChannel('Secret', $alice, true);

        $this->actingAs($this->chatUser('chat.view'))
            ->postJson(route('admin.chat.read', $private))
            ->assertForbidden();
    }

    public function test_the_unread_endpoint_serves_the_sidebar_and_the_polling_fallback(): void
    {
        $alice = $this->chatUser('chat.view', 'chat.create_channel');
        $bob = $this->chatUser('chat.view');

        $channel = $this->chat->createChannel('Ops', $alice);
        $this->chat->addMember($channel, $bob);
        $this->chat->sendMessage($channel, $alice, 'hello');

        $this->actingAs($bob)
            ->getJson(route('admin.chat.unread'))
            ->assertOk()
            ->assertJsonPath('unread.'.$channel->id, 1)
            ->assertJsonStructure(['unread', 'online']);
    }

    // --- presence ---------------------------------------------------------

    public function test_a_heartbeat_puts_someone_online_and_announces_it_once(): void
    {
        Event::fake([UserPresence::class]);

        $presence = app(ChatPresence::class);
        $user = $this->chatUser('chat.view');

        $this->assertFalse($presence->isOnline($user->id));

        $this->assertTrue($presence->heartbeat($user));
        $this->assertTrue($presence->isOnline($user->id));

        // A second heartbeat is not a second arrival.
        $this->assertFalse($presence->heartbeat($user));

        Event::assertDispatchedTimes(UserPresence::class, 1);
    }

    public function test_leaving_takes_someone_offline(): void
    {
        Event::fake([UserPresence::class]);

        $presence = app(ChatPresence::class);
        $user = $this->chatUser('chat.view');

        $presence->heartbeat($user);
        $presence->leave($user);

        $this->assertFalse($presence->isOnline($user->id));
        Event::assertDispatched(
            UserPresence::class,
            fn (UserPresence $e) => $e->status === UserPresence::OFFLINE,
        );
    }

    public function test_a_stale_entry_ages_out_without_a_goodbye(): void
    {
        $presence = app(ChatPresence::class);
        $user = $this->chatUser('chat.view');

        $presence->heartbeat($user);
        $this->assertTrue($presence->isOnline($user->id));

        // A closed tab sends no goodbye — it simply stops heartbeating.
        $this->travel(ChatPresence::STALE_AFTER_SECONDS + 5)->seconds();

        $this->assertFalse($presence->isOnline($user->id));
        $this->assertSame([], $presence->online());
    }

    public function test_the_presence_endpoint_reports_the_roster(): void
    {
        $user = $this->chatUser('chat.view');

        $this->actingAs($user)
            ->postJson(route('admin.chat.presence'))
            ->assertOk()
            ->assertJsonPath('online.0.id', $user->id)
            ->assertJsonPath('heartbeat_seconds', ChatPresence::HEARTBEAT_SECONDS);

        $this->actingAs($user)
            ->postJson(route('admin.chat.presence'), ['online' => false])
            ->assertOk()
            ->assertJsonPath('online', []);
    }
}
