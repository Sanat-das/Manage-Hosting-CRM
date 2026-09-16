<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\ChatOperatorAvailability;
use App\Services\ChatAvailability;
use App\Services\ChatPresence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesChatUsers;
use Tests\TestCase;

/**
 * The operator's declared "am I taking chats".
 *
 * The distinction this whole feature rests on: presence is what the BROWSER
 * knows and expires in 90 seconds; availability is what the PERSON said and
 * has to survive a closed laptop. Several of the tests below exist only to
 * prove the two have not been collapsed back into one.
 */
class ChatOperatorAvailabilityTest extends TestCase
{
    use CreatesChatUsers;
    use RefreshDatabase;

    public function test_an_operator_with_no_row_is_available(): void
    {
        // The absent case IS a value, and it is what keeps this feature from
        // changing anything on an install that never uses the control.
        $operator = $this->chatUser('chat.view', 'chat.manage');

        $this->assertSame(
            ChatOperatorAvailability::STATE_AVAILABLE,
            app(ChatAvailability::class)->stateFor($operator),
        );
    }

    public function test_setting_a_state_persists_it(): void
    {
        $operator = $this->chatUser('chat.view', 'chat.manage');

        $this->actingAs($operator)
            ->postJson(route('admin.chat.availability'), ['state' => 'away', 'note' => 'back at 3'])
            ->assertOk()
            ->assertJsonPath('state', 'away')
            ->assertJsonPath('label', 'Away')
            ->assertJsonPath('note', 'back at 3');

        $this->assertDatabaseHas('chat_operator_availability', [
            'user_id' => $operator->id,
            'state' => 'away',
            'note' => 'back at 3',
        ]);
    }

    public function test_the_state_outlives_the_presence_heartbeat_expiring(): void
    {
        // Storing this next to presence would mean "Away" silently reverting to
        // "Available" 90 seconds after someone shut their laptop, which is the
        // exact lie the feature exists to prevent.
        $operator = $this->chatUser('chat.view', 'chat.manage');
        $availability = app(ChatAvailability::class);

        $availability->set($operator, ChatOperatorAvailability::STATE_AWAY);
        app(ChatPresence::class)->leave($operator);

        $this->assertSame(ChatOperatorAvailability::STATE_AWAY, $availability->stateFor($operator->fresh()));
    }

    public function test_an_unknown_state_is_refused(): void
    {
        $this->actingAs($this->chatUser('chat.view', 'chat.manage'))
            ->postJson(route('admin.chat.availability'), ['state' => 'on-holiday'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('state');
    }

    public function test_an_over_long_note_is_refused(): void
    {
        $this->actingAs($this->chatUser('chat.view', 'chat.manage'))
            ->postJson(route('admin.chat.availability'), [
                'state' => 'busy',
                'note' => str_repeat('a', 200),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');
    }

    public function test_a_blank_note_clears_the_old_one(): void
    {
        // The control posts both fields together, so "no note" is a statement.
        // A stale "back at 3" hanging under a fresh Available is worse than
        // nothing.
        $operator = $this->chatUser('chat.view', 'chat.manage');
        $availability = app(ChatAvailability::class);

        $availability->set($operator, ChatOperatorAvailability::STATE_AWAY, 'back at 3');
        $availability->set($operator, ChatOperatorAvailability::STATE_AVAILABLE, '');

        $this->assertNull($availability->rowFor($operator)->note);
    }

    public function test_editing_only_the_note_does_not_look_like_a_fresh_state_change(): void
    {
        $operator = $this->chatUser('chat.view', 'chat.manage');
        $availability = app(ChatAvailability::class);

        $this->travelTo('2026-09-15 09:00:00');
        $availability->set($operator, ChatOperatorAvailability::STATE_AWAY, 'back at 3');
        $changedAt = $availability->rowFor($operator)->state_changed_at;

        $this->travelTo('2026-09-15 11:00:00');
        $availability->set($operator, ChatOperatorAvailability::STATE_AWAY, 'back at 4');

        $this->assertEquals($changedAt, $availability->rowFor($operator)->state_changed_at);
    }

    public function test_changing_the_state_does_move_the_timestamp(): void
    {
        $operator = $this->chatUser('chat.view', 'chat.manage');
        $availability = app(ChatAvailability::class);

        $this->travelTo('2026-09-15 09:00:00');
        $availability->set($operator, ChatOperatorAvailability::STATE_AWAY);
        $changedAt = $availability->rowFor($operator)->state_changed_at;

        $this->travelTo('2026-09-15 11:00:00');
        $availability->set($operator, ChatOperatorAvailability::STATE_AVAILABLE);

        $this->assertNotEquals($changedAt, $availability->rowFor($operator)->state_changed_at);
    }

    // --- who counts as cover -------------------------------------------------

    public function test_only_someone_both_online_and_available_counts(): void
    {
        $availability = app(ChatAvailability::class);
        $presence = app(ChatPresence::class);

        $operator = $this->chatUser('chat.view', 'chat.manage');

        // Available but not online.
        $this->assertSame(0, $availability->acceptingCount());

        $presence->heartbeat($operator);
        $availability->forget();
        $this->assertSame(1, $availability->acceptingCount());

        // Online but away.
        $availability->set($operator, ChatOperatorAvailability::STATE_AWAY);
        $this->assertSame(0, $availability->acceptingCount());
    }

    public function test_busy_does_not_accept_either(): void
    {
        // "Busy" is deliberately not "accepting but slower": an operator who
        // says they are busy and is handed the queue anyway has a control that
        // does nothing.
        $operator = $this->chatUser('chat.view', 'chat.manage');
        app(ChatPresence::class)->heartbeat($operator);

        $availability = app(ChatAvailability::class);
        $availability->set($operator, ChatOperatorAvailability::STATE_BUSY);

        $this->assertSame(0, $availability->acceptingCount());
    }

    public function test_a_chat_user_without_chat_manage_is_not_counted_as_an_operator(): void
    {
        app(ChatPresence::class)->heartbeat($this->chatUser('chat.view'));

        $availability = app(ChatAvailability::class);
        $availability->forget();

        $this->assertSame(0, $availability->acceptingCount());
    }

    public function test_the_endpoint_reports_the_consequence_of_going_away(): void
    {
        // An operator who was the last one accepting has, by going Away, just
        // closed the chat to customers. They should be able to see that.
        $operator = $this->chatUser('chat.view', 'chat.manage');
        app(ChatPresence::class)->heartbeat($operator);

        $this->actingAs($operator)
            ->postJson(route('admin.chat.availability'), ['state' => 'away'])
            ->assertOk()
            ->assertJsonPath('accepting_operators', 0);
    }

    public function test_setting_a_state_invalidates_the_cached_roster_answer(): void
    {
        $operator = $this->chatUser('chat.view', 'chat.manage');
        app(ChatPresence::class)->heartbeat($operator);

        $availability = app(ChatAvailability::class);
        $availability->forget();

        // Warm it, then change the state through the service: a cached answer
        // that survived would leave the widget open with nobody there.
        $this->assertSame(1, $availability->acceptingCount());
        $availability->set($operator, ChatOperatorAvailability::STATE_AWAY);
        $this->assertSame(0, $availability->acceptingCount());
    }

    // --- surfacing -----------------------------------------------------------

    public function test_states_for_a_roster_fill_in_the_absent_rows(): void
    {
        $away = $this->chatUser('chat.view', 'chat.manage');
        $silent = $this->chatUser('chat.view', 'chat.manage');

        $availability = app(ChatAvailability::class);
        $availability->set($away, ChatOperatorAvailability::STATE_AWAY);

        $states = $availability->statesFor([$away->id, $silent->id]);

        $this->assertSame('away', $states[$away->id]['state']);
        $this->assertSame('available', $states[$silent->id]['state']);
    }

    public function test_the_roster_payload_carries_states_alongside_the_names(): void
    {
        // Sent from the same response because the sidebar repaints the whole
        // list from it — a roster refreshed without its states would repaint
        // everyone as Available.
        $operator = $this->chatUser('chat.view', 'chat.manage');
        app(ChatPresence::class)->heartbeat($operator);
        app(ChatAvailability::class)->set($operator, ChatOperatorAvailability::STATE_BUSY);

        $this->actingAs($operator)
            ->getJson(route('admin.chat.unread'))
            ->assertOk()
            ->assertJsonPath("availability.{$operator->id}.state", 'busy')
            ->assertJsonPath("availability.{$operator->id}.label", 'Busy');
    }

    public function test_the_control_is_only_rendered_for_someone_who_can_take_a_customer_chat(): void
    {
        // For anyone else it would set a flag nothing reads, while appearing to
        // promise that marking yourself Away does something.
        $this->actingAs($this->chatUser('chat.view'))
            ->get(route('admin.chat.index'))
            ->assertOk()
            ->assertDontSee('My availability');

        $this->actingAs($this->chatUser('chat.view', 'chat.manage'))
            ->get(route('admin.chat.index'))
            ->assertOk()
            ->assertSee('My availability');
    }

    public function test_someone_without_chat_view_cannot_set_a_state(): void
    {
        $this->actingAs($this->chatUser())
            ->postJson(route('admin.chat.availability'), ['state' => 'away'])
            ->assertForbidden();
    }
}
