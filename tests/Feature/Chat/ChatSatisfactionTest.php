<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\ChatConversation;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Services\ChatSatisfactionReport;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesChatUsers;
use Tests\TestCase;

/**
 * The satisfaction report.
 *
 * The ratings have been collected since the customer inbox shipped and were
 * read in exactly two places, neither of which added them up. These tests are
 * mostly arithmetic — including the two guards that make the arithmetic
 * honest: closed conversations are the denominator, and a range with nothing
 * in it is 0%, not a division by zero.
 */
class ChatSatisfactionTest extends TestCase
{
    use CreatesChatUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TicketDepartment::firstOrCreate(['slug' => 'support'], ['name' => 'Support', 'enabled' => true]);
        TicketService::forgetDepartmentCache();
    }

    private function closedChat(?int $rating, array $attributes = []): ChatConversation
    {
        return ChatConversation::create([
            'type' => ChatConversation::TYPE_CUSTOMER_INBOX,
            'status' => ChatConversation::STATUS_CLOSED,
            'closed_at' => now(),
            'rating' => $rating,
            'guest_name' => 'Jo Visitor',
            'guest_email' => 'jo@example.com',
        ] + $attributes);
    }

    // --- the arithmetic ------------------------------------------------------

    public function test_the_average_and_the_response_rate_are_both_reported(): void
    {
        // An average of 5.0 from one rating across four closed chats is not a
        // 5.0 satisfaction score. Showing the average without its denominator
        // invites exactly that reading.
        $this->closedChat(5);
        $this->closedChat(3);
        $this->closedChat(null);
        $this->closedChat(null);

        $report = app(ChatSatisfactionReport::class)->build();

        $this->assertSame(4, $report['closed']);
        $this->assertSame(2, $report['rated']);
        $this->assertSame(4.0, $report['average']);
        $this->assertSame(50.0, $report['response_rate']);
    }

    public function test_an_empty_range_is_zero_rather_than_a_division_by_zero(): void
    {
        $report = app(ChatSatisfactionReport::class)->build();

        $this->assertSame(0, $report['closed']);
        $this->assertSame(0.0, $report['response_rate']);
        $this->assertNull($report['average']);
    }

    public function test_the_distribution_lists_every_star_even_the_unused_ones(): void
    {
        // A bar chart that omits the stars nobody gave is unreadable.
        $this->closedChat(5);
        $this->closedChat(5);
        $this->closedChat(1);

        $distribution = app(ChatSatisfactionReport::class)->build()['distribution'];

        $this->assertSame([1 => 1, 2 => 0, 3 => 0, 4 => 0, 5 => 2], $distribution);
    }

    public function test_an_open_conversation_is_not_in_the_denominator(): void
    {
        // It has not had the chance to be rated, so counting it would make the
        // response rate a measure of how many chats are in progress.
        $this->closedChat(5);

        ChatConversation::create([
            'type' => ChatConversation::TYPE_CUSTOMER_INBOX,
            'status' => ChatConversation::STATUS_ACTIVE,
            'guest_email' => 'someone@example.com',
        ]);

        $report = app(ChatSatisfactionReport::class)->build();

        $this->assertSame(1, $report['closed']);
        $this->assertSame(100.0, $report['response_rate']);
    }

    public function test_a_staff_channel_is_never_in_the_report(): void
    {
        ChatConversation::create([
            'type' => ChatConversation::TYPE_CHANNEL,
            'name' => 'general',
            'slug' => 'general',
            'status' => ChatConversation::STATUS_CLOSED,
            'closed_at' => now(),
            'rating' => 1,
        ]);

        $this->assertSame(0, app(ChatSatisfactionReport::class)->build()['closed']);
    }

    // --- grouping ------------------------------------------------------------

    public function test_scores_are_grouped_by_operator_using_their_display_name(): void
    {
        // There is no `name` column on users — it is an accessor over
        // first_name/last_name — so a SQL-assembled label would either select a
        // column that does not exist or need every part of it in the GROUP BY.
        $operator = User::factory()->create(['first_name' => 'Priya', 'last_name' => 'Nair']);

        $this->closedChat(5, ['assigned_operator_id' => $operator->id]);
        $this->closedChat(3, ['assigned_operator_id' => $operator->id]);

        $rows = app(ChatSatisfactionReport::class)->build()['by_operator'];

        $this->assertCount(1, $rows);
        $this->assertSame('Priya Nair', $rows[0]['name']);
        $this->assertSame(2, $rows[0]['rated']);
        $this->assertSame(4.0, $rows[0]['average']);
    }

    public function test_an_unassigned_rated_chat_is_left_out_of_the_operator_table(): void
    {
        $this->closedChat(5);

        $this->assertSame([], app(ChatSatisfactionReport::class)->build()['by_operator']);
    }

    public function test_scores_are_grouped_by_department_with_a_label_for_none(): void
    {
        $this->closedChat(4, ['department' => 'support']);
        $this->closedChat(2);

        $rows = collect(app(ChatSatisfactionReport::class)->build()['by_department'])
            ->keyBy('department');

        $this->assertSame(4.0, $rows['support']['average']);
        $this->assertSame(2.0, $rows['Unassigned']['average']);
    }

    public function test_filtering_by_department_narrows_every_figure(): void
    {
        $this->closedChat(5, ['department' => 'support']);
        $this->closedChat(1, ['department' => 'sales']);

        $report = app(ChatSatisfactionReport::class)->build(null, null, 'support');

        $this->assertSame(1, $report['closed']);
        $this->assertSame(5.0, $report['average']);
    }

    // --- the window ----------------------------------------------------------

    public function test_the_default_window_is_the_last_thirty_days(): void
    {
        $this->travelTo('2026-09-15 12:00:00');

        $recent = $this->closedChat(5);
        $old = $this->closedChat(1);
        $old->forceFill(['closed_at' => now()->subDays(60)])->save();

        $report = app(ChatSatisfactionReport::class)->build();

        $this->assertSame(1, $report['rated']);
        $this->assertSame(5.0, $report['average']);
    }

    public function test_the_range_includes_the_whole_of_its_last_day(): void
    {
        // A `to` of 2026-09-15 meaning midnight excludes everything that
        // happened on the 15th, which reads as a missing day.
        $this->travelTo('2026-09-15 16:30:00');
        $this->closedChat(5);

        $report = app(ChatSatisfactionReport::class)->build('2026-09-15', '2026-09-15');

        $this->assertSame(1, $report['rated']);
    }

    public function test_dates_entered_the_wrong_way_round_are_swapped_not_rejected(): void
    {
        $this->travelTo('2026-09-15 12:00:00');
        $this->closedChat(5);

        $report = app(ChatSatisfactionReport::class)->build('2026-09-20', '2026-09-10');

        $this->assertSame(1, $report['rated']);
    }

    public function test_an_unparseable_date_falls_back_instead_of_erroring(): void
    {
        $this->closedChat(5);

        // A hand-edited query string must not 500 a report.
        $report = app(ChatSatisfactionReport::class)->build('not-a-date', 'also-not');

        $this->assertSame(1, $report['rated']);
    }

    public function test_a_conversation_closed_before_closed_at_was_written_still_lands_in_a_window(): void
    {
        $this->travelTo('2026-09-15 12:00:00');

        $legacy = $this->closedChat(4);
        $legacy->forceFill(['closed_at' => null])->save();

        // COALESCE(closed_at, created_at): without it these rows silently leave
        // the report rather than landing somewhere.
        $this->assertSame(1, app(ChatSatisfactionReport::class)->build()['rated']);
    }

    // --- the page ------------------------------------------------------------

    public function test_the_report_page_needs_chat_manage_not_just_chat_view(): void
    {
        // These are scores per named operator: staff performance data, not part
        // of using the chat.
        $this->actingAs($this->chatUser('chat.view'))
            ->get(route('admin.chat.satisfaction'))
            ->assertForbidden();
    }

    public function test_the_report_page_renders_the_figures(): void
    {
        $operator = User::factory()->create(['first_name' => 'Priya', 'last_name' => 'Nair']);
        $this->closedChat(5, ['assigned_operator_id' => $operator->id]);

        $this->actingAs($this->chatUser('chat.view', 'chat.manage'))
            ->get(route('admin.chat.satisfaction'))
            ->assertOk()
            ->assertSee('Average rating')
            ->assertSee('Priya Nair')
            ->assertSee('5.00');
    }

    public function test_the_page_echoes_back_the_range_it_actually_applied(): void
    {
        $this->actingAs($this->chatUser('chat.view', 'chat.manage'))
            ->get(route('admin.chat.satisfaction', ['from' => '2026-09-20', 'to' => '2026-09-10']))
            ->assertOk()
            // Swapped, so the form shows the window that was used rather than
            // the one that was typed.
            ->assertSee('value="2026-09-10"', false)
            ->assertSee('value="2026-09-20"', false);
    }
}
