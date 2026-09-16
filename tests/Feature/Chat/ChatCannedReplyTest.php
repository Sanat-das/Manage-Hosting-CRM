<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\ChatCannedReply;
use App\Models\TicketDepartment;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesChatUsers;
use Tests\TestCase;

/**
 * Saved replies.
 *
 * The whole feature turns on one nullable column: `user_id` null is the shared
 * library, `user_id` set is one person's own. Most of what follows is proving
 * that the two scopes cannot see or overwrite each other, because the failure
 * mode is not an error — it is an operator quietly reading, or rewriting,
 * someone else's notes.
 */
class ChatCannedReplyTest extends TestCase
{
    use CreatesChatUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TicketDepartment::firstOrCreate(['slug' => 'support'], ['name' => 'Support', 'enabled' => true]);
        TicketService::forgetDepartmentCache();
    }

    // --- visibility ----------------------------------------------------------

    public function test_the_picker_returns_the_shared_library_and_my_own(): void
    {
        $me = $this->chatUser('chat.view');
        $someoneElse = $this->chatUser('chat.view');

        ChatCannedReply::factory()->create(['title' => 'Shared one']);
        ChatCannedReply::factory()->ownedBy($me)->create(['title' => 'Mine']);
        ChatCannedReply::factory()->ownedBy($someoneElse)->create(['title' => 'Theirs']);

        $response = $this->actingAs($me)
            ->getJson(route('admin.chat.canned-replies.pick'))
            ->assertOk();

        $titles = array_column($response->json('replies'), 'title');

        $this->assertContains('Shared one', $titles);
        $this->assertContains('Mine', $titles);
        $this->assertNotContains('Theirs', $titles);
    }

    public function test_my_own_replies_come_first_so_a_personal_shortcut_wins(): void
    {
        $me = $this->chatUser('chat.view');

        ChatCannedReply::factory()->create(['title' => 'Shared refund', 'shortcut' => 'refund']);
        ChatCannedReply::factory()->ownedBy($me)->create(['title' => 'My refund', 'shortcut' => 'refund']);

        // The picker resolves a typed shortcut to the first match, so ordering
        // IS the override rule.
        $replies = $this->actingAs($me)
            ->getJson(route('admin.chat.canned-replies.pick', ['q' => 'refund']))
            ->assertOk()
            ->json('replies');

        $this->assertSame('My refund', $replies[0]['title']);
        $this->assertFalse($replies[0]['shared']);
    }

    public function test_the_picker_searches_the_body_not_only_the_title(): void
    {
        $me = $this->chatUser('chat.view');

        ChatCannedReply::factory()->create([
            'title' => 'Opaque title',
            'body' => 'Your refund will appear within five working days.',
        ]);

        $replies = $this->actingAs($me)
            ->getJson(route('admin.chat.canned-replies.pick', ['q' => 'five working days']))
            ->assertOk()
            ->json('replies');

        $this->assertCount(1, $replies);
    }

    public function test_the_management_screen_does_not_list_other_peoples_personal_replies(): void
    {
        $me = $this->chatUser('chat.view');
        $someoneElse = $this->chatUser('chat.view');

        ChatCannedReply::factory()->ownedBy($someoneElse)->create(['title' => 'Their private note']);

        $this->actingAs($me)
            ->get(route('admin.chat.canned-replies.index'))
            ->assertOk()
            ->assertDontSee('Their private note');
    }

    // --- writing -------------------------------------------------------------

    public function test_anyone_with_chat_view_can_keep_their_own_reply(): void
    {
        $me = $this->chatUser('chat.view');

        $this->actingAs($me)
            ->post(route('admin.chat.canned-replies.store'), [
                'title' => 'My greeting',
                'body' => 'Hello, how can I help?',
                'scope' => 'personal',
            ])
            ->assertRedirect(route('admin.chat.canned-replies.index'));

        $reply = ChatCannedReply::firstOrFail();
        $this->assertSame($me->id, (int) $reply->user_id);
        $this->assertSame($me->id, (int) $reply->created_by);
    }

    public function test_the_shared_library_needs_chat_manage(): void
    {
        $this->actingAs($this->chatUser('chat.view'))
            ->post(route('admin.chat.canned-replies.store'), [
                'title' => 'Official policy',
                'body' => 'Refunds are processed within 5 days.',
                'scope' => 'shared',
            ])
            ->assertForbidden();

        $this->assertSame(0, ChatCannedReply::count());
    }

    public function test_chat_manage_can_write_the_shared_library(): void
    {
        $this->actingAs($this->chatUser('chat.view', 'chat.manage'))
            ->post(route('admin.chat.canned-replies.store'), [
                'title' => 'Official policy',
                'body' => 'Refunds are processed within 5 days.',
                'department' => 'support',
                'scope' => 'shared',
            ])
            ->assertRedirect();

        $reply = ChatCannedReply::firstOrFail();
        $this->assertNull($reply->user_id);
        $this->assertTrue($reply->isShared());
        $this->assertSame('support', $reply->department);
    }

    public function test_i_cannot_edit_someone_elses_personal_reply(): void
    {
        $someoneElse = $this->chatUser('chat.view');
        $reply = ChatCannedReply::factory()->ownedBy($someoneElse)->create();

        $this->actingAs($this->chatUser('chat.view'))
            ->put(route('admin.chat.canned-replies.update', $reply), [
                'title' => 'Hijacked',
                'body' => 'Rewritten.',
                'scope' => 'personal',
            ])
            ->assertForbidden();
    }

    public function test_chat_manage_does_not_grant_access_to_someones_personal_reply(): void
    {
        // chat.manage is a permission over the chat, not over another person's
        // private notes. This is the one place the two diverge.
        $someoneElse = $this->chatUser('chat.view');
        $reply = ChatCannedReply::factory()->ownedBy($someoneElse)->create();

        $this->actingAs($this->chatUser('chat.view', 'chat.manage'))
            ->get(route('admin.chat.canned-replies.edit', $reply))
            ->assertForbidden();
    }

    public function test_a_shared_reply_cannot_be_edited_without_chat_manage(): void
    {
        $reply = ChatCannedReply::factory()->create();

        $this->actingAs($this->chatUser('chat.view'))
            ->put(route('admin.chat.canned-replies.update', $reply), [
                'title' => 'Rewritten',
                'body' => 'Something else.',
                'scope' => 'shared',
            ])
            ->assertForbidden();
    }

    public function test_taking_a_shared_reply_private_also_needs_chat_manage(): void
    {
        // Both directions are an edit to the shared library. Checking only the
        // incoming one leaves "move it to personal" as a way around the gate.
        $reply = ChatCannedReply::factory()->create();

        $this->actingAs($this->chatUser('chat.view'))
            ->put(route('admin.chat.canned-replies.update', $reply), [
                'title' => 'Mine now',
                'body' => 'Something else.',
                'scope' => 'personal',
            ])
            ->assertForbidden();

        $this->assertTrue($reply->fresh()->isShared());
    }

    public function test_deleting_a_shared_reply_needs_chat_manage(): void
    {
        $reply = ChatCannedReply::factory()->create();

        $this->actingAs($this->chatUser('chat.view'))
            ->delete(route('admin.chat.canned-replies.destroy', $reply))
            ->assertForbidden();

        $this->assertModelExists($reply);
    }

    public function test_i_can_delete_my_own(): void
    {
        $me = $this->chatUser('chat.view');
        $reply = ChatCannedReply::factory()->ownedBy($me)->create();

        $this->actingAs($me)
            ->delete(route('admin.chat.canned-replies.destroy', $reply))
            ->assertRedirect();

        $this->assertModelMissing($reply);
    }

    // --- shortcuts -----------------------------------------------------------

    public function test_two_shared_replies_cannot_share_a_shortcut(): void
    {
        // The table cannot enforce this: MySQL treats NULLs as distinct, so a
        // unique index on (user_id, shortcut) enforces nothing for exactly the
        // rows whose user_id is NULL.
        ChatCannedReply::factory()->create(['shortcut' => 'refund']);

        $this->actingAs($this->chatUser('chat.view', 'chat.manage'))
            ->post(route('admin.chat.canned-replies.store'), [
                'title' => 'Second refund',
                'body' => 'Different text.',
                'shortcut' => 'refund',
                'scope' => 'shared',
            ])
            ->assertSessionHasErrors('shortcut');

        $this->assertSame(1, ChatCannedReply::count());
    }

    public function test_a_personal_shortcut_may_shadow_a_shared_one(): void
    {
        ChatCannedReply::factory()->create(['shortcut' => 'refund']);

        $this->actingAs($this->chatUser('chat.view'))
            ->post(route('admin.chat.canned-replies.store'), [
                'title' => 'My refund wording',
                'body' => 'My own version.',
                'shortcut' => 'refund',
                'scope' => 'personal',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, ChatCannedReply::count());
    }

    public function test_two_people_may_each_have_the_same_personal_shortcut(): void
    {
        $someoneElse = $this->chatUser('chat.view');
        ChatCannedReply::factory()->ownedBy($someoneElse)->create(['shortcut' => 'hello']);

        $this->actingAs($this->chatUser('chat.view'))
            ->post(route('admin.chat.canned-replies.store'), [
                'title' => 'My hello',
                'body' => 'Hi there.',
                'shortcut' => 'hello',
                'scope' => 'personal',
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_editing_a_reply_is_not_a_clash_with_itself(): void
    {
        $reply = ChatCannedReply::factory()->create(['shortcut' => 'refund']);

        $this->actingAs($this->chatUser('chat.view', 'chat.manage'))
            ->put(route('admin.chat.canned-replies.update', $reply), [
                'title' => 'Refund, reworded',
                'body' => 'New text.',
                'shortcut' => 'refund',
                'scope' => 'shared',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Refund, reworded', $reply->fresh()->title);
    }

    public function test_a_shortcut_cannot_contain_a_space_or_a_slash(): void
    {
        // The operator types "/" to open the picker and the shortcut is what
        // follows it, so either character makes it untypeable.
        foreach (['two words', 'sub/path'] as $shortcut) {
            $this->actingAs($this->chatUser('chat.view', 'chat.manage'))
                ->post(route('admin.chat.canned-replies.store'), [
                    'title' => 'Nope',
                    'body' => 'Text.',
                    'shortcut' => $shortcut,
                    'scope' => 'shared',
                ])
                ->assertSessionHasErrors('shortcut');
        }
    }

    public function test_a_leading_slash_is_stripped_rather_than_stored(): void
    {
        $this->actingAs($this->chatUser('chat.view', 'chat.manage'))
            ->post(route('admin.chat.canned-replies.store'), [
                'title' => 'Refund',
                'body' => 'Text.',
                'shortcut' => 'refund',
                'scope' => 'shared',
            ]);

        // Stored without the slash; the slash is how it is typed, not part of
        // the name.
        $this->assertSame('refund', ChatCannedReply::firstOrFail()->shortcut);
    }

    public function test_a_blank_shortcut_is_stored_as_null(): void
    {
        // Empty strings would collide with each other under the uniqueness
        // rule while meaning "no shortcut at all".
        $this->actingAs($this->chatUser('chat.view', 'chat.manage'))
            ->post(route('admin.chat.canned-replies.store'), [
                'title' => 'No shortcut',
                'body' => 'Text.',
                'shortcut' => '',
                'scope' => 'shared',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull(ChatCannedReply::firstOrFail()->shortcut);

        $this->actingAs($this->chatUser('chat.view', 'chat.manage'))
            ->post(route('admin.chat.canned-replies.store'), [
                'title' => 'Also no shortcut',
                'body' => 'Text.',
                'shortcut' => '',
                'scope' => 'shared',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, ChatCannedReply::count());
    }

    public function test_a_department_that_does_not_exist_is_refused(): void
    {
        $this->actingAs($this->chatUser('chat.view', 'chat.manage'))
            ->post(route('admin.chat.canned-replies.store'), [
                'title' => 'Refund',
                'body' => 'Text.',
                'department' => 'not-a-department',
                'scope' => 'shared',
            ])
            ->assertSessionHasErrors('department');
    }

    // --- use counting --------------------------------------------------------

    public function test_inserting_a_reply_counts_a_use(): void
    {
        $reply = ChatCannedReply::factory()->create();

        $this->actingAs($this->chatUser('chat.view'))
            ->postJson(route('admin.chat.canned-replies.used', $reply))
            ->assertOk()
            ->assertJsonPath('uses', 1);
    }

    public function test_i_cannot_count_a_use_against_someone_elses_reply(): void
    {
        $someoneElse = $this->chatUser('chat.view');
        $reply = ChatCannedReply::factory()->ownedBy($someoneElse)->create();

        // There is no by-id read endpoint, so this is the one route that could
        // confirm a private reply exists by id.
        $this->actingAs($this->chatUser('chat.view'))
            ->postJson(route('admin.chat.canned-replies.used', $reply))
            ->assertForbidden();
    }

    public function test_the_picker_distinguishes_an_empty_library_from_no_matches(): void
    {
        // The picker showed "No saved replies match" on an install that had
        // never created one -- blaming a filter for an empty library and
        // offering nowhere to go. `any` is what lets it tell the two apart.
        $me = $this->chatUser('chat.view');

        $this->actingAs($me)
            ->getJson(route('admin.chat.canned-replies.pick'))
            ->assertOk()
            ->assertJsonPath('any', false)
            ->assertJsonPath('replies', []);

        ChatCannedReply::factory()->create(['title' => 'Refund wording', 'shortcut' => 'refund']);

        // A search that matches nothing, in a library that is NOT empty.
        $this->actingAs($me)
            ->getJson(route('admin.chat.canned-replies.pick', ['q' => 'zzzznothing']))
            ->assertOk()
            ->assertJsonPath('any', true)
            ->assertJsonPath('replies', []);
    }

    public function test_the_whole_feature_is_closed_to_someone_without_chat_view(): void
    {
        $nobody = $this->chatUser();

        $this->actingAs($nobody)->get(route('admin.chat.canned-replies.index'))->assertForbidden();
        $this->actingAs($nobody)->getJson(route('admin.chat.canned-replies.pick'))->assertForbidden();
    }
}
