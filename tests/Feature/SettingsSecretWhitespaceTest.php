<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Settings\EmailSettings;
use App\Settings\IntegrationSettings;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Secrets on the settings form must round-trip byte-exact through the HTTP
 * middleware stack, while a blank submit still keeps the stored value.
 *
 * TrimStrings used to strip leading/trailing spaces from settings[smtp_password]
 * (nested keys never matched its top-level exceptions), so a mailbox password
 * with a real space could never be stored. The trim exception in
 * bootstrap/app.php fixes that; the whitespace-aware blank guard in
 * SettingsController::update() keeps "leave blank to keep current" working
 * now that '   ' arrives untrimmed.
 */
class SettingsSecretWhitespaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->forgetScopedInstances();

        $ref = new \ReflectionClass(AppSettings::class);
        $prop = $ref->getProperty('cache');
        $prop->setValue(null, null);
    }

    public function test_whitespace_padded_smtp_password_survives_save(): void
    {
        $this->actingAsSettingsAdmin();

        $this->post(route('admin.settings.update'), [
            'settings' => ['smtp_password' => '  spaced-secret  '],
        ])->assertRedirect(route('admin.settings.index'))
            ->assertSessionHasNoErrors();

        app()->forgetScopedInstances();

        $this->assertSame(
            '  spaced-secret  ',
            app(EmailSettings::class)->smtp_password,
            'A secret with leading/trailing spaces must be stored verbatim, not trimmed.'
        );
    }

    public function test_whitespace_only_smtp_password_keeps_the_stored_one(): void
    {
        $settings = app(EmailSettings::class);
        $settings->fill(['smtp_password' => 'OriginalSecret1']);
        $settings->save();
        app()->forgetScopedInstances();

        $this->actingAsSettingsAdmin();

        $this->post(route('admin.settings.update'), [
            'settings' => ['smtp_password' => '   '],
        ])->assertRedirect(route('admin.settings.index'))
            ->assertSessionHasNoErrors();

        app()->forgetScopedInstances();

        $this->assertSame(
            'OriginalSecret1',
            app(EmailSettings::class)->smtp_password,
            'A whitespace-only submit means "keep current" and must not overwrite the stored secret.'
        );
    }

    public function test_whitespace_padded_cpanel_api_token_survives_save(): void
    {
        $this->actingAsSettingsAdmin();

        $this->post(route('admin.settings.update'), [
            'settings' => ['cpanel_api_token' => '  token-with-space  '],
        ])->assertRedirect(route('admin.settings.index'))
            ->assertSessionHasNoErrors();

        app()->forgetScopedInstances();

        $this->assertSame(
            '  token-with-space  ',
            app(IntegrationSettings::class)->cpanel_api_token,
            'A secret with leading/trailing spaces must be stored verbatim, not trimmed.'
        );
    }

    public function test_nbsp_only_smtp_password_keeps_the_stored_one(): void
    {
        $settings = app(EmailSettings::class);
        $settings->fill(['smtp_password' => 'OriginalSecret1']);
        $settings->save();
        app()->forgetScopedInstances();

        $this->actingAsSettingsAdmin();

        $this->post(route('admin.settings.update'), [
            'settings' => ['smtp_password' => "\xc2\xa0"],
        ])->assertRedirect(route('admin.settings.index'))
            ->assertSessionHasNoErrors();

        app()->forgetScopedInstances();

        $this->assertSame(
            'OriginalSecret1',
            app(EmailSettings::class)->smtp_password,
            'An NBSP-only submit means "keep current" and must not overwrite the stored secret.'
        );
    }

    public function test_nbsp_padded_smtp_password_is_stored_verbatim(): void
    {
        $this->actingAsSettingsAdmin();

        $this->post(route('admin.settings.update'), [
            'settings' => ['smtp_password' => "\xc2\xa0abc\xc2\xa0"],
        ])->assertRedirect(route('admin.settings.index'))
            ->assertSessionHasNoErrors();

        app()->forgetScopedInstances();

        $this->assertSame(
            "\xc2\xa0abc\xc2\xa0",
            app(EmailSettings::class)->smtp_password,
            'A secret padded with NBSP contains a real value and must be stored verbatim, not treated as blank.'
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
