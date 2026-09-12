<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\ChatConversation;
use App\Services\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesChatUsers;
use Tests\TestCase;

/**
 * The Slack-like admin UI.
 *
 * Every assertion is against the HTML the browser receives. Compiled Blade can
 * look entirely correct and still render nothing — a section name the layout
 * does not yield is silently dropped, which is exactly what happened to the
 * first version of this page's asset includes.
 */
class ChatUiTest extends TestCase
{
    use CreatesChatUsers;
    use RefreshDatabase;

    private ChatService $chat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chat = app(ChatService::class);
    }

    public function test_the_page_renders_sidebar_message_pane_and_composer(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('General', $user);
        $this->chat->sendMessage($channel, $user, 'Hello everyone');

        $this->actingAs($user)
            ->get(route('admin.chat.index'))
            ->assertOk()
            ->assertSee('chat-sidebar', false)
            ->assertSee('chat-message-list', false)
            ->assertSee('chat-composer', false)
            ->assertSee('chat-thread', false)
            ->assertSee('data-conversation-id', false)
            ->assertSee('General')
            ->assertSee('Hello everyone');
    }

    public function test_the_page_loads_its_own_css_and_js_bundles(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $this->chat->createChannel('General', $user);

        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);

        if (! isset($manifest['resources/js/chat.js']['file'], $manifest['resources/css/chat.css']['file'])) {
            $this->markTestSkipped('The committed Vite build predates chat.js - run `npm run build`.');
        }

        $response = $this->actingAs($user)->get(route('admin.chat.index'))->assertOk();

        // The whole point: a @section the layout never yields compiles fine and
        // renders nothing.
        $response->assertSee($manifest['resources/js/chat.js']['file'], false);
        $response->assertSee($manifest['resources/css/chat.css']['file'], false);
    }

    public function test_messages_are_rendered_server_side_so_the_page_works_without_a_socket(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('General', $user);
        $this->chat->sendMessage($channel, $user, 'Readable without javascript');

        $this->actingAs($user)
            ->get(route('admin.chat.index'))
            ->assertOk()
            ->assertSee('Readable without javascript')
            ->assertSee('data-message-id', false);
    }

    public function test_a_private_channel_the_user_is_not_in_never_reaches_the_sidebar(): void
    {
        $owner = $this->chatUser('chat.view', 'chat.create_channel');
        $this->chat->createChannel('Secret Plans', $owner, true);

        $outsider = $this->chatUser('chat.view', 'chat.create_channel');
        $this->chat->createChannel('Public Room', $outsider);

        $this->actingAs($outsider)
            ->get(route('admin.chat.index'))
            ->assertOk()
            ->assertSee('Public Room')
            ->assertDontSee('Secret Plans');
    }

    public function test_a_specific_conversation_can_be_opened_by_query_string(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $first = $this->chat->createChannel('Alpha', $user);
        $second = $this->chat->createChannel('Beta', $user);
        $this->chat->sendMessage($second, $user, 'Only in beta');

        $this->actingAs($user)
            ->get(route('admin.chat.index', ['c' => $second->id]))
            ->assertOk()
            ->assertSee('Only in beta')
            ->assertSee('data-conversation-id="'.$second->id.'"', false);
    }

    /**
     * Still a refusal, never a silent fallback to some other room — but a 404
     * rather than the 403 this asserted before, so that it is indistinguishable
     * from asking for an id that was never issued. See
     * test_an_unknown_conversation_id_is_indistinguishable_from_a_forbidden_one.
     */
    public function test_asking_for_a_conversation_you_cannot_see_is_refused_not_a_silent_fallback(): void
    {
        $owner = $this->chatUser('chat.view', 'chat.create_channel');
        $private = $this->chat->createChannel('Secret', $owner, true);

        $outsider = $this->chatUser('chat.view', 'chat.create_channel');
        $this->chat->createChannel('Mine', $outsider);

        $response = $this->actingAs($outsider)
            ->get(route('admin.chat.index', ['c' => $private->id]))
            ->assertNotFound();

        // The refusal must not itself name what it refused.
        $response->assertDontSee('Secret');
    }

    public function test_opening_a_conversation_marks_it_read(): void
    {
        $alice = $this->chatUser('chat.view', 'chat.create_channel');
        $bob = $this->chatUser('chat.view');

        $channel = $this->chat->createChannel('Ops', $alice);
        $this->chat->addMember($channel, $bob);
        $this->chat->sendMessage($channel, $alice, 'Unread until opened');

        $this->assertSame(1, $this->chat->unreadCount($channel, $bob));

        $this->actingAs($bob)->get(route('admin.chat.index', ['c' => $channel->id]))->assertOk();

        $this->assertSame(0, $this->chat->unreadCount($channel, $bob->fresh()));
    }

    public function test_unread_badges_appear_for_other_conversations(): void
    {
        $alice = $this->chatUser('chat.view', 'chat.create_channel');
        $bob = $this->chatUser('chat.view');

        $open = $this->chat->createChannel('Open', $alice);
        $busy = $this->chat->createChannel('Busy', $alice);
        $this->chat->addMember($open, $bob);
        $this->chat->addMember($busy, $bob);
        $this->chat->sendMessage($busy, $alice, 'Ping');

        $this->actingAs($bob)
            ->get(route('admin.chat.index', ['c' => $open->id]))
            ->assertOk()
            ->assertSee('data-unread-for="'.$busy->id.'"', false);
    }

    public function test_the_customer_inbox_section_is_only_rendered_for_operators(): void
    {
        $this->chat->startCustomerChat(['email' => 'jo@example.com'], null, 'I need help');

        $staff = $this->chatUser('chat.view', 'chat.create_channel');
        $this->chat->createChannel('Staff Room', $staff);

        $this->actingAs($staff)
            ->get(route('admin.chat.index'))
            ->assertOk()
            ->assertDontSee('Customer inbox');

        $operator = $this->chatUser('chat.view', 'chat.manage');
        $inbox = ChatConversation::where('type', ChatConversation::TYPE_CUSTOMER_INBOX)->firstOrFail();

        $this->actingAs($operator)
            ->get(route('admin.chat.index'))
            ->assertOk()
            ->assertSee('Customer inbox');

        // The take/convert/close controls belong to the open conversation, so
        // they appear once the inbox conversation is the selected one.
        $this->actingAs($operator)
            ->get(route('admin.chat.index', ['c' => $inbox->id]))
            ->assertOk()
            ->assertSee('data-inbox-action', false);
    }

    public function test_the_create_channel_button_follows_the_permission(): void
    {
        $this->actingAs($this->chatUser('chat.view', 'chat.create_channel'))
            ->get(route('admin.chat.index'))
            ->assertOk()
            ->assertSee('chat-new-channel', false);

        $this->actingAs($this->chatUser('chat.view'))
            ->get(route('admin.chat.index'))
            ->assertOk()
            ->assertDontSee('chat-new-channel', false);
    }

    public function test_the_entity_picker_is_hidden_from_users_who_can_search_nothing(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $this->chat->createChannel('Ops', $user);

        $this->actingAs($user)
            ->get(route('admin.chat.index'))
            ->assertOk()
            ->assertDontSee('chat-attach-entity', false);

        $withAccess = $this->chatUser('chat.view', 'chat.create_channel', 'tickets.view');
        $this->chat->createChannel('Ops two', $withAccess);

        $this->actingAs($withAccess)
            ->get(route('admin.chat.index'))
            ->assertOk()
            ->assertSee('chat-attach-entity', false);
    }

    public function test_an_empty_state_is_shown_when_there_is_nothing_to_read(): void
    {
        $this->actingAs($this->chatUser('chat.view'))
            ->get(route('admin.chat.index'))
            ->assertOk()
            ->assertSee('No conversations yet');
    }

    public function test_a_message_body_is_rendered_escaped(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $user);
        $this->chat->sendMessage($channel, $user, '<script>alert(1)</script> **bold**');

        $response = $this->actingAs($user)->get(route('admin.chat.index'))->assertOk();

        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('&lt;script&gt;', false);
        $response->assertSee('<strong>bold</strong>', false);
    }

    public function test_the_legacy_session_screen_is_still_reachable(): void
    {
        // The new UI replaces the old list, but existing chat_sessions rows keep
        // their transcript view rather than becoming unreachable.
        //
        // Inserted with the query builder, not the model: chat_sessions has no
        // created_at/updated_at columns while App\Models\ChatSession leaves
        // timestamps on, so ChatSession::create() throws. Pre-existing, and
        // unnoticed because the demo seeder inserts that table directly too.
        $id = DB::table('chat_sessions')->insertGetId([
            'name' => 'Legacy Visitor',
            'email' => 'legacy@example.com',
            'department' => 'support',
            'status' => 'closed',
            'started_at' => now(),
        ]);

        $this->actingAs($this->chatUser('chat.view'))
            ->get(route('admin.chat.show', $id))
            ->assertOk()
            ->assertSee('Legacy Visitor');
    }

    public function test_the_sidebar_does_not_break_the_adminlte_menu(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $this->chat->createChannel('Ops', $user);

        $this->actingAs($user)
            ->get(route('admin.chat.index'))
            ->assertOk()
            // The panel's own navigation still renders around the chat shell.
            ->assertSee('sidebar-expand-', false)
            ->assertSee('app-sidebar', false)
            ->assertSee('Live Chat');
    }

    public function test_archived_channels_are_not_listed(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $live = $this->chat->createChannel('Live Room', $user);
        $old = $this->chat->createChannel('Old Room', $user);
        $this->chat->archive($old);

        $this->actingAs($user)
            ->get(route('admin.chat.index', ['c' => $live->id]))
            ->assertOk()
            ->assertSee('Live Room')
            ->assertDontSee('Old Room');
    }

    /**
     * Archive is a freeze, not a deletion, so all three surfaces must agree:
     * readable everywhere, postable nowhere.
     *
     * This replaces the behaviour recorded before the fix, which was incoherent
     * — the HTML page answered 403 while the JSON history of the SAME room to
     * the SAME user answered 200. The page's 403 came from `resolveSelected()`
     * treating the sidebar's `notArchived()` listing query as an authorisation
     * source: not in the list was read as not permitted.
     */
    public function test_archived_access_is_coherent_across_the_page_and_the_api(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Old Room', $user);
        $this->chat->sendMessage($channel, $user, 'History survives archiving');
        $this->chat->archive($channel);

        // Surface 1 — the HTML page. Was 403 before the fix.
        $this->actingAs($user)
            ->get(route('admin.chat.index', ['c' => $channel->id]))
            ->assertOk();

        // Surface 2 — the JSON history. Was already 200, and stays 200.
        $this->actingAs($user)
            ->getJson(route('admin.chat.messages.index', $channel))
            ->assertOk();

        // Surface 3 — posting stays refused, by the policy, unchanged. 403, not
        // the 422 the audit reported: that is the *widget* route's shape (the
        // service's RuntimeException); here sendMessage() denies first.
        $this->actingAs($user)
            ->postJson(route('admin.chat.messages.store', $channel), ['body' => 'Can I still speak?'])
            ->assertForbidden();
    }

    public function test_an_archived_conversation_shows_its_history_with_a_frozen_composer(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Old Room', $user);
        $this->chat->sendMessage($channel, $user, 'History survives archiving');
        $this->chat->archive($channel);

        $response = $this->actingAs($user)
            ->get(route('admin.chat.index', ['c' => $channel->id]))
            ->assertOk();

        // Readable: the room's name and its history are both on the page.
        $response->assertSee('Old Room');
        $response->assertSee('History survives archiving');

        // Frozen: the notice is shown and the composer cannot be typed into or
        // submitted. Asserted against rendered HTML, never Blade source.
        $response->assertSee('This conversation is archived', false);
        $response->assertSee('data-chat-archived', false);
        $response->assertSee('chat-body" name="body" rows="2" disabled', false);

        // A Blade directive that reaches the browser as text compiles perfectly
        // well and renders as literal noise; this repo has shipped that bug.
        $html = $response->getContent();
        foreach (['@endif', '@endcan', '@stop', '@if (', '@else'] as $directive) {
            $this->assertStringNotContainsString($directive, $html, 'A Blade directive leaked into the rendered page.');
        }
    }

    public function test_a_live_conversation_composer_is_not_disabled(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Live Room', $user);

        $this->actingAs($user)
            ->get(route('admin.chat.index', ['c' => $channel->id]))
            ->assertOk()
            ->assertDontSee('data-chat-archived', false)
            ->assertDontSee('This conversation is archived', false)
            ->assertSee('chat-body" name="body" rows="2"', false);
    }

    /**
     * A conversation id that does not exist and one that exists but is not
     * yours must produce the SAME answer, or the difference between them is a
     * free enumeration oracle over every conversation in the install.
     *
     * The chosen answer is 404 for both. It keeps the original intent of the
     * 403 — refuse, never silently fall back to some other room — while saying
     * nothing about whether the row exists.
     */
    public function test_an_unknown_conversation_id_is_indistinguishable_from_a_forbidden_one(): void
    {
        $owner = $this->chatUser('chat.view', 'chat.create_channel');
        $private = $this->chat->createChannel('Secret', $owner, true);

        $outsider = $this->chatUser('chat.view', 'chat.create_channel');
        $this->chat->createChannel('Mine', $outsider);

        $forbidden = $this->actingAs($outsider)->get(route('admin.chat.index', ['c' => $private->id]));
        $nonexistent = $this->actingAs($outsider)->get(route('admin.chat.index', ['c' => 999999]));

        $forbidden->assertNotFound();
        $nonexistent->assertNotFound();
        $this->assertSame(
            $forbidden->getStatusCode(),
            $nonexistent->getStatusCode(),
            'A real-but-forbidden id and a nonexistent id must be indistinguishable.'
        );
    }

    public function test_malformed_conversation_ids_are_answered_the_same_way(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $this->chat->createChannel('Mine', $user);

        foreach (['abc', '-1', '0', '999999', '1e9', '../1'] as $bad) {
            $this->actingAs($user)
                ->get(route('admin.chat.index', ['c' => $bad]))
                ->assertNotFound();
        }

        // An empty `c` is a form submitting nothing, not a lookup: it falls back
        // to the default room rather than 404ing.
        $this->actingAs($user)
            ->get(route('admin.chat.index', ['c' => '']))
            ->assertOk()
            ->assertSee('Mine');
    }

    public function test_a_customer_inbox_is_not_an_oracle_for_staff_who_cannot_operate(): void
    {
        $this->chat->startCustomerChat(['email' => 'jo@example.com'], null, 'I need help');
        $inbox = ChatConversation::where('type', ChatConversation::TYPE_CUSTOMER_INBOX)->firstOrFail();

        // chat.view but no chat.manage, and not a participant: the policy says
        // no, so the answer must match the nonexistent-id answer exactly.
        $staff = $this->chatUser('chat.view');

        $this->actingAs($staff)->get(route('admin.chat.index', ['c' => $inbox->id]))->assertNotFound();
        $this->actingAs($staff)->get(route('admin.chat.index', ['c' => 999999]))->assertNotFound();
    }

    public function test_a_customer_inbox_conversation_shows_its_status(): void
    {
        $this->chat->startCustomerChat(['email' => 'jo@example.com'], null, 'Help');

        $this->actingAs($this->chatUser('chat.view', 'chat.manage'))
            ->get(route('admin.chat.index'))
            ->assertOk()
            ->assertSee(ChatConversation::STATUS_WAITING);
    }
}
