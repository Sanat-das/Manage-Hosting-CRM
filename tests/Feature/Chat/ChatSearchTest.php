<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\ChatConversation;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesChatUsers;
use Tests\TestCase;

/**
 * GET /admin/chat/search.
 *
 * The security core of this endpoint is the ORDER of the query: the set of
 * conversations the caller may read is resolved FIRST and applied as a
 * `whereIn`, and only then does the `LIKE '%q%'` run. Half of this file exists
 * to prove that a non-participant cannot pull a private channel's text out of
 * the index by guessing at its contents.
 *
 * The other half is input handling. `%` and `_` are LIKE metacharacters: if
 * they reach the pattern unescaped, `q=%` stops being a search and becomes
 * "dump every message this user can see", which is a real leak dressed up as a
 * feature.
 */
class ChatSearchTest extends TestCase
{
    use CreatesChatUsers;
    use RefreshDatabase;

    private ChatService $chat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chat = app(ChatService::class);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function search(User $as, array $params): TestResponse
    {
        return $this->actingAs($as)->getJson(route('admin.chat.search', $params));
    }

    /**
     * The acceptance fixture: 5 messages across 2 channels, 2 of them matching
     * "hello", split 1/1 across the channels.
     *
     * @return array{0: User, 1: ChatConversation, 2: ChatConversation}
     */
    private function fiveMessages(): array
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');

        $ops = $this->chat->createChannel('Ops', $user);
        $random = $this->chat->createChannel('Random', $user);

        $this->chat->sendMessage($ops, $user, 'hello there team');
        $this->chat->sendMessage($ops, $user, 'unrelated chatter');
        $this->chat->sendMessage($ops, $user, 'deploy is green');
        $this->chat->sendMessage($random, $user, 'say hello to the new hire');
        $this->chat->sendMessage($random, $user, 'lunch at one');

        return [$user, $ops, $random];
    }

    // --- acceptance criteria ----------------------------------------------

    public function test_search_returns_only_the_matching_messages(): void
    {
        [$user] = $this->fiveMessages();

        $response = $this->search($user, ['q' => 'hello'])->assertOk();

        $this->assertSame(2, $response->json('total'));
        $this->assertCount(2, $response->json('results'));

        foreach ($response->json('results') as $hit) {
            $this->assertStringContainsStringIgnoringCase('hello', $hit['body']);
        }
    }

    public function test_filtering_by_channel_narrows_the_result_set(): void
    {
        [$user, $ops] = $this->fiveMessages();

        $response = $this->search($user, ['q' => 'hello', 'channel' => $ops->id])->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame($ops->id, $response->json('results.0.conversation_id'));
        $this->assertSame('hello there team', $response->json('results.0.body'));
    }

    /**
     * THE test of this task. If the participant scoping were removed from the
     * query, this assertion is the one that fails.
     */
    public function test_a_non_participant_never_sees_private_channel_messages(): void
    {
        $owner = $this->chatUser('chat.view', 'chat.create_channel');
        $private = $this->chat->createChannel('Founders', $owner, true);
        $this->chat->sendMessage($private, $owner, 'hello the acquisition closes friday');

        // Guard against a vacuous pass: the room's own member MUST find it.
        $this->assertSame(1, $this->search($owner, ['q' => 'acquisition'])->assertOk()->json('total'));

        $outsider = $this->chatUser('chat.view');

        $response = $this->search($outsider, ['q' => 'acquisition'])->assertOk();

        $this->assertSame(0, $response->json('total'));
        $this->assertSame([], $response->json('results'));
    }

    /**
     * Naming a private channel explicitly must not be a way in either — the
     * filter narrows an already-authorised set, it never widens it.
     */
    public function test_naming_a_private_channel_directly_does_not_widen_the_scope(): void
    {
        $owner = $this->chatUser('chat.view', 'chat.create_channel');
        $private = $this->chat->createChannel('Founders', $owner, true);
        $this->chat->sendMessage($private, $owner, 'hello the acquisition closes friday');

        $outsider = $this->chatUser('chat.view');

        $response = $this->search($outsider, ['q' => 'acquisition', 'channel' => $private->id])->assertOk();

        $this->assertSame(0, $response->json('total'));
    }

    /**
     * A DM between two other people is not searchable by a third party.
     */
    public function test_a_third_party_cannot_search_someone_elses_dm(): void
    {
        $a = $this->chatUser('chat.view', 'chat.create_channel');
        $b = $this->chatUser('chat.view');

        $dm = $this->chat->createChannel('Side chat', $a, true);
        $this->chat->addMember($dm, $b);
        $dm->forceFill(['type' => ChatConversation::TYPE_DM])->save();

        $this->chat->sendMessage($dm, $a, 'hello salary review notes');

        $this->assertSame(1, $this->search($b, ['q' => 'salary'])->assertOk()->json('total'));

        $third = $this->chatUser('chat.view', 'chat.manage');

        $this->assertSame(0, $this->search($third, ['q' => 'salary'])->assertOk()->json('total'));
    }

    // --- archived ----------------------------------------------------------

    /**
     * Archive is a freeze, not a deletion: readable, not postable. A member
     * must still be able to find what was said in an archived room, and the hit
     * must link back to it. Re-adding a `notArchived()` filter here would
     * reintroduce the exact bug fixed in 236d66a0.
     */
    public function test_an_archived_conversations_messages_are_searchable_by_a_member(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Q1 Launch', $user);
        $this->chat->sendMessage($channel, $user, 'hello the retro doc is attached');

        $this->chat->archive($channel);

        $response = $this->search($user, ['q' => 'retro'])->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame($channel->id, $response->json('results.0.conversation_id'));
        $this->assertTrue($response->json('results.0.conversation_archived'));
    }

    public function test_archiving_does_not_make_a_private_room_searchable_by_outsiders(): void
    {
        $owner = $this->chatUser('chat.view', 'chat.create_channel');
        $private = $this->chat->createChannel('Founders', $owner, true);
        $this->chat->sendMessage($private, $owner, 'hello the acquisition closes friday');
        $this->chat->archive($private);

        $outsider = $this->chatUser('chat.view');

        $this->assertSame(0, $this->search($outsider, ['q' => 'acquisition'])->assertOk()->json('total'));
    }

    // --- LIKE metacharacters ----------------------------------------------

    /**
     * `%` unescaped matches everything. That would turn one keystroke into a
     * dump of every message the caller can read.
     */
    public function test_a_percent_sign_is_a_literal_not_a_wildcard(): void
    {
        [$user, $ops] = $this->fiveMessages();
        $this->chat->sendMessage($ops, $user, 'discount is 50% today');

        $response = $this->search($user, ['q' => '50%'])->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('discount is 50% today', $response->json('results.0.body'));
    }

    public function test_a_bare_percent_sign_does_not_dump_the_whole_history(): void
    {
        [$user] = $this->fiveMessages();

        // Six messages exist and none of them contains a literal '%'.
        $response = $this->search($user, ['q' => '%%'])->assertOk();

        $this->assertSame(0, $response->json('total'));
    }

    /**
     * `_` unescaped matches any single character, so `q=h_llo` would match
     * "hello" — a subtler version of the same leak.
     */
    public function test_an_underscore_is_a_literal_not_a_single_character_wildcard(): void
    {
        [$user] = $this->fiveMessages();

        $this->assertSame(0, $this->search($user, ['q' => 'h_llo'])->assertOk()->json('total'));
    }

    public function test_an_underscore_still_matches_itself(): void
    {
        [$user, $ops] = $this->fiveMessages();
        $this->chat->sendMessage($ops, $user, 'see order_items for the schema');

        $response = $this->search($user, ['q' => 'order_items'])->assertOk();

        $this->assertSame(1, $response->json('total'));
    }

    public function test_a_backslash_is_a_literal(): void
    {
        [$user, $ops] = $this->fiveMessages();
        $this->chat->sendMessage($ops, $user, 'the path is C:\\inetpub\\wwwroot');

        $this->assertSame(1, $this->search($user, ['q' => 'C:\\inetpub'])->assertOk()->json('total'));
        $this->assertSame(0, $this->search($user, ['q' => '\\%'])->assertOk()->json('total'));
    }

    // --- injection / malformed input --------------------------------------

    public function test_a_sql_injection_attempt_is_parameterised_and_matches_nothing(): void
    {
        [$user] = $this->fiveMessages();

        $response = $this->search($user, ['q' => "' OR 1=1"])->assertOk();

        $this->assertSame(0, $response->json('total'));
        $this->assertSame([], $response->json('results'));

        // The fixture is still intact — nothing was dropped or rewritten.
        $this->assertSame(2, $this->search($user, ['q' => 'hello'])->assertOk()->json('total'));
    }

    public function test_a_query_shorter_than_two_characters_is_rejected(): void
    {
        [$user] = $this->fiveMessages();

        $this->search($user, ['q' => 'a'])->assertStatus(422)->assertJsonValidationErrors('q');
        $this->search($user, ['q' => ''])->assertStatus(422)->assertJsonValidationErrors('q');
    }

    public function test_an_absurdly_long_query_is_rejected(): void
    {
        [$user] = $this->fiveMessages();

        $this->search($user, ['q' => str_repeat('a', 10000)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');
    }

    public function test_a_non_date_from_is_rejected(): void
    {
        [$user] = $this->fiveMessages();

        $this->search($user, ['q' => 'hello', 'from' => 'yesterday-ish'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('from');
    }

    public function test_a_from_later_than_to_is_rejected(): void
    {
        [$user] = $this->fiveMessages();

        $this->search($user, ['q' => 'hello', 'from' => '2026-09-10', 'to' => '2026-09-01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('to');
    }

    /**
     * An unknown channel id is an empty result set, NOT a 422 or a 404 — the
     * difference between "no such room" and "a room you may not read" is how
     * you enumerate every conversation in the install.
     */
    public function test_a_nonexistent_channel_id_returns_an_empty_result_set(): void
    {
        [$user] = $this->fiveMessages();

        $response = $this->search($user, ['q' => 'hello', 'channel' => 999999])->assertOk();

        $this->assertSame(0, $response->json('total'));
    }

    public function test_a_non_numeric_channel_is_rejected(): void
    {
        [$user] = $this->fiveMessages();

        $this->search($user, ['q' => 'hello', 'channel' => 'ops'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('channel');
    }

    public function test_a_negative_page_is_treated_as_the_first_page(): void
    {
        [$user] = $this->fiveMessages();

        $response = $this->search($user, ['q' => 'hello', 'page' => -3])->assertOk();

        $this->assertSame(1, $response->json('current_page'));
        $this->assertSame(2, $response->json('total'));
    }

    // --- date bounds -------------------------------------------------------

    public function test_from_and_to_bound_the_result_set(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $user);

        Carbon::setTestNow('2026-09-01 09:00:00');
        $this->chat->sendMessage($channel, $user, 'hello from september first');

        Carbon::setTestNow('2026-09-05 09:00:00');
        $this->chat->sendMessage($channel, $user, 'hello from september fifth');

        Carbon::setTestNow('2026-09-09 09:00:00');
        $this->chat->sendMessage($channel, $user, 'hello from september ninth');

        Carbon::setTestNow();

        $this->assertSame(3, $this->search($user, ['q' => 'hello'])->assertOk()->json('total'));

        $windowed = $this->search($user, [
            'q' => 'hello',
            'from' => '2026-09-04',
            'to' => '2026-09-06',
        ])->assertOk();

        $this->assertSame(1, $windowed->json('total'));
        $this->assertSame('hello from september fifth', $windowed->json('results.0.body'));

        // `to` is inclusive of the whole day, not midnight on it.
        $inclusive = $this->search($user, [
            'q' => 'hello',
            'from' => '2026-09-05',
            'to' => '2026-09-05',
        ])->assertOk();

        $this->assertSame(1, $inclusive->json('total'));
    }

    // --- pagination --------------------------------------------------------

    public function test_results_are_paginated_thirty_to_a_page_newest_first(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $user);

        $ids = [];
        for ($i = 1; $i <= 35; $i++) {
            $ids[] = $this->chat->sendMessage($channel, $user, "hello number {$i}")->id;
        }

        $first = $this->search($user, ['q' => 'hello'])->assertOk();

        $this->assertSame(35, $first->json('total'));
        $this->assertSame(30, $first->json('per_page'));
        $this->assertSame(2, $first->json('last_page'));
        $this->assertCount(30, $first->json('results'));

        // Newest first.
        $this->assertSame(array_slice(array_reverse($ids), 0, 30), array_column($first->json('results'), 'id'));

        $second = $this->search($user, ['q' => 'hello', 'page' => 2])->assertOk();

        $this->assertCount(5, $second->json('results'));
        $this->assertSame(2, $second->json('current_page'));
        $this->assertSame(array_slice(array_reverse($ids), 30), array_column($second->json('results'), 'id'));
    }

    // --- what must not be searchable --------------------------------------

    public function test_a_deleted_messages_body_is_not_searchable(): void
    {
        [$user, $ops] = $this->fiveMessages();

        $message = $this->chat->sendMessage($ops, $user, 'hello this will be retracted');
        $this->assertSame(3, $this->search($user, ['q' => 'hello'])->assertOk()->json('total'));

        $message->delete();

        $this->assertSame(2, $this->search($user, ['q' => 'hello'])->assertOk()->json('total'));
        $this->assertSame(0, $this->search($user, ['q' => 'retracted'])->assertOk()->json('total'));
    }

    public function test_search_requires_the_chat_view_permission(): void
    {
        $stranger = $this->chatUser();

        $this->actingAs($stranger)
            ->getJson(route('admin.chat.search', ['q' => 'hello']))
            ->assertForbidden();
    }

    /**
     * A guest is bounced to the login screen rather than answered with 401:
     * bootstrap/app.php narrows `shouldRenderJsonWhen()` to `api/*`, so every
     * admin route redirects an unauthenticated caller. Asserted as it actually
     * behaves — the point of the test is that no results come back.
     */
    public function test_a_guest_is_not_searched_into_the_panel(): void
    {
        $this->getJson(route('admin.chat.search', ['q' => 'hello']))
            ->assertRedirect('/admin');
    }

    // --- payload -----------------------------------------------------------

    public function test_a_hit_carries_what_the_ui_needs_to_jump_to_it(): void
    {
        [$user, $ops] = $this->fiveMessages();

        $hit = $this->search($user, ['q' => 'hello', 'channel' => $ops->id])->assertOk()->json('results.0');

        $this->assertSame('Ops', $hit['conversation_name']);
        $this->assertSame($user->full_name, $hit['author_name']);
        $this->assertNotEmpty($hit['created_at']);
        $this->assertStringContainsString('c='.$ops->id, $hit['url']);
        $this->assertStringContainsString('m='.$hit['id'], $hit['url']);
    }

    /**
     * A public channel the caller has never joined IS searchable — that is what
     * "public" means, and ChatConversationPolicy::view() says so. This is the
     * documented deviation from the plan's literal "conversations the user
     * participates in": the scope is everything `view()` permits, resolved
     * first, so private rooms stay participant-only.
     */
    public function test_a_public_channel_is_searchable_without_joining_it(): void
    {
        $owner = $this->chatUser('chat.view', 'chat.create_channel');
        $public = $this->chat->createChannel('Announcements', $owner);
        $this->chat->sendMessage($public, $owner, 'hello the office is closed monday');

        $other = $this->chatUser('chat.view');

        $this->assertSame(1, $this->search($other, ['q' => 'office'])->assertOk()->json('total'));
    }

    /**
     * Department scoping carries over from the policy: a departmental channel
     * is not readable — and so not searchable — by staff outside it.
     */
    public function test_a_departmental_channel_is_not_searchable_from_outside_the_department(): void
    {
        $owner = $this->chatUser('chat.view', 'chat.create_channel', 'chat.manage');
        $channel = $this->chat->createChannel('Billing', $owner, false, 'billing');
        $this->chat->sendMessage($channel, $owner, 'hello the refund policy changed');

        $outsider = $this->chatUser('chat.view');

        $this->assertSame(0, $this->search($outsider, ['q' => 'refund'])->assertOk()->json('total'));
    }
}
