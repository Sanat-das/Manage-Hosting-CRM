<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\ChatConversation;
use App\Models\ChatConversationMessage;
use App\Services\ChatService;
use App\Services\TicketService;
use App\Models\TicketDepartment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * What the customer widget is handed, and what it is allowed to ask for.
 *
 * Two fixes live here. The transcript used to be an unconditional
 * `orderBy('id')->limit(100)`, so a returning customer received the hundred
 * OLDEST messages — the start of a conversation they had already read, with
 * the operator's most recent reply not on screen at all. And the whole guest
 * route group shared one `throttle:30,1`, a budget the widget's own polling and
 * typing heartbeats could exhaust without a second visitor being involved.
 */
class ClientChatTranscriptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TicketDepartment::firstOrCreate(['slug' => 'support'], ['name' => 'Support', 'enabled' => true]);
        TicketService::forgetDepartmentCache();
    }

    // --- transcript ordering -------------------------------------------------

    public function test_a_returning_customer_is_given_the_newest_messages_not_the_oldest(): void
    {
        [$conversation, $token] = $this->conversationWithMessages(130);

        $body = $this->withHeader('X-Chat-Token', $token)
            ->getJson(route('chat.messages', $conversation))
            ->assertOk()
            ->json();

        $bodies = array_column($body['messages'], 'body');

        $this->assertCount(100, $bodies);

        // Newest page, still rendered oldest-first within itself.
        $this->assertSame('message 31', $bodies[0]);
        $this->assertSame('message 130', $bodies[99]);

        $this->assertTrue($body['has_more'], 'There are 30 older messages the widget must be able to reach.');
    }

    public function test_before_id_pages_backwards_into_the_history(): void
    {
        [$conversation, $token] = $this->conversationWithMessages(130);

        $oldest = ChatConversationMessage::where('conversation_id', $conversation->id)
            ->orderBy('id')
            ->skip(30)
            ->first();

        $body = $this->withHeader('X-Chat-Token', $token)
            ->getJson(route('chat.messages', $conversation).'?before_id='.$oldest->id)
            ->assertOk()
            ->json();

        $bodies = array_column($body['messages'], 'body');

        $this->assertSame('message 1', $bodies[0]);
        $this->assertSame('message 30', $bodies[count($bodies) - 1]);
        $this->assertFalse($body['has_more'], 'Nothing precedes the first message.');
    }

    /**
     * The polling path is unchanged: ascending from the cursor, because those
     * messages are appended in the order they were said.
     */
    public function test_after_id_still_returns_only_what_is_newer_oldest_first(): void
    {
        [$conversation, $token] = $this->conversationWithMessages(5);

        $third = ChatConversationMessage::where('conversation_id', $conversation->id)
            ->orderBy('id')
            ->skip(2)
            ->first();

        $body = $this->withHeader('X-Chat-Token', $token)
            ->getJson(route('chat.messages', $conversation).'?after_id='.$third->id)
            ->assertOk()
            ->json();

        $this->assertSame(['message 4', 'message 5'], array_column($body['messages'], 'body'));
    }

    /**
     * A poll must not be able to retract the "load earlier" control.
     *
     * The polling query only ever looks FORWARD from its cursor, so it has no
     * idea whether older history exists. Answering `false` would be a lie the
     * widget cannot see through: the first load correctly reports more history,
     * then a poll a few seconds later hides the control and strands the
     * customer at the newest page. Absent means unknown.
     *
     * Found in the browser, not here — the control was rendered and then
     * quietly disappeared on the next tick.
     */
    public function test_the_polling_response_omits_has_more_rather_than_guessing(): void
    {
        [$conversation, $token] = $this->conversationWithMessages(130);

        $first = $this->withHeader('X-Chat-Token', $token)
            ->getJson(route('chat.messages', $conversation))
            ->assertOk()
            ->json();

        $this->assertTrue($first['has_more'], 'The opening page knows there is older history.');

        $newest = ChatConversationMessage::where('conversation_id', $conversation->id)->max('id');

        $polled = $this->withHeader('X-Chat-Token', $token)
            ->getJson(route('chat.messages', $conversation).'?after_id='.$newest)
            ->assertOk();

        $polled->assertJsonMissingPath('has_more');
        $this->assertSame([], $polled->json('messages'));
    }

    // --- rate limiting -------------------------------------------------------

    /**
     * One limit could not be right for all of these. Opening a conversation
     * writes rows and needs to stay tight; an attachment writes up to 10MB; a
     * poll or a typing heartbeat costs almost nothing and happens constantly.
     *
     * Asserted on the route definitions rather than by firing hundreds of
     * requests: this is about which budget each endpoint draws from, and a test
     * that actually exhausted a 120/min limiter would be slow and flaky.
     */
    public function test_each_guest_endpoint_draws_on_a_limiter_sized_for_its_cost(): void
    {
        $expected = [
            'chat.start' => 'throttle:chat-start',
            'chat.attach' => 'throttle:chat-attach',
            'chat.messages' => 'throttle:chat-widget',
            'chat.send' => 'throttle:chat-widget',
            'chat.typing' => 'throttle:chat-widget',
            'chat.rate' => 'throttle:chat-widget',
            'chat.attachment' => 'throttle:chat-widget',
            'chat.guest-auth' => 'throttle:chat-widget',
        ];

        foreach ($expected as $name => $limiter) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Route [{$name}] is missing.");

            $middleware = $route->gatherMiddleware();

            $this->assertContains($limiter, $middleware, "Route [{$name}] should be limited by [{$limiter}].");

            // The old blanket limit must not linger alongside the new ones.
            $this->assertNotContains('throttle:30,1', $middleware, "Route [{$name}] still carries the old group throttle.");
        }
    }

    /**
     * @return array{0: ChatConversation, 1: string}
     */
    private function conversationWithMessages(int $count): array
    {
        $chat = app(ChatService::class);

        $conversation = $chat->startCustomerChat(
            ['name' => 'Jo Visitor', 'email' => 'jo@example.com'],
            null,
            'message 1',
        );

        for ($i = 2; $i <= $count; $i++) {
            $chat->sendMessage($conversation, null, 'message '.$i, null, $conversation->guest_token);
        }

        return [$conversation, (string) $conversation->guest_token];
    }
}
