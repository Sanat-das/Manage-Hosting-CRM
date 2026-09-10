<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the three ways the settings page used to fail a save with no visible
 * sign that anything had gone wrong.
 *
 * Deliberately behavioural: these assert the contract (the form is novalidate,
 * the GST fields belong to the other form, Enter posts save_all), not the shape
 * of the javascript that implements it.
 */
class SettingsSilentSaveFailuresTest extends TestCase
{
    use RefreshDatabase;

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

    /**
     * Inactive tab-panes are display:none. A control that fails an HTML5
     * constraint on a tab the admin is not looking at cannot be focused, so the
     * browser aborts the submit and reports nothing — "Save All did nothing".
     * The form must therefore be novalidate, with validation run by hand after
     * switching to the offending tab.
     */
    public function test_the_settings_form_is_novalidate(): void
    {
        $html = $this->actingAsSettingsAdmin()
            ->get(route('admin.settings.index'))
            ->assertStatus(200)
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<form[^>]*id="settings-form"[^>]*\snovalidate[^>]*>/i',
            $html,
            'settings-form must stay novalidate — native validation cannot report on a hidden tab.'
        );
    }

    /**
     * Enter in any text field submits without the Save button's value, which
     * used to leave the controller applying its per-tab filter and silently
     * discarding every other tab's edits. A hidden save_all makes Enter behave
     * like the one visible Save button.
     */
    public function test_enter_key_submit_does_not_drop_other_tabs(): void
    {
        $html = $this->actingAsSettingsAdmin()
            ->get(route('admin.settings.index'))
            ->assertStatus(200)
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<input[^>]*type="hidden"[^>]*name="save_all"[^>]*value="1"[^>]*>/i',
            $html,
            'The hidden save_all input is what stops an Enter-key submit dropping other tabs.'
        );

        // Exactly what the browser now posts when Enter is pressed on General.
        $this->actingAsSettingsAdmin()
            ->post(route('admin.settings.update'), [
                'active_tab' => 'general',
                'save_all' => '1',
                'settings' => [
                    'company_name' => 'Enter Key Co',
                    'quote_prefix' => 'ENTER-',
                ],
            ])
            ->assertRedirect();

        $this->assertSame('Enter Key Co', AppSettings::get('company_name'));
        $this->assertSame(
            'ENTER-',
            DB::table('settings')->where('setting_key', 'quote_prefix')->value('setting_value'),
            'A key from another tab was dropped — Enter-key submits are lossy again.'
        );
    }

    /**
     * Without save_all the per-tab filter still applies. This is the behaviour
     * the hidden field exists to opt out of, and scoped payloads (API, tests)
     * still rely on it.
     */
    public function test_a_scoped_post_without_save_all_still_filters_by_tab(): void
    {
        $this->actingAsSettingsAdmin()
            ->post(route('admin.settings.update'), [
                'active_tab' => 'general',
                'settings' => [
                    'company_name' => 'Scoped Co',
                    'quote_prefix' => 'SCOPED-',
                ],
            ])
            ->assertRedirect();

        $this->assertSame('Scoped Co', AppSettings::get('company_name'));
        $this->assertNotSame(
            'SCOPED-',
            DB::table('settings')->where('setting_key', 'quote_prefix')->value('setting_value'),
            'Tab scoping should still drop out-of-tab keys when save_all is absent.'
        );
    }

    /**
     * The GST card posts to GstSettingController through the form= attribute, so
     * FormData(#settings-form) cannot see it and "Save All Settings" does not
     * save it. That is intended — GstSettingController is the single writer —
     * but it means the page owes the admin a warning, which is what
     * #gst-dirty-hint and the dirty tracker provide.
     */
    public function test_gst_fields_belong_to_their_own_form_and_advertise_it(): void
    {
        $html = $this->actingAsSettingsAdmin()
            ->get(route('admin.settings.index', ['tab' => 'billing']))
            ->assertStatus(200)
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="gstin"[^>]*form="gst-settings-form"|<input[^>]*form="gst-settings-form"[^>]*name="gstin"/i',
            $html,
            'gstin must stay owned by gst-settings-form.'
        );

        $this->assertStringContainsString(
            'id="gst-dirty-hint"',
            $html,
            'The GST card must warn that Save All does not save it.'
        );
    }
}
