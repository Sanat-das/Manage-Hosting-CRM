<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\ChatConversation;
use App\Models\ChatConversationMessage;
use App\Models\ChatParticipant;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Services\ChatService;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\Concerns\CreatesChatUsers;
use Tests\Concerns\CreatesPanelUsers;
use Tests\TestCase;

/**
 * ChatService: the invariants every caller depends on, and the policy rules the
 * HTTP layer and the websocket channels both resolve through.
 */
class ChatServiceTest extends TestCase
{
    use CreatesChatUsers;
    use CreatesPanelUsers;
    use RefreshDatabase;

    private ChatService $chat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chat = app(ChatService::class);
    }

    public function test_creating_a_channel_puts_its_creator_in_it_as_an_admin(): void
    {
        $creator = $this->chatUser('chat.view', 'chat.create_channel');

        $channel = $this->chat->createChannel('Deploys', $creator);

        $this->assertSame(ChatConversation::TYPE_CHANNEL, $channel->type);
        $this->assertSame('deploys', $channel->slug);
        $this->assertFalse($channel->is_private);

        $participant = $channel->participants()->firstOrFail();
        $this->assertSame($creator->id, $participant->user_id);
        $this->assertTrue($participant->isAdmin());
        $this->assertNotNull($participant->joined_at);
    }

    public function test_a_clashing_channel_name_gets_a_suffixed_slug_rather_than_a_unique_violation(): void
    {
        $creator = $this->chatUser('chat.create_channel');

        $first = $this->chat->createChannel('Billing', $creator);
        $second = $this->chat->createChannel('Billing', $creator);

        $this->assertSame('billing', $first->slug);
        $this->assertSame('billing-2', $second->slug);
    }

    public function test_a_channel_cannot_be_created_without_a_name(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->chat->createChannel('   ', $this->chatUser('chat.create_channel'));
    }

    public function test_an_unknown_department_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->chat->createChannel(
            'Ghosts',
            $this->chatUser('chat.create_channel'),
            false,
            'no-such-department',
        );
    }

    public function test_a_known_department_is_accepted_and_stored_as_a_string(): void
    {
        $department = TicketDepartment::create([
            'name' => 'Billing Desk',
            'slug' => 'billing-desk',
            'enabled' => true,
        ]);
        TicketService::forgetDepartmentCache();

        $channel = $this->chat->createChannel(
            'Billing room',
            $this->chatUser('chat.create_channel'),
            false,
            'billing-desk',
        );

        $this->assertSame('billing-desk', $channel->department);
        $this->assertSame($department->slug, $channel->fresh()->department);
    }

    public function test_opening_the_same_direct_message_twice_reuses_one_conversation(): void
    {
        $alice = $this->chatUser('chat.view');
        $bob = $this->chatUser('chat.view');

        $first = $this->chat->findOrCreateDirectMessage($alice, $bob);
        $second = $this->chat->findOrCreateDirectMessage($bob, $alice);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, ChatConversation::where('type', ChatConversation::TYPE_DM)->count());
        $this->assertSame(2, $first->participants()->count());
    }

    public function test_a_direct_message_needs_two_different_people(): void
    {
        $alice = $this->chatUser('chat.view');

        $this->expectException(InvalidArgumentException::class);

        $this->chat->findOrCreateDirectMessage($alice, $alice);
    }

    public function test_a_group_direct_message_needs_at_least_three_people(): void
    {
        $creator = $this->chatUser('chat.view');
        $other = $this->chatUser('chat.view');

        $this->expectException(InvalidArgumentException::class);

        $this->chat->createGroupDirectMessage([$other], $creator);
    }

    public function test_a_group_direct_message_includes_its_creator_once(): void
    {
        $creator = $this->chatUser('chat.view');
        $members = collect(range(1, 3))->map(fn () => $this->chatUser('chat.view'));

        $group = $this->chat->createGroupDirectMessage($members->push($creator), $creator, 'Release crew');

        $this->assertSame(ChatConversation::TYPE_GROUP_DM, $group->type);
        $this->assertSame(4, $group->participants()->count());
        $this->assertSame('Release crew', $group->name);
    }

    public function test_adding_an_existing_member_is_idempotent(): void
    {
        $creator = $this->chatUser('chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $creator);
        $bob = $this->chatUser('chat.view');

        $first = $this->chat->addMember($channel, $bob);
        $second = $this->chat->addMember($channel, $bob);

        $this->assertTrue($first->is($second));
        $this->assertSame(2, $channel->participants()->count());
    }

    public function test_removing_a_member_keeps_their_messages(): void
    {
        $creator = $this->chatUser('chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $creator);
        $bob = $this->chatUser('chat.view');
        $this->chat->addMember($channel, $bob);

        $message = $this->chat->sendMessage($channel, $bob, 'I was here.');

        $this->chat->removeMember($channel, $bob);

        $this->assertSame(1, $channel->participants()->count());
        $this->assertDatabaseHas('chat_conversation_messages', ['id' => $message->id, 'body' => 'I was here.']);
    }

    public function test_archiving_freezes_a_conversation_without_deleting_anything(): void
    {
        $creator = $this->chatUser('chat.create_channel');
        $channel = $this->chat->createChannel('Old project', $creator);
        $this->chat->sendMessage($channel, $creator, 'Wrapping up.');

        $this->chat->archive($channel);

        $this->assertTrue($channel->fresh()->isArchived());
        $this->assertSame(1, $channel->messages()->count());

        $this->expectException(RuntimeException::class);
        $this->chat->sendMessage($channel->fresh(), $creator, 'One more thing.');
    }

    public function test_unarchiving_lets_messages_flow_again(): void
    {
        $creator = $this->chatUser('chat.create_channel');
        $channel = $this->chat->createChannel('Revived', $creator);

        $this->chat->archive($channel);
        $this->chat->unarchive($channel);

        $message = $this->chat->sendMessage($channel->fresh(), $creator, 'Back.');

        $this->assertNotNull($message->id);
    }

    public function test_sending_a_message_marks_it_read_for_its_own_author(): void
    {
        $creator = $this->chatUser('chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $creator);

        $message = $this->chat->sendMessage($channel, $creator, 'First.');

        $this->assertSame(
            $message->id,
            $channel->participants()->where('user_id', $creator->id)->value('last_read_message_id'),
            "An author's own message must not come back to them as unread.",
        );
    }

    public function test_an_empty_or_oversized_message_is_refused(): void
    {
        $creator = $this->chatUser('chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $creator);

        try {
            $this->chat->sendMessage($channel, $creator, "   \n  ");
            $this->fail('An empty message was accepted.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->expectException(InvalidArgumentException::class);
        $this->chat->sendMessage($channel, $creator, str_repeat('a', ChatService::MAX_BODY_LENGTH + 1));
    }

    public function test_threads_are_one_level_deep(): void
    {
        $creator = $this->chatUser('chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $creator);

        $root = $this->chat->sendMessage($channel, $creator, 'Question?');
        $reply = $this->chat->sendMessage($channel, $creator, 'Answer.', $root->id);
        $replyToReply = $this->chat->sendMessage($channel, $creator, 'Follow-up.', $reply->id);

        $this->assertSame($root->id, $reply->parent_id);
        $this->assertSame(
            $root->id,
            $replyToReply->parent_id,
            'Replying to a reply must attach to the thread root, not build a tree.',
        );
    }

    public function test_a_reply_cannot_cross_into_another_conversation(): void
    {
        $creator = $this->chatUser('chat.create_channel');
        $here = $this->chat->createChannel('Here', $creator);
        $there = $this->chat->createChannel('There', $creator);

        $elsewhere = $this->chat->sendMessage($there, $creator, 'Over there.');

        $this->expectException(InvalidArgumentException::class);
        $this->chat->sendMessage($here, $creator, 'Wrong room.', $elsewhere->id);
    }

    public function test_editing_stamps_edited_at_and_deleting_is_soft(): void
    {
        $creator = $this->chatUser('chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $creator);
        $message = $this->chat->sendMessage($channel, $creator, 'Typo heer');

        $this->chat->editMessage($message, 'Typo here');

        $this->assertSame('Typo here', $message->fresh()->body);
        $this->assertTrue($message->fresh()->isEdited());

        $this->chat->deleteMessage($message);

        $this->assertSoftDeleted('chat_conversation_messages', ['id' => $message->id]);
    }

    public function test_a_guest_message_stores_its_token_and_no_user(): void
    {
        $inbox = ChatConversation::factory()->customerInbox()->create();

        $message = $this->chat->sendMessage($inbox, null, 'Hello, is anyone there?', null, 'guest-token-value');

        $this->assertNull($message->user_id);
        $this->assertSame('guest-token-value', $message->fresh()->guest_token);
    }

    public function test_a_message_needs_an_author_or_a_guest_token(): void
    {
        $inbox = ChatConversation::factory()->customerInbox()->create();

        $this->expectException(InvalidArgumentException::class);

        $this->chat->sendMessage($inbox, null, 'Who am I?');
    }

    // --- policy -----------------------------------------------------------

    public function test_a_non_participant_cannot_view_a_private_channel(): void
    {
        $creator = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Secret', $creator, true);
        $outsider = $this->chatUser('chat.view');

        $this->assertTrue($creator->can('view', $channel));
        $this->assertFalse($outsider->can('view', $channel));
    }

    public function test_an_invited_member_can_view_and_send_in_a_private_channel(): void
    {
        $creator = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Secret', $creator, true);
        $invited = $this->chatUser('chat.view');

        $this->chat->addMember($channel, $invited);

        $this->assertTrue($invited->fresh()->can('view', $channel));
        $this->assertTrue($invited->fresh()->can('sendMessage', $channel));
    }

    public function test_a_public_channel_is_readable_without_joining_but_not_writable(): void
    {
        $creator = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('General', $creator);
        $bystander = $this->chatUser('chat.view');

        $this->assertTrue($bystander->can('view', $channel));
        $this->assertFalse($bystander->can('sendMessage', $channel), 'Reading a public channel is not joining it.');
        $this->assertTrue($bystander->can('join', $channel));
    }

    public function test_a_private_channel_cannot_be_self_joined(): void
    {
        $creator = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Secret', $creator, true);
        $outsider = $this->chatUser('chat.view');

        $this->assertFalse($outsider->can('join', $channel));
    }

    public function test_a_department_channel_is_limited_to_that_departments_staff(): void
    {
        TicketDepartment::create(['name' => 'Billing Desk', 'slug' => 'billing-desk', 'enabled' => true]);
        TicketService::forgetDepartmentCache();

        $creator = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Billing room', $creator, false, 'billing-desk');

        $outsider = $this->chatUser('chat.view');
        $this->assertFalse($outsider->can('view', $channel), 'A department channel leaked to staff outside the department.');

        $insider = $this->chatUser('chat.view');
        $insider->ticketDepartments()->attach(TicketDepartment::where('slug', 'billing-desk')->value('id'));

        $this->assertTrue($insider->fresh()->can('view', $channel));
    }

    public function test_a_customer_inbox_is_invisible_to_ordinary_staff_and_open_to_operators(): void
    {
        $inbox = ChatConversation::factory()->customerInbox()->create();

        $staff = $this->chatUser('chat.view');
        $operator = $this->chatUser('chat.view', 'chat.manage');

        $this->assertFalse($staff->can('view', $inbox));
        $this->assertTrue($operator->can('view', $inbox));
        $this->assertTrue($operator->can('operate', $inbox));
        $this->assertFalse($staff->can('operate', $inbox));
    }

    public function test_only_the_author_may_edit_but_a_moderator_may_delete(): void
    {
        $author = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $author);
        $other = $this->chatUser('chat.view');
        $moderator = $this->chatUser('chat.view', 'chat.manage');
        $this->chat->addMember($channel, $other);

        $message = $this->chat->sendMessage($channel, $author, 'Mine.');

        $this->assertTrue($author->can('update', $message));
        $this->assertFalse($other->fresh()->can('update', $message));
        $this->assertFalse(
            $moderator->can('update', $message),
            'A moderator rewriting someone else\'s words under their name is forgery.',
        );

        $this->assertTrue($author->can('delete', $message));
        $this->assertTrue($moderator->can('delete', $message));
        $this->assertFalse($other->fresh()->can('delete', $message));
    }

    public function test_an_archived_conversation_refuses_edits_deletes_and_reactions(): void
    {
        $author = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $author);
        $message = $this->chat->sendMessage($channel, $author, 'Said once.');

        $this->chat->archive($channel);
        $message = ChatConversationMessage::with('conversation')->find($message->id);

        $this->assertFalse($author->can('update', $message));
        $this->assertFalse($author->can('delete', $message));
        $this->assertFalse($author->can('react', $message));
    }

    public function test_a_deleted_message_cannot_be_edited_or_reacted_to(): void
    {
        $author = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $author);
        $message = $this->chat->sendMessage($channel, $author, 'Oops.');

        $this->chat->deleteMessage($message);
        $message = ChatConversationMessage::withTrashed()->with('conversation')->find($message->id);

        $this->assertFalse($author->can('update', $message));
        $this->assertFalse($author->can('delete', $message));
        $this->assertFalse($author->can('react', $message));
    }

    public function test_only_a_creator_admin_or_manager_may_rename_or_archive(): void
    {
        $creator = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $creator);

        $member = $this->chatUser('chat.view');
        $this->chat->addMember($channel, $member);

        $manager = $this->chatUser('chat.view', 'chat.manage');

        $this->assertTrue($creator->can('update', $channel));
        $this->assertTrue($manager->can('update', $channel));
        $this->assertFalse($member->fresh()->can('update', $channel));
        $this->assertFalse($member->fresh()->can('archive', $channel));
    }

    public function test_creating_a_channel_requires_the_permission(): void
    {
        $allowed = $this->chatUser('chat.create_channel');
        $denied = $this->chatUser('chat.view');

        $this->assertTrue($allowed->can('create', ChatConversation::class));
        $this->assertFalse($denied->can('create', ChatConversation::class));
    }

    public function test_a_participant_row_is_what_the_policy_reads(): void
    {
        $user = $this->chatUser('chat.view');
        $conversation = ChatConversation::factory()->private()->create();

        $this->assertFalse($user->can('view', $conversation));

        ChatParticipant::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
        ]);

        $this->assertTrue($user->fresh()->can('view', $conversation));
    }

    public function test_users_without_chat_view_see_nothing(): void
    {
        $user = $this->panelUserWithoutPermission('chat.view');
        $channel = ChatConversation::factory()->create();

        $this->assertFalse($user->can('view', $channel));
        $this->assertFalse($user->can('viewAny', ChatConversation::class));
        $this->assertFalse($user->can('join', $channel));
    }

    public function test_the_group_direct_message_cap_is_enforced(): void
    {
        $creator = $this->chatUser('chat.view');
        $group = ChatConversation::factory()->groupDm()->create();

        User::factory()->count(ChatService::MAX_GROUP_DM_PARTICIPANTS)->create()
            ->each(fn (User $u) => ChatParticipant::factory()->create([
                'conversation_id' => $group->id,
                'user_id' => $u->id,
            ]));

        $this->expectException(RuntimeException::class);

        $this->chat->addMember($group, $creator);
    }
}
