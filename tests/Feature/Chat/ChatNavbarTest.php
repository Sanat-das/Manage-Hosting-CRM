<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\User;
use App\Services\ChatService;
use App\Support\ChatNavbar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesChatUsers;
use Tests\TestCase;

/**
 * The navbar messages dropdown reads Live Chat, never demo data.
 */
class ChatNavbarTest extends TestCase
{
    use CreatesChatUsers;
    use RefreshDatabase;

    private ChatService $chat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chat = app(ChatService::class);
    }

    public function test_a_user_without_chat_view_gets_nothing(): void
    {
        $user = $this->chatUser();

        $this->assertSame([], ChatNavbar::messages($user));
        $this->assertSame(0, ChatNavbar::messageCount($user));
        $this->assertSame([], ChatNavbar::messages(null));
        $this->assertSame(0, ChatNavbar::messageCount(null));
    }

    public function test_unread_conversations_appear_with_deep_links(): void
    {
        $alice = $this->chatUser('chat.view', 'chat.create_channel');
        $bob = $this->chatUser('chat.view');

        $channel = $this->chat->createChannel('Ops', $alice);
        $this->chat->addMember($channel, $bob);
        $this->chat->sendMessage($channel, $alice, 'deploy at dawn');

        $items = ChatNavbar::messages($bob);

        $this->assertCount(1, $items);

        $row = $items[0];

        $this->assertSame($channel->id, $row['id']);
        $this->assertStringContainsString('c='.$channel->id, $row['url']);
        $this->assertStringContainsString('deploy at dawn', $row['text']);
        $this->assertSame(1, $row['unread']);
        $this->assertSame(1, ChatNavbar::messageCount($bob));

        foreach (['Brad Diesel', 'John Pierce', 'Nora Silvester'] as $demoName) {
            $this->assertStringNotContainsString($demoName, json_encode($items));
        }
    }

    public function test_a_waiting_customer_leads_for_operators_only(): void
    {
        $operator = $this->chatUser('chat.view', 'chat.manage');
        $plain = $this->chatUser('chat.view');

        $conversation = $this->chat->startCustomerChat(
            ['name' => 'Guest One', 'email' => 'guest-one@example.com'],
            'support',
            'hello, anyone there?',
        );

        $forOperator = ChatNavbar::messages($operator);
        $forPlain = ChatNavbar::messages($plain);

        $this->assertNotEmpty($forOperator);
        $this->assertSame($conversation->id, $forOperator[0]['id']);
        // The pill says "waiting" — the line below must not repeat it.
        $this->assertSame('hello, anyone there? · support', $forOperator[0]['text']);
        $this->assertStringNotContainsString('Waiting for an operator', $forOperator[0]['text']);
        $this->assertTrue($forOperator[0]['waiting']);
        $this->assertSame('warning', $forOperator[0]['color'], 'Waiting rooms are always amber.');
        $this->assertSame([], $forPlain, 'Plain chat.view must not enumerate the customer queue.');
        $this->assertSame(1, ChatNavbar::messageCount($operator));
    }

    public function test_a_waiting_customer_without_a_department_falls_back_gracefully(): void
    {
        $operator = $this->chatUser('chat.view', 'chat.manage');

        $this->chat->startCustomerChat(
            ['name' => 'Guest Two', 'email' => 'guest-two@example.com'],
            null,
            'hello?',
        );

        $row = ChatNavbar::messages($operator)[0];

        // No department: the opening words stand in, not a status echo.
        $this->assertSame('hello?', $row['text']);
        $this->assertStringNotContainsString('Waiting for an operator', $row['text']);
    }

    public function test_reading_clears_the_dropdown(): void
    {
        $alice = $this->chatUser('chat.view', 'chat.create_channel');
        $bob = $this->chatUser('chat.view');

        $channel = $this->chat->createChannel('Ops', $alice);
        $this->chat->addMember($channel, $bob);
        $this->chat->sendMessage($channel, $alice, 'one');

        $this->assertNotEmpty(ChatNavbar::messages($bob));

        $this->chat->markRead($channel, $bob);

        $this->assertSame([], ChatNavbar::messages($bob));
        $this->assertSame(0, ChatNavbar::messageCount($bob));
    }

    public function test_guests_and_non_staff_see_no_dropdown_data(): void
    {
        $this->assertSame([], ChatNavbar::messages(new User));
    }

    public function test_monogram_colour_is_stable_and_palette_bound(): void
    {
        $alice = $this->chatUser('chat.view', 'chat.create_channel');
        $bob = $this->chatUser('chat.view');

        $channel = $this->chat->createChannel('Ops', $alice);
        $this->chat->addMember($channel, $bob);
        $this->chat->sendMessage($channel, $alice, 'one');

        $first = ChatNavbar::messages($bob)[0];
        $second = ChatNavbar::messages($bob)[0];

        $this->assertContains($first['color'], ChatNavbar::COLORS);
        $this->assertSame($first['color'], $second['color'], 'One room keeps one colour.');
        $this->assertSame('O', $first['initials'], 'Channel Ops monogram.');
    }

    public function test_navbar_endpoint_returns_rows_and_count(): void
    {
        $alice = $this->chatUser('chat.view', 'chat.create_channel');
        $bob = $this->chatUser('chat.view');

        $channel = $this->chat->createChannel('Ops', $alice);
        $this->chat->addMember($channel, $bob);
        $this->chat->sendMessage($channel, $alice, 'deploy at dawn');

        $response = $this->actingAs($bob)->getJson(route('admin.chat.navbar'));

        $response->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('messages.0.id', $channel->id)
            ->assertJsonPath('messages.0.initials', ChatNavbar::messages($bob)[0]['initials']);

        $row = $response->json('messages.0');

        foreach (['id', 'name', 'initials', 'color', 'text', 'time', 'url', 'unread', 'waiting'] as $key) {
            $this->assertArrayHasKey($key, $row, "Navbar row is missing {$key}.");
        }
    }

    public function test_navbar_endpoint_is_forbidden_without_chat_view(): void
    {
        $denied = $this->chatUser();

        $this->actingAs($denied)
            ->getJson(route('admin.chat.navbar'))
            ->assertForbidden();
    }

    public function test_navbar_endpoint_redirects_guests_to_login(): void
    {
        $this->getJson(route('admin.chat.navbar'))
            ->assertRedirect(route('admin.login'));
    }
}
