<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Settings\HostingSettings;
use App\Settings\IntegrationSettings;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression guards for two admin-settings persistence bugs.
 *
 * A. Bool coercion (CRITICAL): PHP weak mode coerces ANY non-empty string —
 *    including 'no' and '0' — to true when assigned to a native typed bool
 *    property, so every Yes/No toggle persisted TRUE regardless of selection.
 *    saveTyped() must convert form values for bool-typed properties BEFORE
 *    fill(), and loadAll() must render them back as 'yes'/'no' so the select
 *    shows the stored selection.
 *
 * B. Untyped blank overwrite: ConvertEmptyStringsToNull turns blank legacy
 *    fields into null; saveUntyped() used to write NULL over the old value
 *    while the audit diff loop skips nulls — a silent unaudited wipe.
 *    Option A (PINNED): empty keeps old. saveUntyped() must skip nulls.
 */
class SettingsBoolAndBlankTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Spatie settings are container-scoped singletons; flush so each test
        // resolves a fresh instance for its own DB (same as TypedSettingsTest).
        app()->forgetScopedInstances();

        $ref = new \ReflectionClass(AppSettings::class);
        $prop = $ref->getProperty('cache');
        $prop->setValue(null, null);
    }

    public function test_bool_typed_setting_persists_no_as_false(): void
    {
        $this->actingAsSettingsAdmin();

        $this->post(route('admin.settings.update'), [
            'settings' => ['cpanel_enabled' => 'no'],
        ])->assertRedirect(route('admin.settings.index'));

        $this->assertSame(
            false,
            app(IntegrationSettings::class)->cpanel_enabled,
            "'no' must persist as boolean false — weak-mode coercion made every toggle true."
        );
        $this->assertDatabaseHas('settings_properties', [
            'group' => 'integration',
            'name' => 'cpanel_enabled',
            'payload' => 'false',
        ]);
    }

    public function test_bool_typed_setting_persists_yes_as_true(): void
    {
        $this->actingAsSettingsAdmin();

        $this->post(route('admin.settings.update'), [
            'settings' => ['cpanel_enabled' => 'yes'],
        ])->assertRedirect(route('admin.settings.index'));

        $this->assertTrue(app(IntegrationSettings::class)->cpanel_enabled);
        $this->assertDatabaseHas('settings_properties', [
            'group' => 'integration',
            'name' => 'cpanel_enabled',
            'payload' => 'true',
        ]);
    }

    public function test_hosting_auto_provision_bool_roundtrip(): void
    {
        $this->actingAsSettingsAdmin();

        $this->post(route('admin.settings.update'), [
            'settings' => ['hosting_auto_provision' => 'no'],
        ])->assertRedirect(route('admin.settings.index'));
        $this->assertFalse(
            app(HostingSettings::class)->hosting_auto_provision,
            "'no' must persist as boolean false."
        );

        $this->post(route('admin.settings.update'), [
            'settings' => ['hosting_auto_provision' => 'yes'],
        ])->assertRedirect(route('admin.settings.index'));
        $this->assertTrue(app(HostingSettings::class)->hosting_auto_provision);
    }

    public function test_zero_and_one_forms_also_coerce_correctly(): void
    {
        $this->actingAsSettingsAdmin();

        $this->post(route('admin.settings.update'), [
            'settings' => ['plesk_enabled' => '0'],
        ])->assertRedirect(route('admin.settings.index'));
        $this->assertFalse(app(IntegrationSettings::class)->plesk_enabled, "'0' must persist as false.");

        $this->post(route('admin.settings.update'), [
            'settings' => ['plesk_enabled' => '1'],
        ])->assertRedirect(route('admin.settings.index'));
        $this->assertTrue(app(IntegrationSettings::class)->plesk_enabled, "'1' must persist as true.");
    }

    public function test_get_renders_saved_bool_selection_in_yes_no_select(): void
    {
        $this->actingAsSettingsAdmin();

        $this->post(route('admin.settings.update'), [
            'settings' => ['cpanel_enabled' => 'yes'],
        ])->assertRedirect(route('admin.settings.index'));

        // loadAll must normalize bool-typed keys to 'yes'/'no' — (string) true
        // is '1', which matches no select option and rendered "No" while the
        // stored value was true.
        $html = $this->get(route('admin.settings.index', ['tab' => 'integration']))->getContent();

        $this->assertMatchesRegularExpression(
            '/name="settings\[cpanel_enabled\]".*?<option value="yes"\s+selected/s',
            $html,
            'Yes option must be selected after saving cpanel_enabled=yes.'
        );
    }

    public function test_blank_legacy_field_keeps_old_value(): void
    {
        $this->actingAsSettingsAdmin();

        // Seed a legacy untyped value through the form.
        $this->post(route('admin.settings.update'), [
            'settings' => ['quote_prefix' => 'X'],
        ])->assertRedirect(route('admin.settings.index'));
        $this->assertSame('X', DB::table('settings')->where('setting_key', 'quote_prefix')->value('setting_value'));

        // Blank submit arrives as null (ConvertEmptyStringsToNull) and must
        // KEEP the old value — never write NULL over it (Option A, pinned).
        $this->post(route('admin.settings.update'), [
            'settings' => ['quote_prefix' => ''],
        ])->assertRedirect(route('admin.settings.index'));

        $this->assertSame(
            'X',
            DB::table('settings')->where('setting_key', 'quote_prefix')->value('setting_value'),
            'Blank legacy field must keep the stored value, not be wiped to NULL.'
        );
    }

    private function actingAsSettingsAdmin(): User
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        foreach (['settings.view', 'settings.manage'] as $permName) {
            $perm = Permission::firstOrCreate(['name' => $permName], ['label' => ucfirst($permName)]);
            $adminRole->permissions()->syncWithoutDetaching($perm->id);
        }

        $user->assignRole('admin');
        $this->actingAs($user);

        return $user;
    }
}
