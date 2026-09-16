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

    public function test_the_new_tables_are_fixed_layout_so_nothing_scrolls_sideways(): void
    {
        $operator = $this->chatUser('chat.view', 'chat.manage');

        foreach ([route('admin.chat.settings.edit'), route('admin.chat.satisfaction')] as $url) {
            $html = $this->actingAs($operator)->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('table-layout: fixed', $html, "{$url} has no fixed-layout table");
        }
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
