<?php

namespace Tests\Unit;

use App\Models\NotificationPreference;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationPreferenceService;
use App\Support\Notifications\EventCatalogue;
use App\Support\Notifications\NotificationMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotificationPreferenceServiceTest extends TestCase
{
    use RefreshDatabase;

    private NotificationPreferenceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new NotificationPreferenceService;

        foreach (['admin', 'staff', 'support', 'sales', 'marketing'] as $name) {
            Role::firstOrCreate(['name' => $name], ['label' => ucfirst($name)]);
        }

        EventCatalogue::flushAudiences();

        NotificationMatrix::flush();
    }

    /**
     * (a) No preference row => the global matrix decides; seeded default is enabled.
     */
    public function test_no_preference_row_falls_back_to_matrix_default(): void
    {
        $user = User::factory()->create(['role' => 'client']);

        $this->assertTrue(
            $this->service->isEnabled($user, 'invoice.overdue'),
            'Missing preference should fall back to the seeded matrix default (enabled).',
        );
    }

    /**
     * (a2) No preference row + a disabled matrix row => denied.
     */
    public function test_no_preference_row_respects_disabled_matrix_row(): void
    {
        $user = User::factory()->create(['role' => 'client']);

        DB::table('notification_event_settings')->updateOrInsert(
            ['event_key' => 'invoice.overdue', 'audience' => 'customer', 'channel' => 'database'],
            ['enabled' => false, 'updated_at' => now()],
        );
        NotificationMatrix::flush();

        $this->assertFalse(
            $this->service->isEnabled($user, 'invoice.overdue'),
            'A disabled matrix row must deny the notification.',
        );
    }

    /**
     * (b) A preference row enabled=false OVERRIDES an enabled matrix default.
     */
    public function test_preference_row_overrides_matrix(): void
    {
        $user = User::factory()->create(['role' => 'client']);

        NotificationPreference::create([
            'preferrable_type' => $user->getMorphClass(),
            'preferrable_id' => $user->getKey(),
            'type' => 'invoice.overdue',
            'channel' => 'database',
            'enabled' => false,
        ]);

        $this->assertFalse(
            $this->service->isEnabled($user, 'invoice.overdue'),
            'An explicit enabled=false preference must win over an enabled matrix row.',
        );
    }

    /**
     * (c) setPreference upserts — a second call updates in place, no duplicate row.
     */
    public function test_set_preference_upserts_without_duplicate(): void
    {
        $user = User::factory()->create(['role' => 'client']);

        $first = $this->service->setPreference($user, 'domain.expiring', true, 'database');
        $second = $this->service->setPreference($user, 'domain.expiring', false, 'database');

        $this->assertSame($first->id, $second->id, 'Repeated setPreference must update the same row.');
        $this->assertSame(1, NotificationPreference::count(), 'No duplicate preference rows.');
        $this->assertFalse($second->enabled, 'The second call must persist the latest value.');
        $this->assertFalse(
            $this->service->isEnabled($user, 'domain.expiring'),
            'The updated disabled flag must be reflected on read.',
        );
    }

    /**
     * (d) Unknown type with no catalogue entry and no preference row => enabled.
     */
    public function test_unknown_type_with_no_row_defaults_enabled(): void
    {
        $user = User::factory()->create(['role' => 'client']);

        $this->assertTrue(
            $this->service->isEnabled($user, 'some.unknown.type'),
            'An unknown type with no preference row must default to enabled.',
        );
    }

    /**
     * Applicability: combinations absent from the catalogue deny, seeded
     * staff-broadcast defaults enable the right audiences.
     */
    public function test_matrix_applicability_and_seed_contract(): void
    {
        $this->assertTrue(
            NotificationMatrix::allows('ticket.new', 'sales', 'database'),
            'ticket.new must be enabled for sales by default.',
        );
        $this->assertFalse(
            NotificationMatrix::allows('scheduled.report', 'sales', 'mail'),
            'scheduled.report is not applicable to sales and must deny.',
        );
        $this->assertFalse(
            NotificationMatrix::allows('invoice.overdue', 'admin', 'database'),
            'invoice.overdue has no admin audience and must deny.',
        );
        $this->assertSame(
            ['admin', 'staff', 'support'],
            NotificationMatrix::enabledStaffRoles('service.overdue', 'database'),
            'service.overdue must broadcast to admin/staff/support by default.',
        );
    }

    /**
     * preferencesFor returns only the rows for the given notifiable.
     */
    public function test_preferences_for_scoped_to_notifiable(): void
    {
        $userA = User::factory()->create(['role' => 'client']);
        $userB = User::factory()->create(['role' => 'client']);

        $this->service->setPreference($userA, 'ticket.new', true);
        $this->service->setPreference($userA, 'invoice.overdue', false);
        $this->service->setPreference($userB, 'ticket.new', false);

        $forA = $this->service->preferencesFor($userA);

        $this->assertCount(2, $forA);
        $this->assertTrue($forA->contains(fn ($p) => $p->type === 'ticket.new' && $p->enabled === true));
        $this->assertTrue($forA->contains(fn ($p) => $p->type === 'invoice.overdue' && $p->enabled === false));
    }
}
