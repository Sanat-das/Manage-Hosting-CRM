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

    public function test_asking_for_a_conversation_you_cannot_see_is_forbidden_not_a_silent_fallback(): void
    {
        $owner = $this->chatUser('chat.view', 'chat.create_channel');
        $private = $this->chat->createChannel('Secret', $owner, true);

        $outsider = $this->chatUser('chat.view', 'chat.create_channel');
        $this->chat->createChannel('Mine', $outsider);

        $this->actingAs($outsider)
            ->get(route('admin.chat.index', ['c' => $private->id]))
            ->assertForbidden();
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

    public function test_a_customer_inbox_conversation_shows_its_status(): void
    {
        $this->chat->startCustomerChat(['email' => 'jo@example.com'], null, 'Help');

        $this->actingAs($this->chatUser('chat.view', 'chat.manage'))
            ->get(route('admin.chat.index'))
            ->assertOk()
            ->assertSee(ChatConversation::STATUS_WAITING);
    }
}
