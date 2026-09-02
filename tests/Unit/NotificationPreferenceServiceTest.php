<?php

namespace Tests\Unit;

use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\NotificationPreferenceService;
use App\Support\AppSettings;
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

        // AppSettings caches settings statically for the request lifetime;
        // reset so each test reads freshly-seeded settings rows.
        $ref = new \ReflectionClass(AppSettings::class);
        $prop = $ref->getProperty('cache');
        $prop->setValue(null, null);
    }

    /**
     * (a) No preference row + a truthy settings key => enabled true.
     */
    public function test_no_preference_row_falls_back_to_truthy_setting(): void
    {
        $user = User::factory()->create();

        DB::table('settings')->updateOrInsert(
            ['setting_key' => 'notify_overdue_invoices'],
            ['setting_value' => 'yes'],
        );

        $this->assertTrue(
            $this->service->isEnabled($user, 'invoice.overdue'),
            'Missing preference should fall back to the truthy notify_overdue_invoices setting.',
        );
    }

    /**
     * (b) A preference row enabled=false OVERRIDES a truthy settings key.
     */
    public function test_preference_row_overrides_truthy_setting(): void
    {
        $user = User::factory()->create();

        DB::table('settings')->updateOrInsert(
            ['setting_key' => 'notify_overdue_invoices'],
            ['setting_value' => 'yes'],
        );

        NotificationPreference::create([
            'preferrable_type' => $user->getMorphClass(),
            'preferrable_id' => $user->getKey(),
            'type' => 'invoice.overdue',
            'channel' => 'database',
            'enabled' => false,
        ]);

        $this->assertFalse(
            $this->service->isEnabled($user, 'invoice.overdue'),
            'An explicit enabled=false preference must win over a truthy global setting.',
        );
    }

    /**
     * (c) setPreference upserts — a second call updates in place, no duplicate row.
     */
    public function test_set_preference_upserts_without_duplicate(): void
    {
        $user = User::factory()->create();

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
     * (d) Unknown type with no settings key => defaults to enabled true.
     */
    public function test_unknown_type_with_no_setting_defaults_enabled(): void
    {
        $user = User::factory()->create();

        $this->assertTrue(
            $this->service->isEnabled($user, 'some.unknown.type'),
            'An unmapped type with no preference row must default to enabled.',
        );
    }

    /**
     * The full settings-key => notification-type map is the contract T3.3 reuses.
     */
    public function test_settings_key_map_matches_contract(): void
    {
        $this->assertSame([
            'notify_overdue_invoices' => 'invoice.overdue',
            'notify_domain_expiry' => 'domain.expiring',
            'notify_new_tickets' => 'ticket.new',
        ], NotificationPreferenceService::SETTINGS_KEY_MAP);
    }

    /**
     * preferencesFor returns only the rows for the given notifiable.
     */
    public function test_preferences_for_scoped_to_notifiable(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $this->service->setPreference($userA, 'ticket.new', true);
        $this->service->setPreference($userA, 'invoice.overdue', false);
        $this->service->setPreference($userB, 'ticket.new', false);

        $forA = $this->service->preferencesFor($userA);

        $this->assertCount(2, $forA);
        $this->assertTrue($forA->contains(fn ($p) => $p->type === 'ticket.new' && $p->enabled === true));
        $this->assertTrue($forA->contains(fn ($p) => $p->type === 'invoice.overdue' && $p->enabled === false));
    }
}
