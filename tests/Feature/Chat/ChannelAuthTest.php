<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\ChatConversation;
use App\Models\ChatParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesPanelUsers;
use Tests\TestCase;

/**
 * Websocket channel authorisation.
 *
 * These closures are the only thing between a private conversation and any
 * authenticated panel user, so they are exercised through the real
 * POST /broadcasting/auth endpoint rather than by calling the closure directly.
 *
 * The reverb driver is forced on for this class. It matters: with
 * BROADCAST_CONNECTION=log — the default in .env.example and in the test
 * environment — `LogBroadcaster::auth()` is an empty method, so every channel
 * authorisation request answers 200 and none of these closures ever runs. That
 * is harmless in itself (the log driver delivers nothing to any client) but it
 * means a test suite left on `log` would prove exactly nothing about channel
 * security.
 *
 * Switching driver mid-test means re-registering the channels: Broadcast::
 * channel() forwards to whichever driver is current, so the closures the
 * application registered at boot belong to the log driver instance.
 * Re-requiring routes/channels.php registers them on the reverb driver — which
 * works only because the shared rule in that file is a closure rather than a
 * named function.
 */
class ChannelAuthTest extends TestCase
{
    use CreatesPanelUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.app_id' => 'test-app',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
        ]);

        require base_path('routes/channels.php');
    }

    public function test_broadcasting_auth_route_is_registered(): void
    {
        $this->assertTrue(
            app('router')->getRoutes()->hasNamedRoute('broadcasting.auth')
            || collect(app('router')->getRoutes())->contains(
                fn ($route) => $route->uri() === 'broadcasting/auth'
            ),
            'POST /broadcasting/auth is not registered — Echo cannot join any private channel.',
        );
    }

    public function test_reverb_is_the_selected_broadcaster_and_is_wired_to_the_reverb_env_vars(): void
    {
        $this->assertSame('reverb', config('broadcasting.default'));
        $this->assertSame('reverb', config('broadcasting.connections.reverb.driver'));
        $this->assertSame('test-key', config('broadcasting.connections.reverb.key'));
        $this->assertSame('test-app', config('broadcasting.connections.reverb.app_id'));

        // The publish timeout is bounded. Chat events broadcast synchronously
        // inside the web request, so an unreachable Reverb must fail fast
        // rather than hold the request open.
        $this->assertSame(5, config('broadcasting.connections.reverb.client_options.timeout'));
        $this->assertSame(2, config('broadcasting.connections.reverb.client_options.connect_timeout'));

        // Whatever the framework's own config stub contributes by merge, pusher
        // must never be what this application selects.
        $this->assertNotSame('pusher', config('broadcasting.default'));
    }

    public function test_participant_may_join_a_private_conversation_channel(): void
    {
        $user = $this->panelUserWithPermissions('chat.view');
        $conversation = ChatConversation::factory()->private()->create();

        ChatParticipant::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
        ]);

        $this->authAs($user, 'private-chat.conversation.'.$conversation->id)
            ->assertOk();
    }

    public function test_non_participant_may_not_join_a_private_conversation_channel(): void
    {
        $outsider = $this->panelUserWithPermissions('chat.view');
        $conversation = ChatConversation::factory()->private()->create();

        ChatParticipant::factory()->create(['conversation_id' => $conversation->id]);

        $this->authAs($outsider, 'private-chat.conversation.'.$conversation->id)
            ->assertForbidden();
    }

    public function test_any_chat_viewer_may_join_a_public_channel_without_joining_it_first(): void
    {
        $user = $this->panelUserWithPermissions('chat.view');
        $conversation = ChatConversation::factory()->create(['is_private' => false]);

        $this->authAs($user, 'private-chat.conversation.'.$conversation->id)
            ->assertOk();
    }

    public function test_a_user_without_chat_view_may_not_join_a_public_channel(): void
    {
        $user = $this->panelUserWithoutPermission('chat.view');
        $conversation = ChatConversation::factory()->create(['is_private' => false]);

        $this->authAs($user, 'private-chat.conversation.'.$conversation->id)
            ->assertForbidden();
    }

    public function test_customer_inbox_is_not_readable_by_ordinary_staff(): void
    {
        $staff = $this->panelUserWithPermissions('chat.view');
        $inbox = ChatConversation::factory()->customerInbox()->create();

        $this->authAs($staff, 'private-chat.conversation.'.$inbox->id)
            ->assertForbidden();
    }

    public function test_chat_manage_opens_the_customer_inbox_queue(): void
    {
        $operator = $this->panelUserWithPermissions('chat.view', 'chat.manage');
        $inbox = ChatConversation::factory()->customerInbox()->create();

        $this->authAs($operator, 'private-chat.conversation.'.$inbox->id)
            ->assertOk();
    }

    public function test_presence_channel_requires_chat_view(): void
    {
        $allowed = $this->panelUserWithPermissions('chat.view');
        $this->authAs($allowed, 'presence-chat.presence')->assertOk();

        $this->app['auth']->forgetGuards();

        $denied = $this->panelUserWithoutPermission('chat.view');
        $this->authAs($denied, 'presence-chat.presence')->assertForbidden();
    }

    public function test_typing_channel_follows_the_conversation_gate(): void
    {
        $conversation = ChatConversation::factory()->private()->create();
        $member = $this->panelUserWithPermissions('chat.view');

        ChatParticipant::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $member->id,
        ]);

        $this->authAs($member, 'presence-chat.typing.'.$conversation->id)->assertOk();

        $this->app['auth']->forgetGuards();

        $outsider = $this->panelUserWithPermissions('chat.view');
        $this->authAs($outsider, 'presence-chat.typing.'.$conversation->id)->assertForbidden();
    }

    public function test_unauthenticated_request_cannot_authorise_a_channel(): void
    {
        $conversation = ChatConversation::factory()->create();

        // The endpoint carries `auth`, so a guest never reaches the broadcaster.
        // The rejection is a redirect rather than a 401 even for an XHR, because
        // bootstrap/app.php narrows shouldRenderJsonWhen() to api/* paths — an
        // app-wide convention, not something specific to this route. Either way
        // Echo cannot obtain an auth signature.
        foreach (['post', 'postJson'] as $method) {
            $this->{$method}('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-chat.conversation.'.$conversation->id,
            ])->assertRedirect(route('client.login'));
        }
    }

    public function test_a_missing_conversation_is_denied_rather_than_erroring(): void
    {
        $user = $this->panelUserWithPermissions('chat.view');

        $this->authAs($user, 'private-chat.conversation.999999')->assertForbidden();
    }

    private function authAs(User $user, string $channel): TestResponse
    {
        return $this->actingAs($user)->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => $channel,
        ]);
    }
}
