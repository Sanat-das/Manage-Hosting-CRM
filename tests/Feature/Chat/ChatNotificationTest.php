<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\ChatConversation;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\ChatAssignmentNotification;
use App\Notifications\ChatMentionNotification;
use App\Notifications\ChatReplyNotification;
use App\Services\ChatService;
use App\Support\ChatMentions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\CreatesChatUsers;
use Tests\TestCase;

/**
 * Todo 16 — notifications for @mentions, thread replies, and operator
 * assignments. Behavioural: real `notifications` rows, gated by the real
 * NotificationPreferenceService, leaked only to people who can actually see the
 * room.
 */
class ChatNotificationTest extends TestCase
{
    use CreatesChatUsers;
    use RefreshDatabase;

    private ChatService $chat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chat = app(ChatService::class);
    }

    private function userWithName(string $first, string $last, string ...$perms): User
    {
        $user = $this->chatUser(...$perms);
        $user->forceFill(['first_name' => $first, 'last_name' => $last])->save();

        return $user->fresh();
    }

    public function test_at_mention_creates_a_database_row_and_a_broadcast(): void
    {
        Event::fake([BroadcastNotificationCreated::class]);

        $alice = $this->userWithName('Alice', 'Anders', 'chat.view', 'chat.create_channel');
        $bob = $this->userWithName('Bob', 'Baker', 'chat.view');

        $channel = $this->chat->createChannel('General', $alice);
        $this->chat->addMember($channel, $bob);

        $this->chat->sendMessage($channel, $alice, 'Hey @Bob Baker, review this.');

        // Behavioural: real row, correct type and payload.
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => $bob->getMorphClass(),
            'notifiable_id' => $bob->id,
        ]);

        $row = $bob->notifications()->firstOrFail();
        $this->assertSame('chat.mention', $row->data['type']);
        $this->assertSame($alice->id, $row->data['actor_id']);
        $this->assertStringContainsString('Bob Baker', $row->data['excerpt'] ?? '');

        // Broadcast: the notification was sent on the broadcast channel to Bob's
        // private user channel.
        Event::assertDispatched(BroadcastNotificationCreated::class, function (BroadcastNotificationCreated $event) use ($bob) {
            return $event->notifiable->is($bob)
                && $event->notification instanceof ChatMentionNotification;
        });

        // Author never notified about their own mention text.
        $this->assertSame(0, $alice->notifications()->count());
    }

    public function test_disabled_mention_preference_skips_the_notification(): void
    {
        Event::fake([BroadcastNotificationCreated::class]);

        $alice = $this->userWithName('Alice', 'Anders', 'chat.view', 'chat.create_channel');
        $bob = $this->userWithName('Bob', 'Baker', 'chat.view');
        $channel = $this->chat->createChannel('General', $alice);
        $this->chat->addMember($channel, $bob);

        NotificationPreference::create([
            'preferrable_type' => $bob->getMorphClass(),
            'preferrable_id' => $bob->id,
            'type' => 'chat.mention',
            'channel' => 'database',
            'enabled' => false,
        ]);

        $this->chat->sendMessage($channel, $alice, 'Hey @Bob Baker, you there?');

        $this->assertSame(0, $bob->notifications()->count());
        Event::assertNotDispatched(BroadcastNotificationCreated::class);
    }

    public function test_self_mention_notifies_nobody(): void
    {
        Event::fake([BroadcastNotificationCreated::class]);

        $alice = $this->userWithName('Alice', 'Anders', 'chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('General', $alice);

        $this->chat->sendMessage($channel, $alice, 'Note to self @Alice Anders do this.');

        $this->assertSame(0, $alice->notifications()->count());
        Event::assertNotDispatched(BroadcastNotificationCreated::class);
    }

    public function test_thread_reply_notifies_the_parent_author(): void
    {
        Event::fake([BroadcastNotificationCreated::class]);

        $alice = $this->userWithName('Alice', 'Anders', 'chat.view', 'chat.create_channel');
        $bob = $this->userWithName('Bob', 'Baker', 'chat.view');
        $channel = $this->chat->createChannel('General', $alice);
        $this->chat->addMember($channel, $bob);

        $root = $this->chat->sendMessage($channel, $alice, 'Question for Bob?');

        // Clear Alice's notification from any mention in root (none here) — ensure clean.
        $bob->notifications()->delete();
        $alice->notifications()->delete();

        $this->chat->sendMessage($channel, $bob, 'Here is the answer.', $root->id);

        $this->assertSame(1, $alice->notifications()->count());
        $row = $alice->notifications()->firstOrFail();
        $this->assertSame('chat.reply', $row->data['type']);
        $this->assertSame($root->id, $row->data['parent_id']);

        Event::assertDispatched(BroadcastNotificationCreated::class, fn ($e) => $e->notification instanceof ChatReplyNotification);
    }

    public function test_operator_assignment_notifies_the_assignee(): void
    {
        Event::fake([BroadcastNotificationCreated::class]);

        $assigner = $this->userWithName('Op', 'One', 'chat.view', 'chat.manage');
        $assignee = $this->userWithName('Op', 'Two', 'chat.view', 'chat.manage');

        $inbox = ChatConversation::factory()->customerInbox()->create();

        $this->chat->assignOperator($inbox, $assignee, $assigner);

        $this->assertSame(1, $assignee->notifications()->count());
        $this->assertSame('chat.assign', $assignee->notifications()->first()->data['type']);

        Event::assertDispatched(BroadcastNotificationCreated::class, fn ($e) => $e->notification instanceof ChatAssignmentNotification);

        // Self-assign must not notify.
        $assignee->notifications()->delete();
        Event::fake([BroadcastNotificationCreated::class]);
        $inbox2 = ChatConversation::factory()->customerInbox()->create();
        $this->chat->assignOperator($inbox2, $assigner);

        $this->assertSame(0, $assigner->notifications()->count());
    }

    public function test_edit_does_not_re_notify(): void
    {
        $alice = $this->userWithName('Alice', 'Anders', 'chat.view', 'chat.create_channel');
        $bob = $this->userWithName('Bob', 'Baker', 'chat.view');
        $channel = $this->chat->createChannel('General', $alice);
        $this->chat->addMember($channel, $bob);

        $msg = $this->chat->sendMessage($channel, $alice, 'Hey @Bob Baker initial.');

        $this->assertSame(1, $bob->notifications()->count());

        $this->chat->editMessage($msg, 'Hey @Bob Baker edited.');

        $this->assertSame(1, $bob->notifications()->count(), 'An edit must not create a second notification.');
    }

    public function test_channel_mention_notifies_every_participant_except_author(): void
    {
        $alice = $this->userWithName('Alice', 'Anders', 'chat.view', 'chat.create_channel');
        $bob = $this->userWithName('Bob', 'Baker', 'chat.view');
        $carol = $this->userWithName('Carol', 'Clark', 'chat.view');
        $channel = $this->chat->createChannel('Team', $alice);
        $this->chat->addMember($channel, $bob);
        $this->chat->addMember($channel, $carol);

        $this->chat->sendMessage($channel, $alice, 'Attention @channel please read.');

        $this->assertSame(1, $bob->notifications()->count());
        $this->assertSame(1, $carol->notifications()->count());
        $this->assertSame(0, $alice->notifications()->count());
        $this->assertSame('chat.mention', $bob->notifications()->first()->data['type']);
    }

    public function test_mention_of_user_who_cannot_see_private_channel_does_not_notify(): void
    {
        $alice = $this->userWithName('Alice', 'Anders', 'chat.view', 'chat.create_channel');
        $bob = $this->userWithName('Bob', 'Baker', 'chat.view'); // outsider
        $private = $this->chat->createChannel('Secret', $alice, true);

        // Bob is NOT a participant, so can('view', $private) is false.
        $this->assertFalse($bob->can('view', $private));

        $this->chat->sendMessage($private, $alice, 'Hey @Bob Baker secret!');

        // Must be 0 — the mention must never become a permission bypass or an
        // existence oracle for the private channel.
        $this->assertSame(0, $bob->notifications()->count());
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $bob->id,
        ]);
    }

    public function test_malformed_mentions_do_not_notify_and_email_is_not_a_mention(): void
    {
        $alice = $this->userWithName('Alice', 'Anders', 'chat.view', 'chat.create_channel');
        $bob = $this->userWithName('Bob', 'Baker', 'chat.view');
        $channel = $this->chat->createChannel('General', $alice);
        $this->chat->addMember($channel, $bob);

        $this->chat->sendMessage($channel, $alice, '@ alone @@ and @nonexistent and a@b.com test');
        $this->assertSame(0, $bob->notifications()->count());

        // Dot/hyphen in name still resolves.
        $hyphen = $this->userWithName('Anne-Marie', 'O\'Neil', 'chat.view');
        $this->chat->addMember($channel, $hyphen);
        $hyphen->notifications()->delete();
        $this->chat->sendMessage($channel, $alice, 'Hi @Anne-Marie O\'Neil welcome');
        $this->assertSame(1, $hyphen->notifications()->count());
    }

    public function test_mention_fan_out_is_bounded(): void
    {
        $alice = $this->userWithName('Alice', 'Anders', 'chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('General', $alice);

        // Create 30 users and mention them all — only MAX_MENTIONS should be notified.
        $victims = collect(range(1, 30))->map(function (int $i) use ($channel) {
            $u = $this->userWithName('User'.$i, 'Test', 'chat.view');
            $this->chat->addMember($channel, $u);

            return $u;
        });

        $body = $victims->map(fn (User $u) => '@'.$u->full_name)->implode(' ');

        $this->chat->sendMessage($channel, $alice, $body);

        $notified = $victims->filter(fn (User $u) => $u->fresh()->notifications()->count() > 0)->count();

        $this->assertSame(ChatMentions::MAX_MENTIONS, $notified);
        $this->assertLessThanOrEqual(ChatMentions::MAX_MENTIONS, $notified);
    }

    public function test_prompt_injection_body_is_escaped_in_notification_payload(): void
    {
        $alice = $this->userWithName('Alice', 'Anders', 'chat.view', 'chat.create_channel');
        $bob = $this->userWithName('Bob', 'Baker', 'chat.view');
        $channel = $this->chat->createChannel('General', $alice);
        $this->chat->addMember($channel, $bob);

        $body = 'Hey @Bob Baker <script>alert(1)</script> {{7*7}}';
        $this->chat->sendMessage($channel, $alice, $body);

        $row = $bob->notifications()->firstOrFail();
        $excerpt = $row->data['excerpt'];

        // Stored data must not contain raw script markup; rendered output would
        // go through htmlspecialchars / ChatBodyHtml.
        $this->assertStringNotContainsString('<script>', $excerpt);
        $this->assertStringContainsString('<script>', $body, 'Fixture must actually contain the injection.');
    }

    public function test_disabled_reply_preference_skips_notification(): void
    {
        $alice = $this->userWithName('Alice', 'Anders', 'chat.view', 'chat.create_channel');
        $bob = $this->userWithName('Bob', 'Baker', 'chat.view');
        $channel = $this->chat->createChannel('General', $alice);
        $this->chat->addMember($channel, $bob);

        $root = $this->chat->sendMessage($channel, $alice, 'Root question');

        NotificationPreference::create([
            'preferrable_type' => $alice->getMorphClass(),
            'preferrable_id' => $alice->id,
            'type' => 'chat.reply',
            'channel' => 'database',
            'enabled' => false,
        ]);

        $this->chat->sendMessage($channel, $bob, 'Reply here', $root->id);

        $this->assertSame(0, $alice->notifications()->where('data->type', 'chat.reply')->count());
    }

    public function test_assign_disabled_preference_skips_notification(): void
    {
        $assigner = $this->userWithName('Op', 'One', 'chat.view', 'chat.manage');
        $assignee = $this->userWithName('Op', 'Two', 'chat.view', 'chat.manage');

        NotificationPreference::create([
            'preferrable_type' => $assignee->getMorphClass(),
            'preferrable_id' => $assignee->id,
            'type' => 'chat.assign',
            'channel' => 'database',
            'enabled' => false,
        ]);

        $inbox = ChatConversation::factory()->customerInbox()->create();
        $this->chat->assignOperator($inbox, $assignee, $assigner);

        $this->assertSame(0, $assignee->notifications()->count());
    }
}
