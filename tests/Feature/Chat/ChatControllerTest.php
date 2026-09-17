<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Events\Chat\TypingIndicator;
use App\Models\ChatConversation;
use App\Models\ChatConversationMessage;
use App\Models\ChatReaction;
use App\Services\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\CreatesChatUsers;
use Tests\TestCase;

/**
 * The admin write path.
 *
 * Two gates guard every route and both are exercised: the `permission:chat.view`
 * middleware, and the per-conversation policy. Holding chat.view is permission
 * to use the chat, not permission to read any particular room.
 */
class ChatControllerTest extends TestCase
{
    use CreatesChatUsers;
    use RefreshDatabase;

    private ChatService $chat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chat = app(ChatService::class);
    }

    // --- channels ---------------------------------------------------------

    public function test_a_channel_can_be_created(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');

        $response = $this->actingAs($user)
            ->postJson(route('admin.chat.channels.store'), ['name' => 'Deploys'])
            ->assertCreated();

        $response->assertJsonPath('channel.name', 'Deploys');
        $response->assertJsonPath('channel.slug', 'deploys');
        $response->assertJsonPath('channel.is_private', false);

        $this->assertDatabaseHas('chat_conversations', ['slug' => 'deploys', 'created_by' => $user->id]);
    }

    public function test_creating_a_channel_requires_the_create_permission(): void
    {
        $this->actingAs($this->chatUser('chat.view'))
            ->postJson(route('admin.chat.channels.store'), ['name' => 'Deploys'])
            ->assertForbidden();

        $this->assertDatabaseCount('chat_conversations', 0);
    }

    public function test_channel_routes_are_closed_to_users_without_chat_view(): void
    {
        $this->actingAs($this->chatUser())
            ->postJson(route('admin.chat.channels.store'), ['name' => 'Deploys'])
            ->assertForbidden();
    }

    public function test_a_channel_name_is_validated(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');

        $this->actingAs($user)
            ->postJson(route('admin.chat.channels.store'), ['name' => '  '])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->actingAs($user)
            ->postJson(route('admin.chat.channels.store'), ['name' => str_repeat('a', 51)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->actingAs($user)
            ->postJson(route('admin.chat.channels.store'), ['name' => '<script>x</script>'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_the_conversation_type_cannot_be_chosen_by_the_caller(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');

        $this->actingAs($user)->postJson(route('admin.chat.channels.store'), [
            'name' => 'Sneaky',
            'type' => ChatConversation::TYPE_CUSTOMER_INBOX,
        ])->assertCreated();

        $this->assertSame(
            ChatConversation::TYPE_CHANNEL,
            ChatConversation::where('slug', 'sneaky')->value('type'),
            'The endpoint, not the request, decides the conversation type.',
        );
    }

    public function test_only_a_channel_admin_may_rename_or_archive_it(): void
    {
        $owner = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $owner);

        $member = $this->chatUser('chat.view');
        $this->chat->addMember($channel, $member);

        $this->actingAs($member)
            ->putJson(route('admin.chat.channels.update', $channel), ['name' => 'Hijacked'])
            ->assertForbidden();

        $this->actingAs($member)
            ->postJson(route('admin.chat.channels.archive', $channel))
            ->assertForbidden();

        $this->actingAs($owner)
            ->putJson(route('admin.chat.channels.update', $channel), ['name' => 'Operations'])
            ->assertOk()
            ->assertJsonPath('channel.name', 'Operations');

        $this->actingAs($owner)
            ->postJson(route('admin.chat.channels.archive', $channel))
            ->assertOk()
            ->assertJsonPath('channel.is_archived', true);
    }

    public function test_only_a_channel_admin_may_delete_it_and_only_channels_go(): void
    {
        $owner = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $owner);
        $this->chat->sendMessage($channel, $owner, 'Last words.');

        $member = $this->chatUser('chat.view');
        $this->chat->addMember($channel, $member);

        $this->actingAs($member)
            ->deleteJson(route('admin.chat.channels.destroy', $channel))
            ->assertForbidden();

        $this->assertDatabaseHas('chat_conversations', ['id' => $channel->id]);

        $dm = $this->chat->findOrCreateDirectMessage($owner, $member);

        $this->actingAs($owner)
            ->deleteJson(route('admin.chat.channels.destroy', $dm))
            ->assertForbidden();

        $this->assertDatabaseHas('chat_conversations', ['id' => $dm->id]);

        $this->actingAs($owner)
            ->deleteJson(route('admin.chat.channels.destroy', $channel))
            ->assertOk();

        $this->assertDatabaseMissing('chat_conversations', ['id' => $channel->id]);
        $this->assertSame(0, ChatConversationMessage::withTrashed()->where('conversation_id', $channel->id)->count());
    }

    public function test_a_public_channel_can_be_joined_and_left_but_a_private_one_cannot(): void
    {
        $owner = $this->chatUser('chat.view', 'chat.create_channel');
        $public = $this->chat->createChannel('General', $owner);
        $private = $this->chat->createChannel('Secret', $owner, true);

        $user = $this->chatUser('chat.view');

        $this->actingAs($user)->postJson(route('admin.chat.channels.join', $public))->assertOk();
        $this->assertDatabaseHas('chat_participants', ['conversation_id' => $public->id, 'user_id' => $user->id]);

        $this->actingAs($user)->postJson(route('admin.chat.channels.leave', $public))->assertOk();
        $this->assertDatabaseMissing('chat_participants', ['conversation_id' => $public->id, 'user_id' => $user->id]);

        $this->actingAs($user)->postJson(route('admin.chat.channels.join', $private))->assertForbidden();
    }

    // --- messages ---------------------------------------------------------

    public function test_a_participant_can_post_a_message(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $user);

        $this->actingAs($user)
            ->postJson(route('admin.chat.messages.store', $channel), ['body' => 'Deploy is green'])
            ->assertCreated()
            ->assertJsonPath('message.body', 'Deploy is green')
            ->assertJsonPath('message.user.id', $user->id);

        $this->assertDatabaseHas('chat_conversation_messages', [
            'conversation_id' => $channel->id,
            'body' => 'Deploy is green',
        ]);
    }

    public function test_a_non_participant_cannot_post_into_a_private_channel(): void
    {
        $owner = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Secret', $owner, true);

        $this->actingAs($this->chatUser('chat.view'))
            ->postJson(route('admin.chat.messages.store', $channel), ['body' => 'Let me in'])
            ->assertForbidden();

        $this->assertSame(0, $channel->messages()->count());
    }

    public function test_reading_a_public_channel_does_not_grant_writing_to_it(): void
    {
        $owner = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('General', $owner);
        $bystander = $this->chatUser('chat.view');

        $this->actingAs($bystander)
            ->getJson(route('admin.chat.messages.index', $channel))
            ->assertOk();

        $this->actingAs($bystander)
            ->postJson(route('admin.chat.messages.store', $channel), ['body' => 'hi'])
            ->assertForbidden();
    }

    public function test_an_oversized_body_is_refused(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $user);

        $this->actingAs($user)
            ->postJson(route('admin.chat.messages.store', $channel), [
                'body' => str_repeat('a', ChatService::MAX_BODY_LENGTH + 1),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');
    }

    public function test_messages_cannot_be_created_by_a_get_request(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $user);

        $this->actingAs($user)
            ->get('/admin/chat/channels/'.$channel->id.'/archive')
            ->assertStatus(405);
    }

    public function test_only_the_author_may_edit_and_a_moderator_may_delete(): void
    {
        $author = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $author);
        $message = $this->chat->sendMessage($channel, $author, 'Typo heer');

        $other = $this->chatUser('chat.view');
        $this->chat->addMember($channel, $other);

        $this->actingAs($other)
            ->putJson(route('admin.chat.messages.update', $message), ['body' => 'Rewritten'])
            ->assertForbidden();

        $this->actingAs($author)
            ->putJson(route('admin.chat.messages.update', $message), ['body' => 'Typo here'])
            ->assertOk()
            ->assertJsonPath('message.body', 'Typo here')
            ->assertJsonPath('message.is_edited', true);

        $moderator = $this->chatUser('chat.view', 'chat.manage');

        $this->actingAs($moderator)
            ->deleteJson(route('admin.chat.messages.destroy', $message))
            ->assertOk();

        $this->assertSoftDeleted('chat_conversation_messages', ['id' => $message->id]);
    }

    public function test_history_pages_backwards_and_polls_forwards(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $user);

        $ids = collect(range(1, 5))
            ->map(fn (int $n) => $this->chat->sendMessage($channel, $user, "message {$n}")->id);

        $this->actingAs($user)
            ->getJson(route('admin.chat.messages.index', $channel))
            ->assertOk()
            ->assertJsonCount(5, 'messages');

        // after_id is what the polling fallback uses.
        $this->actingAs($user)
            ->getJson(route('admin.chat.messages.index', [$channel, 'after_id' => $ids[2]]))
            ->assertOk()
            ->assertJsonCount(2, 'messages');

        $this->actingAs($user)
            ->getJson(route('admin.chat.messages.index', [$channel, 'before_id' => $ids[1]]))
            ->assertOk()
            ->assertJsonCount(1, 'messages');
    }

    public function test_a_thread_returns_its_parent_and_replies(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $user);

        $root = $this->chat->sendMessage($channel, $user, 'Question?');
        $this->chat->sendMessage($channel, $user, 'Answer one.', $root->id);
        $this->chat->sendMessage($channel, $user, 'Answer two.', $root->id);

        $this->actingAs($user)
            ->getJson(route('admin.chat.threads.show', [$channel, $root]))
            ->assertOk()
            ->assertJsonPath('parent.id', $root->id)
            ->assertJsonCount(2, 'replies');

        // Thread replies do not appear again in the main history.
        $this->actingAs($user)
            ->getJson(route('admin.chat.messages.index', $channel))
            ->assertJsonCount(1, 'messages');
    }

    public function test_a_thread_from_another_conversation_is_not_found(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $here = $this->chat->createChannel('Here', $user);
        $there = $this->chat->createChannel('There', $user);

        $elsewhere = $this->chat->sendMessage($there, $user, 'Over there');

        $this->actingAs($user)
            ->getJson(route('admin.chat.threads.show', [$here, $elsewhere]))
            ->assertNotFound();
    }

    // --- reactions and typing --------------------------------------------

    public function test_a_reaction_toggles_on_and_off(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $user);
        $message = $this->chat->sendMessage($channel, $user, 'Shipped');

        $this->actingAs($user)
            ->postJson(route('admin.chat.messages.reactions', $message), ['emoji' => '🎉'])
            ->assertOk()
            ->assertJson(['added' => true, 'count' => 1]);

        $this->assertDatabaseCount('chat_reactions', 1);

        $this->actingAs($user)
            ->postJson(route('admin.chat.messages.reactions', $message), ['emoji' => '🎉'])
            ->assertOk()
            ->assertJson(['added' => false, 'count' => 0]);

        $this->assertDatabaseCount('chat_reactions', 0);
    }

    public function test_an_arbitrary_reaction_is_refused(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $user);
        $message = $this->chat->sendMessage($channel, $user, 'Shipped');

        $this->actingAs($user)
            ->postJson(route('admin.chat.messages.reactions', $message), [
                'emoji' => '<img src=x onerror=alert(1)>',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('emoji');

        $this->assertSame(0, ChatReaction::count());
    }

    public function test_typing_is_broadcast_and_never_stored(): void
    {
        Event::fake([TypingIndicator::class]);

        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $user);

        $this->actingAs($user)
            ->postJson(route('admin.chat.typing', $channel), ['typing' => true])
            ->assertOk();

        Event::assertDispatched(
            TypingIndicator::class,
            fn (TypingIndicator $e) => $e->conversationId === $channel->id
                && $e->userId === $user->id
                && $e->typing === true,
        );

        $this->assertSame(0, ChatConversationMessage::count());
    }

    public function test_a_non_participant_cannot_announce_typing_in_a_private_channel(): void
    {
        $owner = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Secret', $owner, true);

        $this->actingAs($this->chatUser('chat.view'))
            ->postJson(route('admin.chat.typing', $channel))
            ->assertForbidden();
    }

    public function test_the_legacy_session_route_still_resolves_and_is_not_shadowed(): void
    {
        // `chat/channels` must not be swallowed by the legacy `chat/{chat}`
        // wildcard, and the wildcard itself must still work.
        $user = $this->chatUser('chat.view', 'chat.create_channel');

        $this->actingAs($user)
            ->postJson(route('admin.chat.channels.store'), ['name' => 'Routing'])
            ->assertCreated();
    }
}
