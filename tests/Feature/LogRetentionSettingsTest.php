<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Settings\LogRetentionSettings;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LogRetentionSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // spatie registers settings classes as container-scoped singletons;
        // flush them so each test resolves a fresh instance for its own DB.
        app()->forgetScopedInstances();

        // AppSettings caches the legacy settings table statically for the
        // request lifetime; reset so each test reads freshly-seeded rows.
        $ref = new \ReflectionClass(AppSettings::class);
        $prop = $ref->getProperty('cache');
        $prop->setValue(null, null);
    }

    private function actingAsSettingsAdmin()
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        foreach (['settings.view', 'settings.manage'] as $permName) {
            $perm = Permission::firstOrCreate(['name' => $permName], ['label' => ucfirst($permName)]);
            $adminRole->permissions()->syncWithoutDetaching($perm->id);
        }

        $user->assignRole('admin');

        return $this->actingAs($user);
    }

    public function test_saving_all_log_retention_keys_persists_and_audits(): void
    {
        $this->actingAsSettingsAdmin()
            ->post(route('admin.settings.update'), [
                'active_tab' => 'log_retention',
                'settings' => [
                    'activity_retention_days' => '45',
                    'audit_retention_days' => '400',
                    'email_retention_days' => '120',
                    'module_retention_days' => '60',
                    'domain_sync_retention_days' => '75',
                    'domain_search_retention_days' => '20',
                    'invoice_pdf_retention_days' => '500',
                    'consent_retention_days' => '0',
                ],
            ])
            ->assertRedirect(route('admin.settings.index', ['tab' => 'log_retention']));

        $settings = app(LogRetentionSettings::class);
        $this->assertSame(45, $settings->activity_retention_days);
        $this->assertSame(400, $settings->audit_retention_days);
        $this->assertSame(120, $settings->email_retention_days);
        $this->assertSame(60, $settings->module_retention_days);
        $this->assertSame(75, $settings->domain_sync_retention_days);
        $this->assertSame(20, $settings->domain_search_retention_days);
        $this->assertSame(500, $settings->invoice_pdf_retention_days);
        $this->assertSame(0, $settings->consent_retention_days);

        $row = DB::table('settings_properties')
            ->where('group', 'log_retention')
            ->where('name', 'activity_retention_days')
            ->first();
        $this->assertNotNull($row, 'settings_properties row for the log_retention group was not created.');
        $this->assertSame('45', (string) json_decode((string) $row->payload));

        $audit = DB::table('activity_log')
            ->where('action', 'settings.updated')
            ->orderByDesc('created_at')
            ->first();
        $this->assertNotNull($audit, 'No settings.updated audit row written for log_retention.');
        $props = json_decode((string) $audit->metadata, true);
        $this->assertIsArray($props);
        $this->assertSame('log_retention', $props['section']);
    }

    public function test_invalid_days_are_rejected_and_row_unchanged(): void
    {
        $this->actingAsSettingsAdmin()
            ->post(route('admin.settings.update'), [
                'active_tab' => 'log_retention',
                'settings' => ['activity_retention_days' => 'abc'],
            ])
            ->assertSessionHasErrors('settings.activity_retention_days');

        $row = DB::table('settings_properties')
            ->where('group', 'log_retention')
            ->where('name', 'activity_retention_days')
            ->first();
        $this->assertNotNull($row);
        $this->assertSame('180', (string) json_decode((string) $row->payload));
    }

    public function test_consent_zero_is_accepted(): void
    {
        $this->actingAsSettingsAdmin()
            ->post(route('admin.settings.update'), [
                'active_tab' => 'log_retention',
                'settings' => ['consent_retention_days' => '0'],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.settings.index', ['tab' => 'log_retention']));

        $this->assertSame(0, app(LogRetentionSettings::class)->consent_retention_days);
    }

    public function test_a_stray_zero_in_a_non_consent_property_falls_back_to_config(): void
    {
        // A 0 can only get in by writing around the min:1 validation; it must
        // never turn into "prune the whole stream" (subDays(0)).
        DB::table('settings_properties')
            ->where('group', 'log_retention')
            ->where('name', 'activity_retention_days')
            ->update(['payload' => '0']);

        app()->forgetScopedInstances();

        $this->assertSame(180, LogRetentionSettings::daysFor('activity_log'));
    }

    public function test_zero_is_rejected_for_non_consent_fields_over_http(): void
    {
        $this->actingAsSettingsAdmin()
            ->post(route('admin.settings.update'), [
                'active_tab' => 'log_retention',
                'settings' => ['activity_retention_days' => '0'],
            ])
            ->assertSessionHasErrors('settings.activity_retention_days');
    }

    public function test_days_for_uses_defaults_then_saved_values_and_maps_consent_zero_to_null(): void
    {
        // Fresh install → property defaults mirror config/audit.php.
        $this->assertSame(180, LogRetentionSettings::daysFor('activity_log'));
        $this->assertNull(LogRetentionSettings::daysFor('marketing_consent_log'));

        $this->actingAsSettingsAdmin()
            ->post(route('admin.settings.update'), [
                'active_tab' => 'log_retention',
                'settings' => ['activity_retention_days' => '45'],
            ])
            ->assertRedirect(route('admin.settings.index', ['tab' => 'log_retention']));

        // The stored setting wins over the config default.
        $this->assertSame(45, LogRetentionSettings::daysFor('activity_log'));

        // Consent 0 = keep forever → null even when stored as 0.
        $this->assertNull(LogRetentionSettings::daysFor('marketing_consent_log'));
    }
}
