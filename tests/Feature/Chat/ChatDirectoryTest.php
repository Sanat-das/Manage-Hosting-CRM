<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\ChatConversation;
use App\Models\ChatParticipant;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesChatUsers;
use Tests\TestCase;

/**
 * Finding people and rooms.
 *
 * Until these endpoints existed the chat could create a channel and nothing
 * else: no way to add a second member to it, no way to find a public channel
 * somebody else made, and no way at all to open a direct message — the service
 * method for one had no route in front of it.
 */
class ChatDirectoryTest extends TestCase
{
    use CreatesChatUsers;
    use RefreshDatabase;

    private ChatService $chat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chat = app(ChatService::class);
    }

    // --- the staff roster ----------------------------------------------------

    public function test_the_roster_offers_chat_users_and_not_the_person_asking(): void
    {
        $me = $this->chatUser('chat.view');
        $colleague = $this->chatUser('chat.view');

        $people = $this->actingAs($me)->getJson(route('admin.chat.people'))
            ->assertOk()
            ->json('people');

        $ids = array_column($people, 'id');

        $this->assertContains($colleague->id, $ids);
        $this->assertNotContains($me->id, $ids, 'The roster offered a DM with oneself.');
    }

    public function test_the_roster_leaves_out_anyone_who_cannot_open_the_chat(): void
    {
        $me = $this->chatUser('chat.view');
        $client = User::factory()->create(['role' => 'client']);
        $staffWithoutChat = $this->chatUser();

        $ids = array_column(
            $this->actingAs($me)->getJson(route('admin.chat.people'))->assertOk()->json('people'),
            'id',
        );

        $this->assertNotContains($client->id, $ids);
        $this->assertNotContains(
            $staffWithoutChat->id,
            $ids,
            'A DM with someone who has no chat access is a room they can never open.',
        );
    }

    public function test_the_roster_leaves_out_deactivated_staff(): void
    {
        $me = $this->chatUser('chat.view');
        $gone = $this->chatUser('chat.view');
        $gone->update(['status' => 'inactive']);

        $ids = array_column(
            $this->actingAs($me)->getJson(route('admin.chat.people'))->assertOk()->json('people'),
            'id',
        );

        $this->assertNotContains($gone->id, $ids);
    }

    public function test_the_roster_can_be_searched_by_name_or_email(): void
    {
        $me = $this->chatUser('chat.view');
        $ana = $this->chatUser('chat.view');
        $ana->update(['first_name' => 'Ana', 'last_name' => 'Fernandes', 'email' => 'ana@example.com']);
        $bo = $this->chatUser('chat.view');
        $bo->update(['first_name' => 'Bo', 'last_name' => 'Larsen', 'email' => 'bo@example.com']);

        $byName = array_column(
            $this->actingAs($me)->getJson(route('admin.chat.people', ['q' => 'Fernand']))->assertOk()->json('people'),
            'id',
        );

        $this->assertSame([$ana->id], $byName);

        $byEmail = array_column(
            $this->actingAs($me)->getJson(route('admin.chat.people', ['q' => 'bo@example']))->assertOk()->json('people'),
            'id',
        );

        $this->assertSame([$bo->id], $byEmail);
    }

    public function test_the_add_member_roster_hides_people_already_in_the_room(): void
    {
        $owner = $this->chatUser('chat.view', 'chat.create_channel');
        $inside = $this->chatUser('chat.view');
        $outside = $this->chatUser('chat.view');

        $channel = $this->chat->createChannel('Deploys', $owner);
        $this->chat->addMember($channel, $inside);

        $ids = array_column(
            $this->actingAs($owner)
                ->getJson(route('admin.chat.people', ['conversation' => $channel->id]))
                ->assertOk()
                ->json('people'),
            'id',
        );

        $this->assertContains($outside->id, $ids);
        $this->assertNotContains($inside->id, $ids);
    }

    public function test_the_add_member_roster_is_refused_to_someone_who_cannot_add(): void
    {
        $owner = $this->chatUser('chat.view', 'chat.create_channel');
        $bystander = $this->chatUser('chat.view');

        $channel = $this->chat->createChannel('Deploys', $owner);

        $this->actingAs($bystander)
            ->getJson(route('admin.chat.people', ['conversation' => $channel->id]))
            ->assertForbidden();
    }

    // --- direct messages -----------------------------------------------------

    public function test_a_direct_message_can_be_opened_and_reopens_the_same_one(): void
    {
        $me = $this->chatUser('chat.view');
        $colleague = $this->chatUser('chat.view');

        $first = $this->actingAs($me)
            ->postJson(route('admin.chat.dms.store'), ['user_ids' => [$colleague->id]])
            ->assertCreated()
            ->json('conversation');

        $this->assertSame(ChatConversation::TYPE_DM, $first['type']);

        $conversation = ChatConversation::findOrFail($first['id']);
        $this->assertEqualsCanonicalizing(
            [$me->id, $colleague->id],
            $conversation->participants->pluck('user_id')->map(fn ($id) => (int) $id)->all(),
        );

        // Idempotent: asking again reopens the same history rather than
        // starting a second conversation beside it.
        $second = $this->actingAs($me)
            ->postJson(route('admin.chat.dms.store'), ['user_ids' => [$colleague->id]])
            ->assertOk()
            ->json('conversation');

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, ChatConversation::where('type', ChatConversation::TYPE_DM)->count());
    }

    public function test_the_other_side_of_a_direct_message_finds_it_in_their_own_sidebar(): void
    {
        $me = $this->chatUser('chat.view');
        // Named rather than faked: a generated surname with an apostrophe in it
        // is HTML-escaped in the sidebar and the assertion then fails on the
        // escaping rather than on anything about direct messages.
        $me->update(['first_name' => 'Ana', 'last_name' => 'Fernandes']);
        $colleague = $this->chatUser('chat.view');

        $this->actingAs($me)
            ->postJson(route('admin.chat.dms.store'), ['user_ids' => [$colleague->id]])
            ->assertCreated();

        $html = $this->actingAs($colleague)->get(route('admin.chat.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Ana Fernandes', $html);
    }

    public function test_three_people_get_a_group_message(): void
    {
        $me = $this->chatUser('chat.view');
        $ana = $this->chatUser('chat.view');
        $bo = $this->chatUser('chat.view');

        $conversation = $this->actingAs($me)
            ->postJson(route('admin.chat.dms.store'), [
                'user_ids' => [$ana->id, $bo->id],
                'name' => 'Release crew',
            ])
            ->assertCreated()
            ->json('conversation');

        $this->assertSame(ChatConversation::TYPE_GROUP_DM, $conversation['type']);
        $this->assertSame('Release crew', $conversation['name']);
        $this->assertSame(3, ChatConversation::findOrFail($conversation['id'])->participants()->count());
    }

    public function test_a_direct_message_with_someone_who_cannot_use_the_chat_is_refused(): void
    {
        $me = $this->chatUser('chat.view');
        $outsider = $this->chatUser();

        $this->actingAs($me)
            ->postJson(route('admin.chat.dms.store'), ['user_ids' => [$outsider->id]])
            ->assertStatus(422);

        $this->assertSame(0, ChatConversation::count());
    }

    public function test_a_direct_message_with_only_yourself_is_refused(): void
    {
        $me = $this->chatUser('chat.view');

        $this->actingAs($me)
            ->postJson(route('admin.chat.dms.store'), ['user_ids' => [$me->id]])
            ->assertStatus(422);

        $this->assertSame(0, ChatConversation::count());
    }

    public function test_opening_a_direct_message_needs_chat_access(): void
    {
        $outsider = $this->chatUser();
        $colleague = $this->chatUser('chat.view');

        $this->actingAs($outsider)
            ->postJson(route('admin.chat.dms.store'), ['user_ids' => [$colleague->id]])
            ->assertForbidden();
    }

    // --- the public channel directory ---------------------------------------

    public function test_the_directory_lists_public_channels_you_are_not_in(): void
    {
        $owner = $this->chatUser('chat.view', 'chat.create_channel');
        $stranger = $this->chatUser('chat.view');

        $open = $this->chat->createChannel('Announcements', $owner);
        $secret = $this->chat->createChannel('Secret Ops', $owner, true);
        $archived = $this->chat->createChannel('Old News', $owner);
        $this->chat->archive($archived);

        $channels = $this->actingAs($stranger)
            ->getJson(route('admin.chat.channels.browse'))
            ->assertOk()
            ->json('channels');

        $names = array_column($channels, 'name');

        $this->assertContains('Announcements', $names);
        $this->assertNotContains('Secret Ops', $names, 'A private channel was advertised to a non-member.');
        $this->assertNotContains('Old News', $names, 'An archived channel cannot be joined, so it is not on offer.');

        $this->assertFalse($channels[array_search('Announcements', $names, true)]['joined']);
        $this->assertSame($open->id, $channels[array_search('Announcements', $names, true)]['id']);
    }

    public function test_the_directory_marks_the_rooms_you_are_already_in(): void
    {
        $owner = $this->chatUser('chat.view', 'chat.create_channel');

        $this->chat->createChannel('Announcements', $owner);

        $channels = $this->actingAs($owner)->getJson(route('admin.chat.channels.browse'))
            ->assertOk()
            ->json('channels');

        $this->assertTrue($channels[0]['joined']);
        $this->assertSame(1, $channels[0]['members']);
    }

    public function test_the_directory_respects_department_scoping(): void
    {
        TicketDepartment::firstOrCreate(['slug' => 'billing'], ['name' => 'Billing', 'enabled' => true]);
        \App\Services\TicketService::forgetDepartmentCache();

        $owner = $this->chatUser('chat.view', 'chat.create_channel');
        $outsider = $this->chatUser('chat.view');

        $this->chat->createChannel('Billing Desk', $owner, false, 'billing');

        $names = array_column(
            $this->actingAs($outsider)->getJson(route('admin.chat.channels.browse'))->assertOk()->json('channels'),
            'name',
        );

        $this->assertNotContains('Billing Desk', $names);
    }

    public function test_a_public_channel_found_in_the_directory_can_then_be_joined(): void
    {
        $owner = $this->chatUser('chat.view', 'chat.create_channel');
        $stranger = $this->chatUser('chat.view');

        $channel = $this->chat->createChannel('Announcements', $owner);

        $this->actingAs($stranger)
            ->postJson(route('admin.chat.channels.join', $channel))
            ->assertOk()
            ->assertJsonPath('joined', true);

        $this->assertTrue(
            $channel->participants()->where('user_id', $stranger->id)->exists(),
            'Joining from the directory has to leave a seat behind, or nothing was joined.',
        );
    }

    // --- membership ----------------------------------------------------------

    public function test_the_member_list_says_who_is_in_and_what_the_caller_may_do(): void
    {
        $owner = $this->chatUser('chat.view', 'chat.create_channel');
        $member = $this->chatUser('chat.view');

        $channel = $this->chat->createChannel('Deploys', $owner);
        $this->chat->addMember($channel, $member);

        $payload = $this->actingAs($owner)
            ->getJson(route('admin.chat.channels.members.index', $channel))
            ->assertOk()
            ->json();

        $this->assertCount(2, $payload['members']);
        $this->assertTrue($payload['can_manage'], 'The channel admin has to be offered the member controls.');

        $mine = collect($payload['members'])->firstWhere('user_id', $owner->id);
        $this->assertTrue($mine['is_you']);
        $this->assertSame(ChatParticipant::ROLE_ADMIN, $mine['role']);

        // A plain member sees the same roster and none of the controls.
        $theirs = $this->actingAs($member)
            ->getJson(route('admin.chat.channels.members.index', $channel))
            ->assertOk()
            ->json();

        $this->assertCount(2, $theirs['members']);
        $this->assertFalse($theirs['can_manage']);
        $this->assertTrue($theirs['can_leave']);
    }

    public function test_the_member_list_of_a_private_channel_is_refused_to_outsiders(): void
    {
        $owner = $this->chatUser('chat.view', 'chat.create_channel');
        $outsider = $this->chatUser('chat.view');

        $channel = $this->chat->createChannel('Secret Ops', $owner, true);

        $this->actingAs($outsider)
            ->getJson(route('admin.chat.channels.members.index', $channel))
            ->assertForbidden();
    }

    /**
     * The AdminLTE package registers a Gate::before that answers true for every
     * ability an admin asks about, so "the policy says no" is not a refusal an
     * admin ever meets. These rules are structural and enforced in the
     * controller, which is the only place an admin passes through.
     */
    public function test_nobody_can_edit_the_membership_of_a_one_to_one_direct_message(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $colleague = $this->chatUser('chat.view');
        $third = $this->chatUser('chat.view');

        $dm = $this->chat->findOrCreateDirectMessage($admin, $colleague);

        $this->assertTrue($admin->can('addMember', $dm), 'The admin gate bypass is what this test exists for.');

        $this->actingAs($admin)
            ->postJson(route('admin.chat.channels.members.store', $dm), ['user_id' => $third->id])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->deleteJson(route('admin.chat.channels.members.destroy', [$dm, $colleague]))
            ->assertStatus(422);

        $this->actingAs($admin)
            ->postJson(route('admin.chat.channels.leave', $dm))
            ->assertStatus(422);

        $this->assertSame(2, $dm->participants()->count());
    }

    public function test_the_member_panel_offers_no_membership_controls_on_a_direct_message(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $colleague = $this->chatUser('chat.view');

        $dm = $this->chat->findOrCreateDirectMessage($admin, $colleague);

        $payload = $this->actingAs($admin)
            ->getJson(route('admin.chat.channels.members.index', $dm))
            ->assertOk()
            ->json();

        $this->assertFalse($payload['can_manage']);
        $this->assertFalse($payload['can_leave']);
        $this->assertFalse($payload['can_join']);
        $this->assertCount(2, $payload['members']);
    }

    public function test_join_is_not_offered_in_a_channel_you_are_already_in(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $channel = $this->chat->createChannel('Announcements', $admin);

        $payload = $this->actingAs($admin)
            ->getJson(route('admin.chat.channels.members.index', $channel))
            ->assertOk()
            ->json();

        $this->assertFalse($payload['can_join'], 'Join was offered in a room the caller is already in.');
        $this->assertTrue($payload['can_leave']);
    }

    public function test_an_archived_channel_cannot_be_joined(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = $this->chatUser('chat.view', 'chat.create_channel');

        $channel = $this->chat->createChannel('Old News', $owner);
        $this->chat->archive($channel);

        $this->actingAs($admin)
            ->postJson(route('admin.chat.channels.join', $channel))
            ->assertStatus(422);
    }

    public function test_a_channel_creator_can_add_a_second_member_and_remove_them_again(): void
    {
        $owner = $this->chatUser('chat.view', 'chat.create_channel');
        $colleague = $this->chatUser('chat.view');

        $channel = $this->chat->createChannel('Deploys', $owner, true);

        $this->actingAs($owner)
            ->postJson(route('admin.chat.channels.members.store', $channel), ['user_id' => $colleague->id])
            ->assertCreated()
            ->assertJsonPath('members', 2);

        // The point of the whole exercise: an invited member can now read a
        // private room they could not see before.
        $this->actingAs($colleague)->getJson(route('admin.chat.messages.index', $channel))->assertOk();

        $this->actingAs($owner)
            ->deleteJson(route('admin.chat.channels.members.destroy', [$channel, $colleague]))
            ->assertOk()
            ->assertJsonPath('members', 1);

        $this->actingAs($colleague)->getJson(route('admin.chat.messages.index', $channel))->assertForbidden();
    }
}
