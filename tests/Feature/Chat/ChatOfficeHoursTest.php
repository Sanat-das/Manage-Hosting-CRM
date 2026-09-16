<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\ChatConversation;
use App\Models\ChatOfficeHour;
use App\Models\ChatOperatorAvailability;
use App\Models\ChatSetting;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Services\ChatOfficeHours;
use App\Services\ChatPresence;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesChatUsers;
use Tests\TestCase;

/**
 * Office hours and the out-of-hours message.
 *
 * Every test that asserts open/closed pins the clock. Without that these pass
 * or fail depending on the day of the week the suite happens to run on, which
 * is the one failure mode a schedule feature must not have.
 */
class ChatOfficeHoursTest extends TestCase
{
    use CreatesChatUsers;
    use RefreshDatabase;

    /** A Tuesday, inside the seeded Mon-Fri 09:00-18:00 window. */
    private const INSIDE_HOURS = '2026-09-15 10:00:00';

    /** The Sunday before it. */
    private const OUTSIDE_HOURS = '2026-09-13 03:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        TicketDepartment::firstOrCreate(['slug' => 'support'], ['name' => 'Support', 'enabled' => true]);
        TicketService::forgetDepartmentCache();
        ChatOfficeHours::forget();
    }

    private function enforceHours(array $overrides = []): ChatSetting
    {
        $settings = ChatSetting::current();
        $settings->update(['enforce_office_hours' => true] + $overrides);

        ChatOfficeHours::forget();

        return $settings;
    }

    // --- the default is no change -------------------------------------------

    public function test_office_hours_are_not_enforced_until_an_admin_turns_them_on(): void
    {
        // The whole point of the default: this ships to installs whose
        // customers are mid-conversation, and must not close the chat by
        // arriving. Even at 3am on a Sunday.
        $this->travelTo(self::OUTSIDE_HOURS);

        $status = app(ChatOfficeHours::class)->status();

        $this->assertTrue($status['open']);
        $this->assertSame('not_enforced', $status['reason']);
    }

    public function test_a_guest_can_still_start_a_chat_while_hours_are_unenforced(): void
    {
        $this->travelTo(self::OUTSIDE_HOURS);

        $this->postJson(route('chat.start'), [
            'name' => 'Jo Visitor',
            'email' => 'jo@example.com',
            'body' => 'My site is down',
        ])->assertCreated();
    }

    // --- the clock ----------------------------------------------------------

    public function test_inside_the_window_the_chat_is_open(): void
    {
        $this->travelTo(self::INSIDE_HOURS);
        $this->enforceHours();

        $status = app(ChatOfficeHours::class)->status();

        $this->assertTrue($status['open']);
        $this->assertSame('within_hours', $status['reason']);
    }

    public function test_outside_the_window_the_chat_is_closed_and_says_when_it_reopens(): void
    {
        $this->travelTo(self::OUTSIDE_HOURS);
        $this->enforceHours();

        $status = app(ChatOfficeHours::class)->status();

        $this->assertFalse($status['open']);
        $this->assertSame('outside_hours', $status['reason']);
        $this->assertSame(ChatSetting::DEFAULT_CLOSED_MESSAGE, $status['message']);
        $this->assertSame('Monday 09:00', $status['next_opens_at']);
    }

    public function test_a_window_that_closes_before_it_opens_runs_past_midnight(): void
    {
        // 22:00-02:00 on Monday. At 01:00 on TUESDAY the row that matters is
        // Monday's — checking only today's row is how a night shift evaluates
        // to "never open".
        ChatOfficeHour::query()->update(['is_open' => false]);
        ChatOfficeHour::query()->where('day_of_week', 1)->update([
            'is_open' => true,
            'opens_at' => '22:00:00',
            'closes_at' => '02:00:00',
        ]);

        $this->enforceHours();
        $service = app(ChatOfficeHours::class);

        $this->travelTo('2026-09-14 23:30:00');   // Monday night
        $this->assertTrue($service->status()['open'], 'Monday 23:30 is inside a 22:00-02:00 window.');

        $this->travelTo('2026-09-15 01:00:00');   // Tuesday, still Monday's shift
        $this->assertTrue($service->status()['open'], 'Tuesday 01:00 is the tail of Monday night.');

        $this->travelTo('2026-09-15 03:00:00');   // Tuesday, after it ended
        $this->assertFalse($service->status()['open'], 'Tuesday 03:00 is past the 02:00 close.');
    }

    public function test_next_opens_at_is_null_when_no_day_is_open(): void
    {
        ChatOfficeHour::query()->update(['is_open' => false]);
        $this->enforceHours();
        $this->travelTo(self::OUTSIDE_HOURS);

        $status = app(ChatOfficeHours::class)->status();

        $this->assertFalse($status['open']);
        $this->assertNull($status['next_opens_at']);
    }

    // --- the roster ---------------------------------------------------------

    public function test_an_empty_desk_closes_the_chat_during_its_own_opening_hours(): void
    {
        $this->travelTo(self::INSIDE_HOURS);
        $this->enforceHours(['require_available_operator' => true]);

        $status = app(ChatOfficeHours::class)->status();

        $this->assertFalse($status['open']);
        $this->assertSame('no_operator', $status['reason']);
        // The clock says open, so "opens at" would be a time that has passed.
        $this->assertNull($status['next_opens_at']);
    }

    public function test_an_online_available_operator_keeps_the_chat_open(): void
    {
        $this->travelTo(self::INSIDE_HOURS);
        $this->enforceHours(['require_available_operator' => true]);

        app(ChatPresence::class)->heartbeat($this->chatUser('chat.view', 'chat.manage'));

        $this->assertTrue(app(ChatOfficeHours::class)->status()['open']);
    }

    public function test_an_operator_who_is_away_does_not_count_as_cover(): void
    {
        $this->travelTo(self::INSIDE_HOURS);
        $this->enforceHours(['require_available_operator' => true]);

        $operator = $this->chatUser('chat.view', 'chat.manage');
        app(ChatPresence::class)->heartbeat($operator);
        ChatOperatorAvailability::create([
            'user_id' => $operator->id,
            'state' => ChatOperatorAvailability::STATE_AWAY,
        ]);

        $this->assertFalse(app(ChatOfficeHours::class)->status()['open']);
    }

    public function test_someone_online_without_chat_manage_is_not_an_operator(): void
    {
        $this->travelTo(self::INSIDE_HOURS);
        $this->enforceHours(['require_available_operator' => true]);

        // Holds chat.view only: they can use the chat, they cannot take a
        // customer conversation, so they are not support cover.
        app(ChatPresence::class)->heartbeat($this->chatUser('chat.view'));

        $this->assertFalse(app(ChatOfficeHours::class)->status()['open']);
    }

    // --- the widget's own endpoints ------------------------------------------

    public function test_the_availability_endpoint_tells_a_visitor_what_to_do_but_not_why(): void
    {
        $this->travelTo(self::INSIDE_HOURS);
        $this->enforceHours(['require_available_operator' => true]);

        $this->getJson(route('chat.availability'))
            ->assertOk()
            ->assertJsonPath('open', false)
            ->assertJsonPath('offline_form', true)
            // "Nobody is available" is an operational fact about the desk.
            ->assertJsonMissingPath('reason');
    }

    public function test_starting_a_chat_is_refused_while_closed(): void
    {
        $this->travelTo(self::OUTSIDE_HOURS);
        $this->enforceHours();

        $this->postJson(route('chat.start'), [
            'name' => 'Jo Visitor',
            'email' => 'jo@example.com',
            'body' => 'Anyone there?',
        ])
            ->assertStatus(409)
            ->assertJsonPath('closed', true)
            ->assertJsonPath('offline_form', true);

        $this->assertSame(0, ChatConversation::count());
    }

    public function test_closing_time_does_not_eject_a_customer_already_mid_conversation(): void
    {
        $this->travelTo(self::INSIDE_HOURS);

        $start = $this->postJson(route('chat.start'), [
            'name' => 'Jo Visitor',
            'email' => 'jo@example.com',
            'body' => 'My site is down',
        ])->assertCreated();

        $id = $start->json('conversation_id');
        $token = $start->json('token');

        // The desk shuts while they are typing.
        $this->travelTo(self::OUTSIDE_HOURS);
        $this->enforceHours();

        $this->withHeader('X-Chat-Token', $token)
            ->postJson(route('chat.send', ['conversation' => $id]), ['body' => 'Still here?'])
            ->assertCreated();

        // And re-opening the widget resumes rather than refusing.
        $this->withHeader('X-Chat-Token', $token)
            ->postJson(route('chat.start'), ['name' => 'Jo', 'email' => 'jo@example.com', 'body' => 'again'])
            ->assertOk()
            ->assertJsonPath('conversation_id', $id);
    }

    // --- the offline message -------------------------------------------------

    public function test_an_out_of_hours_message_opens_a_ticket(): void
    {
        $this->travelTo(self::OUTSIDE_HOURS);
        $this->enforceHours();

        $response = $this->postJson(route('chat.offline'), [
            'name' => 'Jo Visitor',
            'email' => 'jo@example.com',
            'body' => 'My card was charged twice',
        ]);

        $response->assertCreated()->assertJsonStructure(['ticket_no', 'message']);

        $ticket = Ticket::firstOrFail();
        $this->assertSame('jo@example.com', $ticket->guest_email);
        $this->assertSame('Jo Visitor', $ticket->guest_name);
        $this->assertStringContainsString('My card was charged twice', $ticket->subject);

        // With no department configured it is the FIRST enabled one, whatever
        // that happens to be on this install — asserting a particular slug here
        // would be asserting the seeder's ordering, not this feature.
        $this->assertSame(array_key_first(TicketService::departments()), $ticket->department);

        // It is a ticket, not a chat waiting in a queue nobody is watching.
        $this->assertSame(0, ChatConversation::count());
    }

    public function test_the_configured_department_is_honoured(): void
    {
        $this->travelTo(self::OUTSIDE_HOURS);
        $this->enforceHours(['offline_ticket_department' => 'support']);

        $this->postJson(route('chat.offline'), [
            'name' => 'Jo Visitor',
            'email' => 'jo@example.com',
            'body' => 'Which plan am I on?',
        ])->assertCreated();

        $this->assertSame('support', Ticket::firstOrFail()->department);
    }

    public function test_a_department_that_no_longer_exists_falls_back_instead_of_stranding_the_ticket(): void
    {
        $this->travelTo(self::OUTSIDE_HOURS);
        $this->enforceHours(['offline_ticket_department' => 'a-department-that-was-deleted']);

        $this->postJson(route('chat.offline'), [
            'name' => 'Jo Visitor',
            'email' => 'jo@example.com',
            'body' => 'Hello',
        ])->assertCreated();

        // Resolved against the departments that CURRENTLY exist, so a renamed
        // or disabled one cannot produce a ticket nobody can see.
        $this->assertSame(array_key_first(TicketService::departments()), Ticket::firstOrFail()->department);
    }

    public function test_a_signed_in_customers_offline_message_is_theirs_not_a_guests(): void
    {
        $this->travelTo(self::OUTSIDE_HOURS);
        $this->enforceHours();

        $user = User::factory()->create(['role' => 'client']);
        $customer = Customer::create(['user_id' => $user->id, 'status' => 'active']);

        $this->actingAs($user)
            ->postJson(route('chat.offline'), ['body' => 'Please cancel my renewal'])
            ->assertCreated();

        $ticket = Ticket::firstOrFail();
        $this->assertSame($customer->id, $ticket->customer_id);
        $this->assertNull($ticket->guest_email);
    }

    public function test_an_offline_message_is_refused_while_the_chat_is_open(): void
    {
        $this->travelTo(self::INSIDE_HOURS);
        $this->enforceHours();

        // Otherwise this is a second, unthrottled, uncaptcha'd way to file a
        // ticket that bypasses the ordinary support form.
        $this->postJson(route('chat.offline'), [
            'name' => 'Jo Visitor',
            'email' => 'jo@example.com',
            'body' => 'hello',
        ])->assertStatus(409);

        $this->assertSame(0, Ticket::count());
    }

    public function test_an_offline_message_is_refused_when_the_admin_turned_the_form_off(): void
    {
        $this->travelTo(self::OUTSIDE_HOURS);
        $this->enforceHours(['offline_form_enabled' => false]);

        $this->postJson(route('chat.offline'), [
            'name' => 'Jo Visitor',
            'email' => 'jo@example.com',
            'body' => 'hello',
        ])->assertStatus(409);

        $this->getJson(route('chat.availability'))
            ->assertJsonPath('open', false)
            ->assertJsonPath('offline_form', false);
    }

    public function test_an_offline_message_still_validates_the_guests_identity(): void
    {
        $this->travelTo(self::OUTSIDE_HOURS);
        $this->enforceHours();

        // Without an email there is no way to answer it, which is the entire
        // point of taking it.
        $this->postJson(route('chat.offline'), ['body' => 'hello'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email']);
    }

    // --- the settings screen -------------------------------------------------

    public function test_the_settings_page_needs_chat_manage(): void
    {
        $this->actingAs($this->chatUser('chat.view'))
            ->get(route('admin.chat.settings.edit'))
            ->assertForbidden();

        $this->actingAs($this->chatUser('chat.view', 'chat.manage'))
            ->get(route('admin.chat.settings.edit'))
            ->assertOk()
            ->assertSee('Enforce office hours');
    }

    public function test_saving_the_schedule_writes_all_seven_days_and_clears_the_cache(): void
    {
        $this->travelTo(self::INSIDE_HOURS);

        // Warm the cache with the pre-save answer, so a stale read would be
        // visible as a failure here rather than as "my save did nothing" in
        // production.
        $this->assertTrue(app(ChatOfficeHours::class)->status()['open']);

        $days = [];
        foreach (range(0, 6) as $day) {
            $days[$day] = ['opens_at' => '08:00', 'closes_at' => '09:00'];
        }

        $this->actingAs($this->chatUser('chat.view', 'chat.manage'))
            ->put(route('admin.chat.settings.update'), [
                'enforce_office_hours' => '1',
                'closed_message' => 'Back tomorrow.',
                'days' => $days,
            ])
            ->assertRedirect(route('admin.chat.settings.edit'))
            ->assertSessionHas('success');

        $this->assertSame(7, ChatOfficeHour::count());
        $this->assertSame(0, ChatOfficeHour::where('is_open', true)->count());

        $status = app(ChatOfficeHours::class)->status();
        $this->assertFalse($status['open'], 'The cached schedule must not survive the save.');
        $this->assertSame('Back tomorrow.', $status['message']);
    }

    public function test_the_schedule_accepts_an_overnight_window_rather_than_rejecting_it(): void
    {
        $days = [];
        foreach (range(0, 6) as $day) {
            $days[$day] = ['is_open' => '1', 'opens_at' => '22:00', 'closes_at' => '02:00'];
        }

        $this->actingAs($this->chatUser('chat.view', 'chat.manage'))
            ->put(route('admin.chat.settings.update'), ['days' => $days])
            ->assertSessionHasNoErrors();

        $this->assertSame('22:00:00', ChatOfficeHour::where('day_of_week', 1)->value('opens_at'));
    }

    public function test_an_unknown_timezone_is_refused(): void
    {
        $days = [];
        foreach (range(0, 6) as $day) {
            $days[$day] = ['opens_at' => '09:00', 'closes_at' => '18:00'];
        }

        // A bad identifier here throws at READ time, in the widget, on pages
        // that have nothing to do with chat settings.
        $this->actingAs($this->chatUser('chat.view', 'chat.manage'))
            ->put(route('admin.chat.settings.update'), [
                'timezone' => 'Mars/Olympus_Mons',
                'days' => $days,
            ])
            ->assertSessionHasErrors('timezone');
    }
}
