<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Settings\BillingSettings;
use App\Settings\DomainSettings;
use App\Settings\GeneralSettings;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TypedSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // spatie registers settings classes as container-scoped singletons;
        // flush them so each test resolves a fresh instance for its own DB.
        app()->forgetScopedInstances();

        // AppSettings caches the legacy settings table statically for the
        // request lifetime; reset so each test reads freshly-seeded rows
        // (same pattern as NotificationPreferenceServiceTest).
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

    public function test_settings_page_renders_all_existing_fields(): void
    {
        $this->actingAsSettingsAdmin()
            ->get(route('admin.settings.index'))
            ->assertStatus(200)
            ->assertSee('Company Name')
            ->assertSee('Invoice Prefix')
            ->assertSee('SMTP Host');
    }

    public function test_saving_general_settings_via_form_persists_and_reads_back(): void
    {
        $this->actingAsSettingsAdmin()
            ->post(route('admin.settings.update'), [
                'settings' => [
                    'company_name' => 'Acme Hosting',
                    'company_email' => 'billing@acme.test',
                ],
            ])
            ->assertRedirect(route('admin.settings.index'));

        $this->assertSame('Acme Hosting', app(GeneralSettings::class)->company_name);
        $this->assertSame('billing@acme.test', app(GeneralSettings::class)->company_email);

        $this->assertDatabaseHas('settings_properties', [
            'group' => 'general',
            'name' => 'company_name',
            'payload' => '"Acme Hosting"',
        ]);
    }

    public function test_empty_typed_fields_do_not_crash_save(): void
    {
        // Regression: empty form fields arrive as null (ConvertEmptyStringsToNull)
        // and must never be assigned to non-nullable typed properties.
        $this->actingAsSettingsAdmin()
            ->post(route('admin.settings.update'), [
                'settings' => [
                    'company_name' => 'Acme Hosting',
                    'company_phone' => '',
                    'domain_pricing_tier' => 'premium',
                ],
            ])
            ->assertRedirect(route('admin.settings.index'));

        $this->assertSame('Acme Hosting', app(GeneralSettings::class)->company_name);
        $this->assertSame('premium', app(DomainSettings::class)->domain_pricing_tier);
    }

    public function test_invalid_numeric_typed_value_is_rejected(): void
    {
        $response = $this->actingAsSettingsAdmin()
            ->post(route('admin.settings.update'), [
                'settings' => [
                    'invoice_next_number' => 'abc',
                ],
            ]);

        $response->assertSessionHasErrors('settings.invoice_next_number');

        $this->assertDatabaseHas('settings_properties', [
            'group' => 'billing',
            'name' => 'invoice_next_number',
            'payload' => '"1"',
        ]);
    }

    public function test_app_settings_get_returns_saved_typed_value(): void
    {
        $this->actingAsSettingsAdmin()
            ->post(route('admin.settings.update'), [
                'settings' => ['company_name' => 'Acme Hosting'],
            ])
            ->assertRedirect(route('admin.settings.index'));

        $this->assertSame('Acme Hosting', AppSettings::get('company_name'));
    }

    public function test_untyped_keys_still_write_to_legacy_settings_table(): void
    {
        $this->actingAsSettingsAdmin()
            ->post(route('admin.settings.update'), [
                'settings' => [
                    'registration_enabled' => 'no',
                    'default_currency' => 'EUR',
                ],
            ])
            ->assertRedirect(route('admin.settings.index'));

        $this->assertSame('no', DB::table('settings')->where('setting_key', 'registration_enabled')->value('setting_value'));
        $this->assertSame('EUR', DB::table('settings')->where('setting_key', 'default_currency')->value('setting_value'));
        $this->assertFalse(AppSettings::bool('registration_enabled'));
    }

    public function test_registration_middleware_blocks_when_disabled(): void
    {
        $this->actingAsSettingsAdmin()
            ->post(route('admin.settings.update'), [
                'settings' => ['registration_enabled' => 'no'],
            ])
            ->assertRedirect(route('admin.settings.index'));

        $this->assertFalse(AppSettings::bool('registration_enabled', true));

        $this->get('/register')->assertStatus(404);
    }

    public function test_registration_middleware_allows_when_enabled(): void
    {
        $this->actingAsSettingsAdmin()
            ->post(route('admin.settings.update'), [
                'settings' => ['registration_enabled' => 'yes'],
            ])
            ->assertRedirect(route('admin.settings.index'));

        $this->assertTrue(AppSettings::bool('registration_enabled', true));

        $this->get('/register')->assertStatus(200);
    }

    public function test_billing_typed_save_persists_values(): void
    {
        $this->actingAsSettingsAdmin()
            ->post(route('admin.settings.update'), [
                'settings' => [
                    'invoice_prefix' => 'INVX-',
                    'invoice_next_number' => '42',
                    'currency' => 'INR',
                    'tax_rate' => '18',
                ],
            ])
            ->assertRedirect(route('admin.settings.index'));

        $settings = app(BillingSettings::class);
        $this->assertSame('INVX-', $settings->invoice_prefix);
        $this->assertSame(42, $settings->invoice_next_number);
        $this->assertSame(18.0, $settings->tax_rate);
        $this->assertSame('INR', $settings->currency);
    }
}
