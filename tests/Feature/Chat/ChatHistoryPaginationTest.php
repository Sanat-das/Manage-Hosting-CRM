<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\ChatConversation;
use App\Models\ChatConversationMessage;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesChatUsers;
use Tests\TestCase;

/**
 * CHARACTERISATION of the cursor pagination that Todo 13 already shipped.
 *
 * Every assertion in this file passes on UNCHANGED code — that is the point.
 * Todo 15 asks for "history pagination on conversation fetch (cursor by id)",
 * and the endpoint at ChatController::fetchMessages already implements it:
 * `before_id` pages backwards for "Load older", `after_id` is what the polling
 * fallback uses when the websocket is down, the page is capped at
 * ChatController::MESSAGE_PAGE (50) and ordered by id, and the whole thing is
 * authorised by `Gate::authorize('view', $conversation)` on the first line.
 *
 * Rather than rewrite a working endpoint, this file pins its behaviour so that
 * the rest of Todo 15 provably changed none of it, and so that a future edit to
 * the cursor cannot silently turn "load older" into a full-history dump.
 */
class ChatHistoryPaginationTest extends TestCase
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
     * Seed a channel with $count messages, oldest first, and return the ids in
     * ascending order.
     *
     * @return array{0: ChatConversation, 1: User, 2: list<int>}
     */
    private function channelWithMessages(int $count): array
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('History', $user);

        $ids = [];

        for ($i = 1; $i <= $count; $i++) {
            $ids[] = $this->chat->sendMessage($channel, $user, "message {$i}")->id;
        }

        return [$channel, $user, $ids];
    }

    public function test_a_first_fetch_returns_the_newest_page_capped_at_fifty(): void
    {
        [$channel, $user, $ids] = $this->channelWithMessages(55);

        $response = $this->actingAs($user)
            ->getJson(route('admin.chat.messages.index', $channel))
            ->assertOk();

        $returned = array_column($response->json('messages'), 'id');

        // Newest 50, handed back oldest-first so the client can append in order.
        $this->assertCount(50, $returned);
        $this->assertSame(array_slice($ids, 5), $returned);
        $this->assertTrue($response->json('has_more'));
    }

    public function test_before_id_pages_backwards_from_the_cursor(): void
    {
        [$channel, $user, $ids] = $this->channelWithMessages(55);

        // The oldest id on the first page is the cursor the UI sends back.
        $cursor = $ids[5];

        $response = $this->actingAs($user)
            ->getJson(route('admin.chat.messages.index', $channel).'?before_id='.$cursor)
            ->assertOk();

        $returned = array_column($response->json('messages'), 'id');

        $this->assertSame(array_slice($ids, 0, 5), $returned);
        $this->assertFalse($response->json('has_more'));
    }

    public function test_after_id_returns_only_what_arrived_since_the_cursor(): void
    {
        [$channel, $user, $ids] = $this->channelWithMessages(10);

        $response = $this->actingAs($user)
            ->getJson(route('admin.chat.messages.index', $channel).'?after_id='.$ids[7])
            ->assertOk();

        $this->assertSame(array_slice($ids, 8), array_column($response->json('messages'), 'id'));
    }

    public function test_paging_backwards_twice_never_repeats_a_message(): void
    {
        [$channel, $user, $ids] = $this->channelWithMessages(120);

        $this->actingAs($user);

        $seen = [];
        $cursor = null;

        for ($page = 0; $page < 3; $page++) {
            $url = route('admin.chat.messages.index', $channel).($cursor === null ? '' : '?before_id='.$cursor);
            $returned = array_column($this->getJson($url)->assertOk()->json('messages'), 'id');

            $this->assertNotEmpty($returned, "page {$page} came back empty");
            $seen = array_merge($returned, $seen);
            $cursor = $returned[0];
        }

        $this->assertCount(120, $seen, 'three pages (50+50+20) should have covered the whole history');
        $this->assertSame($seen, array_values(array_unique($seen)), 'a message was served on two different pages');
        $this->assertSame($ids, $seen, 'paging backwards did not reconstruct the history in order');
    }

    public function test_thread_replies_are_excluded_from_the_main_history(): void
    {
        [$channel, $user, $ids] = $this->channelWithMessages(3);

        $this->chat->sendMessage($channel, $user, 'a reply', $ids[0]);

        $returned = array_column(
            $this->actingAs($user)
                ->getJson(route('admin.chat.messages.index', $channel))
                ->assertOk()
                ->json('messages'),
            'id'
        );

        $this->assertSame($ids, $returned, 'a thread reply leaked into the root history');
    }

    /**
     * The cursor is not an authorisation bypass: the policy runs first.
     */
    public function test_a_non_participant_cannot_page_a_private_channel(): void
    {
        $owner = $this->chatUser('chat.view', 'chat.create_channel');
        $private = $this->chat->createChannel('Locked', $owner, true);
        $this->chat->sendMessage($private, $owner, 'secret');

        $outsider = $this->chatUser('chat.view');

        $this->actingAs($outsider)
            ->getJson(route('admin.chat.messages.index', $private).'?before_id=999999')
            ->assertForbidden();
    }

    /**
     * A soft-deleted message keeps its slot in the page (withTrashed) but never
     * its text — so paging cannot be used to read deleted history.
     */
    public function test_a_deleted_message_keeps_its_slot_but_not_its_body(): void
    {
        [$channel, $user, $ids] = $this->channelWithMessages(3);

        ChatConversationMessage::findOrFail($ids[1])->delete();

        $messages = $this->actingAs($user)
            ->getJson(route('admin.chat.messages.index', $channel))
            ->assertOk()
            ->json('messages');

        $this->assertSame($ids, array_column($messages, 'id'));
        $this->assertSame(ChatConversationMessage::DELETED_PLACEHOLDER, $messages[1]['body']);
    }
}
