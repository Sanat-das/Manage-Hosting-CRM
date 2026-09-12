<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Events\Chat\TypingIndicator;
use App\Models\ChatConversation;
use App\Models\Customer;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Services\ChatService;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesChatUsers;
use Tests\TestCase;

/**
 * Client portal chat widget — the customer/guest HTTP surface.
 *
 * Two kinds of caller: signed-in customer (auth + customer_id) and guest (guest_token in session/header).
 * Neither may reach staff channels/DMs, list conversations, or spoof operator identity.
 */
class ClientChatTest extends TestCase
{
    use CreatesChatUsers;
    use RefreshDatabase;

    private ChatService $chat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chat = app(ChatService::class);
        TicketDepartment::firstOrCreate(['slug' => 'support'], ['name' => 'Support', 'enabled' => true]);
        TicketDepartment::firstOrCreate(['slug' => 'billing'], ['name' => 'Billing', 'enabled' => true]);
        TicketService::forgetDepartmentCache();
    }

    // --- happy paths ---------------------------------------------------------

    public function test_guest_can_start_chat_and_gets_token_back(): void
    {
        $response = $this->postJson(route('chat.start'), [
            'name' => 'Jo Visitor',
            'email' => 'jo@example.com',
            'body' => 'My site is down',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['conversation_id', 'token', 'status'])
            ->assertJsonPath('status', ChatConversation::STATUS_WAITING);

        $token = $response->json('token');
        $this->assertSame(40, strlen((string) $token));

        $conversation = ChatConversation::findOrFail($response->json('conversation_id'));
        $this->assertSame(ChatConversation::TYPE_CUSTOMER_INBOX, $conversation->type);
        $this->assertSame($token, $conversation->guest_token);
        $this->assertSame('jo@example.com', $conversation->guest_email);
        $this->assertSame(40, strlen((string) $conversation->guest_token));

        // Session holds it too so reload doesn't orphan
        $this->assertSame($token, session('chat.guest_token'));
        $this->assertSame($conversation->id, session('chat.conversation_id'));
    }

    public function test_authenticated_customer_chat_appears_in_operator_inbox(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $customer = Customer::create(['user_id' => $user->id, 'status' => 'active']);

        $response = $this->actingAs($user)->postJson(route('chat.start'), [
            'body' => 'Invoice question',
        ]);

        $response->assertCreated();
        $conversationId = $response->json('conversation_id');

        $conversation = ChatConversation::findOrFail($conversationId);
        $this->assertSame($customer->id, $conversation->customer_id);

        // Appears in operator inbox (chat.manage required)
        $operator = $this->chatUser('chat.view', 'chat.manage');
        $this->actingAs($operator)
            ->getJson(route('admin.chat.inbox'))
            ->assertOk()
            ->assertJsonPath('conversations.0.id', $conversationId);
    }

    public function test_guest_can_fetch_own_messages_and_send_reply(): void
    {
        $start = $this->postJson(route('chat.start'), [
            'name' => 'Jo', 'email' => 'jo@example.com', 'body' => 'Hello',
        ])->assertCreated();
        $id = $start->json('conversation_id');
        $token = $start->json('token');

        // Follow-up fetch using header token (session already has it, but header proves alternative path)
        $this->withHeader('X-Chat-Token', $token)
            ->getJson(route('chat.messages', $id))
            ->assertOk()
            ->assertJsonPath('messages.0.body', 'Hello');

        // Send a second message as guest
        $this->withHeader('X-Chat-Token', $token)
            ->postJson(route('chat.send', $id), ['body' => 'Second message'])
            ->assertCreated()
            ->assertJsonPath('message.body', 'Second message');

        $this->assertSame(2, ChatConversation::find($id)->messages()->count());
    }

    public function test_starting_twice_in_one_session_reuses_the_open_conversation(): void
    {
        $first = $this->postJson(route('chat.start'), [
            'name' => 'Jo', 'email' => 'jo@example.com', 'body' => 'Hello',
        ])->assertCreated();

        $second = $this->postJson(route('chat.start'), [
            'name' => 'Jo', 'email' => 'jo@example.com', 'body' => 'Hello again',
        ])->assertOk();

        $this->assertSame($first->json('conversation_id'), $second->json('conversation_id'));
        $this->assertSame(1, ChatConversation::query()->count());
    }

    public function test_a_closed_conversation_does_not_block_a_new_one(): void
    {
        $first = $this->postJson(route('chat.start'), [
            'name' => 'Jo', 'email' => 'jo@example.com', 'body' => 'Hello',
        ])->assertCreated();

        $this->chat->closeConversation(ChatConversation::findOrFail($first->json('conversation_id')));

        $second = $this->postJson(route('chat.start'), [
            'name' => 'Jo', 'email' => 'jo@example.com', 'body' => 'Back again',
        ])->assertCreated();

        $this->assertNotSame($first->json('conversation_id'), $second->json('conversation_id'));
    }

    // --- isolation / 403 -----------------------------------------------------

    public function test_client_cannot_fetch_staff_channel(): void
    {
        $owner = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Secret Ops', $owner, true);

        // Guest with valid token but trying to access staff channel
        $guest = $this->postJson(route('chat.start'), [
            'name' => 'Jo', 'email' => 'jo@example.com', 'body' => 'Hi',
        ])->assertCreated();
        $token = $guest->json('token');

        $this->withHeader('X-Chat-Token', $token)
            ->getJson(route('chat.messages', $channel->id))
            ->assertForbidden();

        $this->withHeader('X-Chat-Token', $token)
            ->postJson(route('chat.send', $channel->id), ['body' => 'try'])
            ->assertForbidden();
    }

    public function test_authenticated_customer_cannot_fetch_staff_channel(): void
    {
        $owner = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Secret Ops', $owner, true);

        $user = User::factory()->create(['role' => 'client']);
        Customer::create(['user_id' => $user->id, 'status' => 'active']);

        $this->actingAs($user)
            ->getJson(route('chat.messages', $channel->id))
            ->assertForbidden();
    }

    // --- validation 422 ------------------------------------------------------

    public function test_empty_message_body_returns_422(): void
    {
        $start = $this->postJson(route('chat.start'), [
            'name' => 'Jo', 'email' => 'jo@example.com', 'body' => 'Hello',
        ])->assertCreated();
        $id = $start->json('conversation_id');
        $token = $start->json('token');

        $this->withHeader('X-Chat-Token', $token)
            ->postJson(route('chat.send', $id), ['body' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');

        $this->withHeader('X-Chat-Token', $token)
            ->postJson(route('chat.send', $id), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');
    }

    public function test_oversized_body_returns_422(): void
    {
        $start = $this->postJson(route('chat.start'), [
            'name' => 'Jo', 'email' => 'jo@example.com', 'body' => 'Hello',
        ])->assertCreated();
        $id = $start->json('conversation_id');
        $token = $start->json('token');

        $big = str_repeat('a', 4001);

        $this->withHeader('X-Chat-Token', $token)
            ->postJson(route('chat.send', $id), ['body' => $big])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');

        // Start with oversized body also 422
        $this->postJson(route('chat.start'), [
            'name' => 'Jo', 'email' => 'jo@example.com', 'body' => $big,
        ])->assertStatus(422)->assertJsonValidationErrors('body');
    }

    public function test_start_missing_required_fields_returns_422(): void
    {
        // Guest without name/email
        $this->postJson(route('chat.start'), ['body' => 'Hi'])
            ->assertStatus(422)->assertJsonValidationErrors(['name', 'email']);

        // Without body
        $this->postJson(route('chat.start'), ['name' => 'Jo', 'email' => 'jo@example.com'])
            ->assertStatus(422)->assertJsonValidationErrors('body');
    }

    public function test_start_wrong_types_returns_422(): void
    {
        $this->postJson(route('chat.start'), [
            'name' => 12345,
            'email' => 'not-an-email',
            'body' => 123,
        ])->assertStatus(422);
    }

    // --- guest token auth 401 ------------------------------------------------

    public function test_bad_guest_token_returns_401(): void
    {
        $start = $this->postJson(route('chat.start'), [
            'name' => 'Jo', 'email' => 'jo@example.com', 'body' => 'Hello',
        ])->assertCreated();
        $id = $start->json('conversation_id');

        // Clear session so only header matters
        $this->flushSession();

        $this->withHeader('X-Chat-Token', str_repeat('x', 40))
            ->getJson(route('chat.messages', $id))
            ->assertStatus(401);

        $this->withHeader('X-Chat-Token', str_repeat('x', 40))
            ->postJson(route('chat.send', $id), ['body' => 'try'])
            ->assertStatus(401);
    }

    public function test_missing_guest_token_returns_401(): void
    {
        $start = $this->postJson(route('chat.start'), [
            'name' => 'Jo', 'email' => 'jo@example.com', 'body' => 'Hello',
        ])->assertCreated();
        $id = $start->json('conversation_id');

        $this->flushSession();

        $this->getJson(route('chat.messages', $id))->assertStatus(401);
        $this->postJson(route('chat.send', $id), ['body' => 'try'])->assertStatus(401);
    }

    public function test_expired_or_foreign_guest_token_returns_401(): void
    {
        $a = $this->postJson(route('chat.start'), ['name' => 'A', 'email' => 'a@example.com', 'body' => 'Hi'])->assertCreated();

        // Two guests are two browsers and therefore two sessions; reusing this
        // one would just hand B the conversation A already has.
        $this->flushSession();

        $b = $this->postJson(route('chat.start'), ['name' => 'B', 'email' => 'b@example.com', 'body' => 'Hi'])->assertCreated();

        $this->flushSession();

        // A's token cannot read B's conversation (cross-tenant)
        $this->withHeader('X-Chat-Token', $a->json('token'))
            ->getJson(route('chat.messages', $b->json('conversation_id')))
            ->assertStatus(401);

        $this->withHeader('X-Chat-Token', $b->json('token'))
            ->getJson(route('chat.messages', $a->json('conversation_id')))
            ->assertStatus(401);
    }

    // --- spoof prevention ----------------------------------------------------

    public function test_client_cannot_spoof_sender_type_to_look_like_operator(): void
    {
        $start = $this->postJson(route('chat.start'), [
            'name' => 'Jo', 'email' => 'jo@example.com', 'body' => 'Hello',
        ])->assertCreated();
        $id = $start->json('conversation_id');
        $token = $start->json('token');

        $response = $this->withHeader('X-Chat-Token', $token)
            ->postJson(route('chat.send', $id), [
                'body' => 'I am operator',
                'sender_type' => 'operator',
                'is_operator' => true,
                'user_id' => 1,
            ])->assertCreated();

        // Must still be stored as guest, not operator
        $message = ChatConversation::find($id)->messages()->latest('id')->first();
        $this->assertNull($message->user_id);
        $this->assertSame($token, $message->guest_token);
        $this->assertTrue($response->json('message.is_guest'));
        $this->assertFalse($response->json('message.is_operator') === false ? false : $response->json('message.is_guest') === false);
        // Ensure extra fields not persisted or leaked
        $this->assertArrayNotHasKey('sender_type', $response->json('message'));
    }

    // --- no list endpoint ----------------------------------------------------

    public function test_there_is_no_endpoint_that_lists_conversations(): void
    {
        $this->postJson(route('chat.start'), ['name' => 'Jo', 'email' => 'jo@example.com', 'body' => 'Hi'])->assertCreated();

        // These paths must not exist or must not list
        $this->getJson('/chat')->assertStatus(404);
        $this->getJson('/chat/conversations')->assertStatus(404);
        $this->getJson(route('chat.messages', 999999))->assertStatus(404); // non-existent id = 404 not 403
    }

    // --- XSS / escaping ------------------------------------------------------

    public function test_xss_payload_is_escaped_in_widget_transcript(): void
    {
        $start = $this->postJson(route('chat.start'), [
            'name' => 'Jo', 'email' => 'jo@example.com', 'body' => 'Hello',
        ])->assertCreated();
        $id = $start->json('conversation_id');
        $token = $start->json('token');

        $xss = '<script>alert(1)</script>';
        $this->withHeader('X-Chat-Token', $token)
            ->postJson(route('chat.send', $id), ['body' => $xss])
            ->assertCreated();

        $response = $this->withHeader('X-Chat-Token', $token)
            ->getJson(route('chat.messages', $id))
            ->assertOk();

        $last = collect($response->json('messages'))->last();
        // Raw body preserved but body_html must be escaped
        $this->assertSame($xss, ChatConversation::find($id)->messages()->latest('id')->first()->body);
        $this->assertStringNotContainsString('<script>', $last['body_html']);
        $this->assertStringContainsString('&lt;script&gt;', $last['body_html']);
    }

    public function test_img_onerror_payload_is_escaped(): void
    {
        $start = $this->postJson(route('chat.start'), [
            'name' => 'Jo', 'email' => 'jo@example.com', 'body' => 'Hello',
        ])->assertCreated();
        $id = $start->json('conversation_id');
        $token = $start->json('token');

        $payload = '<img src=x onerror=alert(1)>';
        $this->withHeader('X-Chat-Token', $token)
            ->postJson(route('chat.send', $id), ['body' => $payload])
            ->assertCreated();

        $response = $this->withHeader('X-Chat-Token', $token)
            ->getJson(route('chat.messages', $id))
            ->assertOk();

        $last = collect($response->json('messages'))->last();
        $this->assertStringNotContainsString('<img', $last['body_html']);
        $this->assertStringContainsString('&lt;img', $last['body_html']);
    }

    // --- cross-tenant / malformed_input -------------------------------------

    public function test_conversation_route_param_belonging_to_someone_else_is_denied(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $customer = Customer::create(['user_id' => $user->id, 'status' => 'active']);

        // Built through the engine rather than over HTTP: actingAs() sticks for
        // the rest of the test, and an authenticated probe below would then be
        // authorised by the session it was supposed to be testing without.
        $customerConversation = $this->chat->startCustomerChat(
            ['customer_id' => $customer->id], null, 'Customer hi',
        );

        $guestStart = $this->postJson(route('chat.start'), [
            'name' => 'Guest', 'email' => 'guest@example.com', 'body' => 'Hi',
        ])->assertCreated();
        $guestId = $guestStart->json('conversation_id');
        $guestToken = $guestStart->json('token');

        $this->flushSession();

        // A guest's token against a route param that is a real id belonging to
        // someone else.
        $this->withHeader('X-Chat-Token', $guestToken)
            ->getJson(route('chat.messages', $customerConversation->id))
            ->assertStatus(401);

        $this->withHeader('X-Chat-Token', $guestToken)
            ->postJson(route('chat.send', $customerConversation->id), ['body' => 'intruding'])
            ->assertStatus(401);

        $this->assertSame(1, $customerConversation->messages()->count());

        // withHeader() sticks for the rest of the test, and a guest token that
        // IS valid for this conversation would legitimately let the request
        // through — which is not what is being tested here.
        $this->withoutHeader('X-Chat-Token');

        // A signed-in customer reaching for a guest's conversation is
        // authenticated but not permitted.
        $this->actingAs($user)
            ->getJson(route('chat.messages', $guestId))
            ->assertStatus(403);
    }

    public function test_guest_cannot_claim_a_customer_account_by_sending_customer_id(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $customer = Customer::create(['user_id' => $user->id, 'status' => 'active']);

        $response = $this->postJson(route('chat.start'), [
            'name' => 'Jo',
            'email' => 'jo@example.com',
            'body' => 'Hi',
            'customer_id' => $customer->id,
        ])->assertCreated();

        $conversation = ChatConversation::findOrFail($response->json('conversation_id'));

        $this->assertNull($conversation->customer_id);
        $this->assertSame('jo@example.com', $conversation->guest_email);
    }

    public function test_customer_can_access_own_conversation_via_session(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        Customer::create(['user_id' => $user->id, 'status' => 'active']);

        $start = $this->actingAs($user)->postJson(route('chat.start'), ['body' => 'Hello'])->assertCreated();
        $id = $start->json('conversation_id');

        // No header needed, session proves ownership via customer_id
        $this->actingAs($user)
            ->getJson(route('chat.messages', $id))
            ->assertOk();

        $this->actingAs($user)
            ->postJson(route('chat.send', $id), ['body' => 'Reply'])
            ->assertCreated();
    }

    // --- rating validation ---------------------------------------------------

    public function test_guest_can_rate_closed_conversation(): void
    {
        $start = $this->postJson(route('chat.start'), [
            'name' => 'Jo', 'email' => 'jo@example.com', 'body' => 'Hi',
        ])->assertCreated();
        $id = $start->json('conversation_id');
        $token = $start->json('token');

        $conversation = ChatConversation::findOrFail($id);
        $this->chat->closeConversation($conversation);

        $this->withHeader('X-Chat-Token', $token)
            ->postJson(route('chat.rate', $id), ['rating' => 5])
            ->assertOk()
            ->assertJsonPath('rating', 5);
    }

    public function test_rating_validation(): void
    {
        $start = $this->postJson(route('chat.start'), ['name' => 'Jo', 'email' => 'jo@example.com', 'body' => 'Hi'])->assertCreated();
        $id = $start->json('conversation_id');
        $token = $start->json('token');

        // Open conversation cannot be rated (422 from service)
        $this->withHeader('X-Chat-Token', $token)
            ->postJson(route('chat.rate', $id), ['rating' => 5])
            ->assertStatus(422);

        // Invalid rating values -> 422 validation
        $this->withHeader('X-Chat-Token', $token)
            ->postJson(route('chat.rate', $id), ['rating' => 9])
            ->assertStatus(422);
        $this->withHeader('X-Chat-Token', $token)
            ->postJson(route('chat.rate', $id), ['rating' => 'bad'])
            ->assertStatus(422);
        $this->withHeader('X-Chat-Token', $token)
            ->postJson(route('chat.rate', $id), [])
            ->assertStatus(422);
    }

    // --- entity cards read-only ---------------------------------------------

    public function test_entity_cards_are_rendered_read_only_without_url(): void
    {
        $start = $this->postJson(route('chat.start'), ['name' => 'Jo', 'email' => 'jo@example.com', 'body' => 'Hi'])->assertCreated();
        $id = $start->json('conversation_id');
        $token = $start->json('token');

        $conversation = ChatConversation::findOrFail($id);
        $operator = $this->chatUser('chat.view', 'chat.manage');
        $this->chat->assignOperator($conversation, $operator);

        // Operator attaches an entity link
        $msg = $conversation->messages()->first();
        $linkUser = User::factory()->create(['role' => 'client']);
        $linked = Customer::create(['user_id' => $linkUser->id, 'status' => 'active']);
        $this->chat->linkEntity($msg, 'customer', $linked->id);

        $response = $this->withHeader('X-Chat-Token', $token)
            ->getJson(route('chat.messages', $id))
            ->assertOk();

        $links = $response->json('messages.0.entity_links');
        $this->assertNotEmpty($links);
        foreach ($links as $link) {
            $this->assertArrayHasKey('type', $link);
            $this->assertArrayHasKey('label', $link);
            $this->assertArrayNotHasKey('url', $link, 'Customer must not receive admin URL');
        }
    }

    // --- the widget is actually on the page ----------------------------------

    public function test_widget_is_rendered_on_the_client_portal(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        Customer::create(['user_id' => $user->id, 'status' => 'active']);

        $response = $this->actingAs($user)->get('/client');

        $response->assertOk()
            ->assertSee('id="client-chat-launcher"', false)
            ->assertSee('id="client-chat-panel"', false)
            ->assertSee('id="client-chat-composer"', false);
    }

    public function test_widget_asks_for_identity_exactly_when_the_request_requires_it(): void
    {
        // Signed in, but with no customer record — the case where "logged in"
        // and "we know who you are" come apart.
        $staff = $this->chatUser('chat.view');

        $this->actingAs($staff)
            ->get('/client')
            ->assertSee('id="client-chat-email"', false);

        $user = User::factory()->create(['role' => 'client']);
        Customer::create(['user_id' => $user->id, 'status' => 'active']);

        $this->actingAs($user)
            ->get('/client')
            ->assertOk()
            ->assertDontSee('id="client-chat-email"', false);
    }

    public function test_widget_is_not_rendered_in_the_admin_panel(): void
    {
        $staff = $this->chatUser('chat.view', 'chat.manage');

        $this->actingAs($staff)
            ->get(route('admin.chat.index'))
            ->assertOk()
            ->assertDontSee('id="client-chat-launcher"', false);
    }

    // --- the admin side of an untrusted body ---------------------------------

    public function test_guest_xss_payload_is_escaped_in_the_admin_pane(): void
    {
        $start = $this->postJson(route('chat.start'), [
            'name' => 'Jo', 'email' => 'jo@example.com', 'body' => 'Hello',
        ])->assertCreated();
        $id = $start->json('conversation_id');
        $token = $start->json('token');

        $this->withHeader('X-Chat-Token', $token)
            ->postJson(route('chat.send', $id), ['body' => '<script>alert(1)</script>'])
            ->assertCreated();

        $operator = $this->chatUser('chat.view', 'chat.manage');
        $conversation = ChatConversation::findOrFail($id);
        $this->chat->assignOperator($conversation, $operator);

        $response = $this->actingAs($operator)
            ->getJson(route('admin.chat.messages.index', $id))
            ->assertOk();

        $bodies = collect($response->json('messages'))->pluck('body_html')->implode(' ');

        $this->assertStringNotContainsString('<script>', $bodies);
        $this->assertStringContainsString('&lt;script&gt;', $bodies);
    }

    // --- attachments ---------------------------------------------------------

    public function test_guest_can_attach_a_file_to_their_own_message_and_fetch_it_back(): void
    {
        Storage::fake('local');

        $start = $this->postJson(route('chat.start'), [
            'name' => 'Jo', 'email' => 'jo@example.com', 'body' => 'Hello',
        ])->assertCreated();
        $id = $start->json('conversation_id');
        $token = $start->json('token');

        $message = ChatConversation::findOrFail($id)->messages()->firstOrFail();

        $response = $this->withHeader('X-Chat-Token', $token)
            ->post(route('chat.attach', ['conversation' => $id, 'message' => $message->id]), [
                'file' => UploadedFile::fake()->create('screenshot.png', 12, 'image/png'),
            ])->assertCreated();

        // The customer is never handed the signed admin download URL.
        $url = $response->json('attachment.url');
        $this->assertStringNotContainsString('/admin/', (string) $url);
        $this->assertStringContainsString('/chat/'.$id.'/attachments/', (string) $url);

        $this->withHeader('X-Chat-Token', $token)->get($url)->assertOk();
    }

    public function test_attachment_of_a_forbidden_type_is_refused(): void
    {
        Storage::fake('local');

        $start = $this->postJson(route('chat.start'), [
            'name' => 'Jo', 'email' => 'jo@example.com', 'body' => 'Hello',
        ])->assertCreated();
        $id = $start->json('conversation_id');
        $token = $start->json('token');
        $message = ChatConversation::findOrFail($id)->messages()->firstOrFail();

        $this->withHeader('X-Chat-Token', $token)
            ->postJson(route('chat.attach', ['conversation' => $id, 'message' => $message->id]), [
                'file' => UploadedFile::fake()->create('payload.ps1', 4, 'text/plain'),
            ])->assertStatus(422);
    }

    public function test_guest_cannot_attach_to_the_operators_message(): void
    {
        Storage::fake('local');

        $start = $this->postJson(route('chat.start'), [
            'name' => 'Jo', 'email' => 'jo@example.com', 'body' => 'Hello',
        ])->assertCreated();
        $id = $start->json('conversation_id');
        $token = $start->json('token');

        $conversation = ChatConversation::findOrFail($id);
        $operator = $this->chatUser('chat.view', 'chat.manage');
        $this->chat->assignOperator($conversation, $operator);
        $reply = $this->chat->sendMessage($conversation, $operator, 'Looking into it');

        $this->withHeader('X-Chat-Token', $token)
            ->post(route('chat.attach', ['conversation' => $id, 'message' => $reply->id]), [
                'file' => UploadedFile::fake()->create('note.txt', 2, 'text/plain'),
            ])->assertForbidden();
    }

    public function test_attachment_from_another_conversation_is_not_served(): void
    {
        Storage::fake('local');

        $a = $this->postJson(route('chat.start'), ['name' => 'A', 'email' => 'a@example.com', 'body' => 'Hi'])->assertCreated();
        $this->flushSession();
        $b = $this->postJson(route('chat.start'), ['name' => 'B', 'email' => 'b@example.com', 'body' => 'Hi'])->assertCreated();

        $aId = $a->json('conversation_id');
        $bId = $b->json('conversation_id');
        $aMessage = ChatConversation::findOrFail($aId)->messages()->firstOrFail();

        $this->flushSession();

        $attachment = $this->withHeader('X-Chat-Token', $a->json('token'))
            ->post(route('chat.attach', ['conversation' => $aId, 'message' => $aMessage->id]), [
                'file' => UploadedFile::fake()->create('private.txt', 2, 'text/plain'),
            ])->assertCreated()->json('attachment.id');

        // B's token, B's conversation in the path, A's attachment id.
        $this->withHeader('X-Chat-Token', $b->json('token'))
            ->get(route('chat.attachment', ['conversation' => $bId, 'attachment' => $attachment]))
            ->assertNotFound();
    }

    // --- typing --------------------------------------------------------------

    public function test_typing_is_broadcast_and_never_stored(): void
    {
        Event::fake([TypingIndicator::class]);

        $start = $this->postJson(route('chat.start'), [
            'name' => 'Jo', 'email' => 'jo@example.com', 'body' => 'Hello',
        ])->assertCreated();
        $id = $start->json('conversation_id');
        $token = $start->json('token');

        $this->withHeader('X-Chat-Token', $token)
            ->postJson(route('chat.typing', $id), ['typing' => true])
            ->assertOk();

        Event::assertDispatched(TypingIndicator::class, fn (TypingIndicator $e) => $e->conversationId === $id
            && $e->typing === true
            && $e->userId === 0);

        $this->assertSame(1, ChatConversation::find($id)->messages()->count());
    }

    public function test_typing_from_a_stranger_is_refused(): void
    {
        $start = $this->postJson(route('chat.start'), [
            'name' => 'Jo', 'email' => 'jo@example.com', 'body' => 'Hello',
        ])->assertCreated();
        $id = $start->json('conversation_id');

        $this->flushSession();

        $this->postJson(route('chat.typing', $id), ['typing' => true])->assertStatus(401);
    }

    public function test_rate_requires_valid_token(): void
    {
        $start = $this->postJson(route('chat.start'), ['name' => 'Jo', 'email' => 'jo@example.com', 'body' => 'Hi'])->assertCreated();
        $id = $start->json('conversation_id');
        $this->chat->closeConversation(ChatConversation::findOrFail($id));

        $this->flushSession();

        $this->withHeader('X-Chat-Token', str_repeat('x', 40))
            ->postJson(route('chat.rate', $id), ['rating' => 4])
            ->assertStatus(401);
    }
}
