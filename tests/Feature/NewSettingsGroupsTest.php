<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Settings\AnalyticsSettings;
use App\Settings\AutomationSettings;
use App\Settings\CatalogSettings;
use App\Settings\CronSettings;
use App\Settings\DomainSettings;
use App\Settings\HostingSettings;
use App\Settings\IntegrationSettings;
use App\Settings\InventorySettings;
use App\Settings\IpamSettings;
use App\Settings\ProductSettings;
use App\Settings\RoleSettings;
use App\Settings\UserSettings;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NewSettingsGroupsTest extends TestCase
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

    public static function newGroupsProvider(): array
    {
        return [
            'domain' => [DomainSettings::class, 'domain'],
            'integration' => [IntegrationSettings::class, 'integration'],
            'hosting' => [HostingSettings::class, 'hosting'],
            'ipam' => [IpamSettings::class, 'ipam'],
            'inventory' => [InventorySettings::class, 'inventory'],
            'catalog' => [CatalogSettings::class, 'catalog'],
            'product' => [ProductSettings::class, 'product'],
            'analytics' => [AnalyticsSettings::class, 'analytics'],
            'automation' => [AutomationSettings::class, 'automation'],
            'cron' => [CronSettings::class, 'cron'],
            'role' => [RoleSettings::class, 'role'],
            'user' => [UserSettings::class, 'user'],
        ];
    }

    public static function roundTripProvider(): array
    {
        return [
            'domain' => [DomainSettings::class, 'domain_default_registrar', 'resellerclub'],
            'integration' => [IntegrationSettings::class, 'cpanel_host', 'panel.example.com'],
            'hosting' => [HostingSettings::class, 'hosting_default_panel', 'plesk'],
            'ipam' => [IpamSettings::class, 'ipam_default_ipv4_gateway', '10.0.0.1'],
            'inventory' => [InventorySettings::class, 'inventory_stock_unit', 'licenses'],
            'catalog' => [CatalogSettings::class, 'catalog_default_sort', 'name'],
            'product' => [ProductSettings::class, 'product_sku_prefix', 'HST-'],
            'analytics' => [AnalyticsSettings::class, 'analytics_tracking_code', 'G-ABCDE12345'],
            'automation' => [AutomationSettings::class, 'automation_default_workflow', 'provision'],
            'cron' => [CronSettings::class, 'cron_domain_expiry_check', 'daily'],
            'role' => [RoleSettings::class, 'role_default_role', 'client'],
            'user' => [UserSettings::class, 'user_default_timezone', 'Asia/Kolkata'],
        ];
    }

    #[DataProvider('newGroupsProvider')]
    public function test_each_new_group_class_exposes_group_and_is_registered(string $class, string $group): void
    {
        $this->assertSame($group, $class::group());
        $this->assertContains($class, config('settings.settings'));
    }

    #[DataProvider('newGroupsProvider')]
    public function test_each_new_group_class_has_typed_properties_with_defaults(string $class, string $group): void
    {
        $settings = app($class);

        $this->assertSame($group, $settings->group());
        $this->assertNotEmpty($settings->toArray());

        foreach ((new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $this->assertTrue($property->hasDefaultValue(), "{$class}::\${$property->getName()} is missing a default value");
            $this->assertNotNull($property->getDefaultValue(), "{$class}::\${$property->getName()} default is null");
            $this->assertNotNull($property->getType(), "{$class}::\${$property->getName()} is not typed");
        }
    }

    #[DataProvider('roundTripProvider')]
    public function test_each_new_group_saves_and_reads_back(string $class, string $property, string $value): void
    {
        $settings = app($class);
        $settings->fill([$property => $value]);
        $settings->save();

        app()->forgetScopedInstances();
        $reloaded = app($class);

        $this->assertSame($value, $reloaded->{$property});
        $this->assertDatabaseHas('settings_properties', [
            'group' => $class::group(),
            'name' => $property,
            'payload' => json_encode($value),
        ]);
    }

    public function test_integration_credentials_are_stored_encrypted(): void
    {
        $settings = app(IntegrationSettings::class);
        $settings->fill([
            'cpanel_api_token' => 'super-secret-token',
            'plesk_password' => 'super-secret-password',
            'resellerclub_api_key' => 'super-secret-key',
        ]);
        $settings->save();

        foreach (['cpanel_api_token', 'plesk_password', 'resellerclub_api_key'] as $key) {
            $payload = DB::table('settings_properties')
                ->where('group', 'integration')
                ->where('name', $key)
                ->value('payload');

            $this->assertNotNull($payload, "{$key} was not persisted");
            $this->assertStringContainsString('eyJpdiI6', $payload, "{$key} is not stored as ciphertext");
        }

        app()->forgetScopedInstances();
        $reloaded = app(IntegrationSettings::class);

        $this->assertSame('super-secret-token', $reloaded->cpanel_api_token);
        $this->assertSame('super-secret-password', $reloaded->plesk_password);
        $this->assertSame('super-secret-key', $reloaded->resellerclub_api_key);
    }

    public function test_saving_new_group_via_form_persists_and_reads_back(): void
    {
        $this->actingAsSettingsAdmin()
            ->post(route('admin.settings.update'), [
                'settings' => [
                    'domain_default_registrar' => 'resellerclub',
                    'domain_renewal_reminder_days' => '45',
                ],
            ])
            ->assertRedirect(route('admin.settings.index'));

        $this->assertSame('resellerclub', app(DomainSettings::class)->domain_default_registrar);
        $this->assertSame(45, app(DomainSettings::class)->domain_renewal_reminder_days);

        $this->assertDatabaseHas('settings_properties', [
            'group' => 'domain',
            'name' => 'domain_default_registrar',
            'payload' => '"resellerclub"',
        ]);
    }

    public function test_saving_integration_group_via_form_encrypts_credentials(): void
    {
        $this->actingAsSettingsAdmin()
            ->post(route('admin.settings.update'), [
                'settings' => [
                    'cpanel_host' => 'cpanel.example.com',
                    'cpanel_api_token' => 'panel-token-123',
                ],
            ])
            ->assertRedirect(route('admin.settings.index'));

        $payload = DB::table('settings_properties')
            ->where('group', 'integration')
            ->where('name', 'cpanel_api_token')
            ->value('payload');

        $this->assertStringContainsString('eyJpdiI6', $payload);
        $this->assertSame('panel-token-123', app(IntegrationSettings::class)->cpanel_api_token);
    }

    public function test_invalid_typed_value_is_rejected_for_new_group(): void
    {
        $this->actingAsSettingsAdmin()
            ->post(route('admin.settings.update'), [
                'settings' => [
                    'domain_transfer_lock_days' => 'abc',
                ],
            ])
            ->assertSessionHasErrors('settings.domain_transfer_lock_days');
    }

    public function test_app_settings_get_reads_new_typed_keys(): void
    {
        $settings = app(DomainSettings::class);
        $settings->fill(['domain_default_registrar' => 'resellerclub']);
        $settings->save();

        $this->assertSame('resellerclub', AppSettings::get('domain_default_registrar'));
        $this->assertSame('daily', AppSettings::get('cron_domain_expiry_check'));
    }

    public function test_legacy_untyped_keys_still_read_via_app_settings_fallback(): void
    {
        DB::table('settings')->updateOrInsert(
            ['setting_key' => 'quote_prefix'],
            ['setting_value' => 'QT-', 'group' => 'billing', 'updated_at' => now()]
        );
        DB::table('settings')->updateOrInsert(
            ['setting_key' => 'auto_generate_invoice'],
            ['setting_value' => 'no', 'group' => 'billing', 'updated_at' => now()]
        );

        $this->assertSame('QT-', AppSettings::get('quote_prefix'));
        $this->assertSame('no', AppSettings::get('auto_generate_invoice'));
        $this->assertFalse(AppSettings::bool('auto_generate_invoice'));
    }

    public function test_settings_page_renders_new_group_sections(): void
    {
        $this->actingAsSettingsAdmin()
            ->get(route('admin.settings.index'))
            ->assertStatus(200)
            ->assertSee('Domain Settings')
            ->assertSee('Integration Settings')
            ->assertSee('Hosting Settings')
            ->assertSee('IPAM Settings')
            ->assertSee('Inventory Settings')
            ->assertSee('Catalog Settings')
            ->assertSee('Product Settings')
            ->assertSee('Analytics Settings')
            ->assertSee('Automation Settings')
            ->assertSee('Cron Settings')
            ->assertSee('Role Settings')
            ->assertSee('User Settings');
    }

    private function actingAsSettingsAdmin(): self
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
}
