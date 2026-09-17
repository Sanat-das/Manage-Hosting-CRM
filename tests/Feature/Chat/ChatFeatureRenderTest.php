<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\ChatCannedReply;
use App\Models\ChatConversation;
use App\Models\ChatSetting;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Services\ChatOfficeHours;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesChatUsers;
use Tests\TestCase;

/**
 * The chat screens, checked as RENDERED HTML rather than as a 200.
 *
 * A status code proves the controller ran, not that the view came out
 * readable. `@endcan@stop` compiles perfectly well and then prints the literal
 * text "@stop" onto the page, and a view referencing a variable the controller
 * never passed prints a literal `{{ $thing }}`. Neither shows up as an error.
 */
class ChatFeatureRenderTest extends TestCase
{
    use CreatesChatUsers;
    use RefreshDatabase;

    /** Directives that mean the compiler did not consume what it should have. */
    private const LEAKED_DIRECTIVES = ['@stop', '@endif', '@endcan', '@endforeach', '@php', '@section'];

    protected function setUp(): void
    {
        parent::setUp();

        TicketDepartment::firstOrCreate(['slug' => 'support'], ['name' => 'Support', 'enabled' => true]);
        TicketService::forgetDepartmentCache();
        ChatOfficeHours::forget();
    }

    public function test_no_unrendered_blade_leaks_into_the_chat_screens(): void
    {
        $operator = $this->chatUser('chat.view', 'chat.manage');
        ChatCannedReply::factory()->create(['title' => 'Shared refund', 'shortcut' => 'refund']);

        ChatConversation::create([
            'type' => ChatConversation::TYPE_CUSTOMER_INBOX,
            'status' => ChatConversation::STATUS_CLOSED,
            'closed_at' => now(),
            'rating' => 5,
            'guest_email' => 'jo@example.com',
            'assigned_operator_id' => User::factory()->create()->id,
        ]);

        $reply = ChatCannedReply::firstOrFail();

        $pages = [
            'chat index' => route('admin.chat.index'),
            'settings' => route('admin.chat.settings.edit'),
            'satisfaction' => route('admin.chat.satisfaction'),
            'canned index' => route('admin.chat.canned-replies.index'),
            'canned create' => route('admin.chat.canned-replies.create'),
            'canned edit' => route('admin.chat.canned-replies.edit', $reply),
        ];

        foreach ($pages as $label => $url) {
            $html = $this->actingAs($operator)->get($url)->assertOk()->getContent();

            foreach (self::LEAKED_DIRECTIVES as $directive) {
                $this->assertStringNotContainsString(
                    $directive,
                    $html,
                    "{$label} ({$url}) printed a literal {$directive}",
                );
            }

            $this->assertDoesNotMatchRegularExpression(
                '/\{\{\s*\$/',
                $html,
                "{$label} ({$url}) printed a literal Blade echo",
            );
        }
    }

    public function test_the_widget_survives_being_rendered_on_a_logged_out_page(): void
    {
        // It is included from the shared layout, so anything that throws inside
        // it takes the login page, the storefront and the client portal with
        // it — which is why its office-hours lookup is wrapped.
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringContainsString('client-chat-offline', $html);
        $this->assertStringContainsString('data-open="1"', $html);
        $this->assertStringNotContainsString('@endif', $html);
    }

    public function test_the_widget_is_hidden_while_the_master_switch_is_off(): void
    {
        // The launcher, the panel and the bundle tags all live inside the
        // widget partial, so none of them may reach a guest while disabled.
        // An operator mid-conversation keeps theirs: the session still names
        // a conversation, and stranding it would end support, not pause it.
        ChatSetting::current()->update(['customer_chat_enabled' => false]);

        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringNotContainsString('id="client-chat-launcher"', $html);
        $this->assertStringNotContainsString('id="client-chat-panel"', $html);
        $this->assertStringNotContainsString('@endif', $html);

        $openHtml = $this->withSession(['chat.conversation_id' => 1])
            ->get(route('login'))->assertOk()->getContent();

        $this->assertStringContainsString('id="client-chat-launcher"', $openHtml);
    }

    public function test_the_widget_renders_the_closed_notice_and_the_offline_form(): void
    {
        $this->travelTo('2026-09-13 03:00:00');   // a Sunday
        ChatSetting::current()->update(['enforce_office_hours' => true]);
        ChatOfficeHours::forget();

        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringContainsString('data-open="0"', $html);
        $this->assertStringContainsString(ChatSetting::DEFAULT_CLOSED_MESSAGE, $html);
        $this->assertStringContainsString('We reopen Monday 09:00.', $html);

        // The intro form is present but hidden, and the offline form is the one
        // on offer. Both have to be in the DOM: the desk can shut while a tab
        // is open, and the swap then happens without a reload.
        $this->assertMatchesRegularExpression(
            '/class="client-chat__intro d-none"/',
            $html,
            'The start-a-chat form should be hidden while the desk is closed.',
        );
        $this->assertMatchesRegularExpression(
            '/class="client-chat__offline "/',
            $html,
            'The offline form should be the visible one while the desk is closed.',
        );
    }

    public function test_the_widget_ships_the_way_back_out_of_a_closed_conversation(): void
    {
        // Without this control a closed conversation is a dead end: the session
        // still names it, so the intro form stays hidden on every reload.
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringContainsString('id="client-chat-restart"', $html);
        $this->assertStringContainsString('Start a new chat', $html);
    }

    public function test_the_new_tables_are_fixed_layout_so_nothing_scrolls_sideways(): void
    {
        $operator = $this->chatUser('chat.view', 'chat.manage');

        foreach ([route('admin.chat.settings.edit'), route('admin.chat.satisfaction')] as $url) {
            $html = $this->actingAs($operator)->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('table-layout: fixed', $html, "{$url} has no fixed-layout table");
        }
    }

    public function test_the_sidebar_offers_a_way_to_find_a_channel_and_a_colleague(): void
    {
        // Both were unreachable from the UI: the channel directory did not
        // exist, and the DM service method had no route in front of it.
        $operator = $this->chatUser('chat.view');

        $html = $this->actingAs($operator)->get(route('admin.chat.index'))->assertOk()->getContent();

        $this->assertStringContainsString('id="chat-browse-open"', $html);
        $this->assertStringContainsString('id="chat-new-dm"', $html);
        $this->assertStringContainsString('id="chat-people"', $html);
        $this->assertStringContainsString('id="chat-browse"', $html);
    }

    public function test_a_channel_admin_is_offered_the_member_and_settings_controls(): void
    {
        $operator = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = app(\App\Services\ChatService::class)->createChannel('Deploys', $operator);

        $html = $this->actingAs($operator)
            ->get(route('admin.chat.index', ['c' => $channel->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="chat-members-open"', $html);
        $this->assertStringContainsString('id="chat-members-list"', $html);
        $this->assertStringContainsString('id="chat-members-add"', $html);
        $this->assertStringContainsString('id="chat-channel-settings"', $html);
        $this->assertStringContainsString('value="Deploys"', $html);
    }

    public function test_the_delete_button_is_offered_to_a_channel_admin_but_not_a_member(): void
    {
        $owner = $this->chatUser('chat.view', 'chat.create_channel');
        $member = $this->chatUser('chat.view');

        $chat = app(\App\Services\ChatService::class);
        $channel = $chat->createChannel('Deploys', $owner);
        $chat->addMember($channel, $member);

        $adminHtml = $this->actingAs($owner)
            ->get(route('admin.chat.index', ['c' => $channel->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="chat-delete"', $adminHtml);

        $memberHtml = $this->actingAs($member)
            ->get(route('admin.chat.index', ['c' => $channel->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('id="chat-delete"', $memberHtml);
    }

    public function test_a_plain_member_gets_no_channel_settings_form(): void
    {
        $owner = $this->chatUser('chat.view', 'chat.create_channel');
        $member = $this->chatUser('chat.view');

        $chat = app(\App\Services\ChatService::class);
        $channel = $chat->createChannel('Deploys', $owner);
        $chat->addMember($channel, $member);

        $html = $this->actingAs($member)
            ->get(route('admin.chat.index', ['c' => $channel->id]))
            ->assertOk()
            ->getContent();

        // The roster is still offered — seeing who else is in the room is not
        // the same as being able to rename it.
        $this->assertStringContainsString('id="chat-members-open"', $html);
        $this->assertStringNotContainsString('id="chat-channel-settings"', $html);
    }

    public function test_an_archived_channel_offers_unarchive_instead_of_archive(): void
    {
        $operator = $this->chatUser('chat.view', 'chat.create_channel');
        $chat = app(\App\Services\ChatService::class);
        $channel = $chat->createChannel('Old News', $operator);
        $chat->archive($channel);

        $html = $this->actingAs($operator)
            ->get(route('admin.chat.index', ['c' => $channel->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="chat-unarchive"', $html);
        $this->assertStringNotContainsString('id="chat-archive"', $html);
    }

    public function test_the_composer_offers_the_saved_reply_picker(): void
    {
        $operator = $this->chatUser('chat.view', 'chat.manage');

        // A conversation has to exist for the composer to render at all.
        ChatConversation::create([
            'type' => ChatConversation::TYPE_CHANNEL,
            'name' => 'general',
            'slug' => 'general',
            'created_by' => $operator->id,
        ])->participants()->create([
            'user_id' => $operator->id,
            'role' => 'member',
            'joined_at' => now(),
        ]);

        $html = $this->actingAs($operator)->get(route('admin.chat.index'))->assertOk()->getContent();

        $this->assertStringContainsString('id="chat-canned-open"', $html);
        $this->assertStringContainsString('id="chat-canned-picker"', $html);
        $this->assertStringContainsString('data-canned-url', $html);
        // The used-counter URL is a template the client fills in.
        $this->assertStringContainsString('__ID__', $html);
    }
}
