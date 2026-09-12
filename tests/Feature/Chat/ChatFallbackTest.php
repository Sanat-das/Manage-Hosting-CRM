<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Services\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesChatUsers;
use Tests\TestCase;

/**
 * Todo 19 — reconnection fallback, error states, empty/loading skeletons.
 *
 * Server-side: after_id polling is permission-gated and well-behaved.
 * Rendered-HTML: banner, skeletons and empty states are present in the
 * response the browser receives and keyed for the JS to find.
 */
class ChatFallbackTest extends TestCase
{
    use CreatesChatUsers;
    use RefreshDatabase;

    private ChatService $chat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chat = app(ChatService::class);
    }

    // --- polling endpoint ------------------------------------------------

    public function test_after_id_returns_only_newer_messages_in_order(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $user);

        $first = $this->chat->sendMessage($channel, $user, 'one');
        $second = $this->chat->sendMessage($channel, $user, 'two');
        $third = $this->chat->sendMessage($channel, $user, 'three');

        $this->actingAs($user)
            ->getJson(route('admin.chat.messages.index', [$channel, 'after_id' => $first->id]))
            ->assertOk()
            ->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.0.id', $second->id)
            ->assertJsonPath('messages.1.id', $third->id);
    }

    public function test_after_id_returns_empty_array_when_there_is_nothing_new(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $user);
        $last = $this->chat->sendMessage($channel, $user, 'only');

        $this->actingAs($user)
            ->getJson(route('admin.chat.messages.index', [$channel, 'after_id' => $last->id]))
            ->assertOk()
            ->assertJsonCount(0, 'messages');
    }

    public function test_polling_does_not_leak_a_private_channel_to_a_non_participant(): void
    {
        $owner = $this->chatUser('chat.view', 'chat.create_channel');
        $private = $this->chat->createChannel('Secret', $owner, true);
        $this->chat->sendMessage($private, $owner, 'hidden');

        // after_id must not be a back door.
        $this->actingAs($this->chatUser('chat.view'))
            ->getJson(route('admin.chat.messages.index', [$private, 'after_id' => 0]))
            ->assertForbidden();

        $this->actingAs($this->chatUser('chat.view'))
            ->getJson(route('admin.chat.messages.index', $private))
            ->assertForbidden();
    }

    public function test_an_invalid_after_id_is_handled_gracefully(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $user);
        $this->chat->sendMessage($channel, $user, 'hello');

        // abc -> integer 0 via $request->integer(), so returns all messages.
        $this->actingAs($user)
            ->getJson(route('admin.chat.messages.index', [$channel, 'after_id' => 'abc']))
            ->assertOk()
            ->assertJsonCount(1, 'messages');

        // -1 -> no id matches <0, so all messages returned (treated as after -1).
        $this->actingAs($user)
            ->getJson(route('admin.chat.messages.index', [$channel, 'after_id' => -1]))
            ->assertOk();
        // 999999999 -> nothing newer.
        $this->actingAs($user)
            ->getJson(route('admin.chat.messages.index', [$channel, 'after_id' => 999999999]))
            ->assertOk()
            ->assertJsonCount(0, 'messages');
    }

    public function test_polling_a_foreign_conversation_id_is_forbidden(): void
    {
        $owner = $this->chatUser('chat.view', 'chat.create_channel');
        $private = $this->chat->createChannel('Secret', $owner, true);

        $other = $this->chatUser('chat.view', 'chat.create_channel');
        $mine = $this->chat->createChannel('Mine', $other);

        // Asking for a private conversation that exists but is not yours is 403.
        $this->actingAs($other)
            ->getJson(route('admin.chat.messages.index', [$private, 'after_id' => 0]))
            ->assertForbidden();

        // A conversation the user can see still works.
        $this->actingAs($other)
            ->getJson(route('admin.chat.messages.index', [$mine, 'after_id' => 0]))
            ->assertOk();
    }

    // --- rendered HTML markers ------------------------------------------

    public function test_the_disconnected_banner_is_present_in_the_rendered_html_but_hidden(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $this->chat->createChannel('Ops', $user);

        $html = $this->actingAs($user)->get(route('admin.chat.index'))->assertOk()->getContent();

        // Exact banner text with em dash — JS keys off it and the test proves
        // the UTF-8 survived the build.
        $this->assertStringContainsString('Realtime disconnected — polling', $html);
        $this->assertStringContainsString('chat-reconnect-banner', $html);
        $this->assertStringContainsString('data-reconnect-banner', $html);
        // Hidden by default; JS shows it only when Echo is unavailable.
        $this->assertStringContainsString('d-none', $html);
        $this->assertStringNotContainsString('@endcan@stop', $html);
        $this->assertStringNotContainsString('@stop', $html);
    }

    public function test_loading_skeletons_are_present_in_the_rendered_html(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $this->chat->createChannel('Ops', $user);

        $html = $this->actingAs($user)->get(route('admin.chat.index'))->assertOk()->getContent();

        $this->assertStringContainsString('chat-skeleton', $html);
        $this->assertStringContainsString('data-chat-skeleton', $html);
    }

    public function test_empty_state_for_no_conversations_is_rendered(): void
    {
        $html = $this->actingAs($this->chatUser('chat.view'))
            ->get(route('admin.chat.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('No conversations yet', $html);
        $this->assertStringContainsString('chat-empty', $html);
    }

    public function test_empty_state_for_no_messages_in_a_conversation(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $user);

        $html = $this->actingAs($user)
            ->get(route('admin.chat.index', ['c' => $channel->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('chat-no-messages', $html);
        $this->assertStringContainsString('No messages yet', $html);
    }

    public function test_error_toast_container_is_present_in_the_rendered_html(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $this->chat->createChannel('Ops', $user);

        $html = $this->actingAs($user)->get(route('admin.chat.index'))->assertOk()->getContent();

        $this->assertStringContainsString('chat-toast', $html);
        $this->assertStringContainsString('data-chat-toast', $html);
        $this->assertStringContainsString('data-chat-retry', $html);
    }

    public function test_confirmation_dialog_markers_are_present(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $user);

        $html = $this->actingAs($user)->get(route('admin.chat.index', ['c' => $channel->id]))->assertOk()->getContent();

        $this->assertStringContainsString('chat-confirm', $html);
        $this->assertStringContainsString('data-chat-confirm', $html);
    }

    public function test_rendered_html_contains_no_stray_blade_directives(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $this->chat->createChannel('Ops', $user);
        $this->chat->sendMessage($this->chat->createChannel('Second', $user), $user, 'hello');

        $html = $this->actingAs($user)->get(route('admin.chat.index'))->assertOk()->getContent();

        // A Blade file can compile clean and still print literal directive text.
        $this->assertDoesNotMatchRegularExpression('/@(?:endcan|stop|endif|endforeach|can|endcan)\b/', $html);
    }

    public function test_chat_js_and_css_bundles_are_still_injected(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $this->chat->createChannel('Ops', $user);

        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
        if (! isset($manifest['resources/js/chat.js']['file'], $manifest['resources/css/chat.css']['file'])) {
            $this->markTestSkipped('Run npm run build.');
        }

        $html = $this->actingAs($user)->get(route('admin.chat.index'))->assertOk()->getContent();
        $this->assertStringContainsString($manifest['resources/js/chat.js']['file'], $html);
        $this->assertStringContainsString($manifest['resources/css/chat.css']['file'], $html);
    }
}
