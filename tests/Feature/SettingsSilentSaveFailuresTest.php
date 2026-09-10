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
     * Save All now posts only the tabs that were edited (clean panes are
     * disabled on submit, and disabled controls are not serialized). That is
     * only safe because the controller writes exactly the keys it receives —
     * an omitted key must keep its stored value, not be blanked.
     */
    public function test_a_pane_sized_payload_leaves_the_other_tabs_untouched(): void
    {
        $this->actingAsSettingsAdmin()
            ->post(route('admin.settings.update'), [
                'save_all' => '1',
                'settings' => [
                    'company_name' => 'Original Co',
                    'hosting_provision_retries' => '7',
                    'quote_prefix' => 'ORIG-',
                ],
            ])
            ->assertRedirect();

        // What the browser posts after editing only the Billing tab.
        $this->actingAsSettingsAdmin()
            ->post(route('admin.settings.update'), [
                'active_tab' => 'billing',
                'save_all' => '1',
                'settings' => ['quote_prefix' => 'NEW-'],
            ])
            ->assertRedirect();

        $this->assertSame(
            'NEW-',
            DB::table('settings')->where('setting_key', 'quote_prefix')->value('setting_value')
        );
        $this->assertSame('Original Co', AppSettings::get('company_name'), 'An untouched tab was overwritten.');
        $this->assertSame('7', AppSettings::get('hosting_provision_retries'), 'An untouched tab was overwritten.');
    }

    /**
     * Why the shrink is pane-sized and not field-sized: the controller compiles
     * company_address from the six sundered fields present in the payload. Post
     * one of them on its own and the address is rebuilt from just that one, so
     * these fields have to travel together.
     */
    public function test_the_address_fields_must_travel_together(): void
    {
        $this->actingAsSettingsAdmin()
            ->post(route('admin.settings.update'), [
                'active_tab' => 'general',
                'save_all' => '1',
                'settings' => [
                    'company_address_line1' => '12 Park Street',
                    'company_city' => 'Kolkata',
                    'company_state' => 'West Bengal',
                    'company_postcode' => '700016',
                    'company_country' => 'India',
                ],
            ])
            ->assertRedirect();

        $compiled = AppSettings::get('company_address');

        foreach (['12 Park Street', 'Kolkata', 'West Bengal', '700016', 'India'] as $part) {
            $this->assertStringContainsString($part, (string) $compiled);
        }
    }

    /**
     * The free-text boxes that became selects must keep a stored value that is
     * not in the option list.
     *
     * Saves are last-write-wins across a whole tab, so a select that fell back
     * to its first option would rewrite the stored value the moment anyone
     * saved that tab — a silent data change from merely opening the page. The
     * value is kept and labelled instead, which also surfaces the underlying
     * problem: role_default_role is 'client', and no such role exists.
     */
    public function test_a_select_never_silently_drops_an_unrecognised_stored_value(): void
    {
        DB::table('settings')->updateOrInsert(
            ['setting_key' => 'role_default_role'],
            ['setting_value' => 'client', 'group' => 'role', 'updated_at' => now()],
        );
        app(\App\Settings\RoleSettings::class)->fill(['role_default_role' => 'client'])->save();
        app()->forgetScopedInstances();

        $html = $this->actingAsSettingsAdmin()
            ->get(route('admin.settings.index', ['tab' => 'role']))
            ->assertStatus(200)
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<option value="client" selected>client\s*—\s*not a recognised value<\/option>/u',
            $html,
            'An unrecognised stored value must stay selected, not be replaced by the first option.'
        );

        // The real options are there too — a dropdown with only the bad value
        // would mean the option source silently failed (the roles table is
        // adminlte_roles, and querying `roles` returns nothing but does not throw
        // past the guard).
        foreach (['admin', 'support', 'sales'] as $role) {
            $this->assertStringContainsString('<option value="'.$role.'"', $html, "Role {$role} missing from the dropdown.");
        }
    }

    /**
     * Fields with a known set of values should not be free text. A typo in a
     * cron schedule box was a silent no-op, and "cpanel" typed into a control
     * panel box was indistinguishable from "cpnael".
     */
    public function test_known_value_sets_render_as_selects(): void
    {
        $html = $this->actingAsSettingsAdmin()
            ->get(route('admin.settings.index', ['tab' => 'cron']))
            ->assertStatus(200)
            ->getContent();

        foreach ([
            'cron_domain_expiry_check', 'cron_overdue_invoice_check', 'cron_backup_check',
            'cron_usage_sync', 'cron_pricing_sync', 'cron_report_generation',
            'role_guard', 'product_default_billing_cycle', 'date_format',
            'hosting_default_panel', 'hosting_default_server_group',
            'domain_default_registrar', 'domain_pricing_tier', 'inventory_stock_unit',
        ] as $key) {
            $this->assertMatchesRegularExpression(
                '/<select[^>]*name="settings\['.preg_quote($key, '/').'\]"/',
                $html,
                "{$key} should be a select, not a free-text box."
            );
        }

        // …and the URL fields carry a URL type rather than plain text.
        foreach (['hosting_documentation_url', 'hosting_terms_url'] as $key) {
            $this->assertMatchesRegularExpression(
                '/<input[^>]*type="url"[^>]*name="settings\['.preg_quote($key, '/').'\]"|<input[^>]*name="settings\['.preg_quote($key, '/').'\]"[^>]*type="url"/',
                $html,
                "{$key} should be type=url."
            );
        }
    }

    /**
     * The control panel list is the modules that can actually provision, read
     * from each module's stored manifest. Offering ssh-console or snmp-monitor
     * as a "control panel" is how an admin ends up with a default that can
     * never create an account.
     */
    public function test_the_control_panel_list_holds_only_provisioning_modules(): void
    {
        \App\Models\Module::query()->delete();
        foreach ([
            ['slug' => 'cpanel', 'caps' => ['provisioning']],
            ['slug' => 'virtualizor', 'caps' => ['provisioning']],
            ['slug' => 'snmp-monitor', 'caps' => ['hosting-account-info']],
            ['slug' => 'ssh-console', 'caps' => []],
        ] as $row) {
            \App\Models\Module::query()->create([
                'slug' => $row['slug'],
                'name' => ucfirst($row['slug']),
                'version' => '1.0.0',
                'status' => 'active',
                'provider' => 'Modules\\'.ucfirst($row['slug']).'\\Provider',
                // An array, because Module::$casts stores it that way. Passing a
                // JSON string here double-encodes it and the test then exercises
                // a shape production never has.
                'manifest' => ['capabilities' => $row['caps']],
            ]);
        }

        $html = $this->actingAsSettingsAdmin()
            ->get(route('admin.settings.index', ['tab' => 'hosting']))
            ->assertStatus(200)
            ->getContent();

        preg_match('/<select[^>]*name="settings\[hosting_default_panel\]".*?<\/select>/s', $html, $m);
        $this->assertNotEmpty($m, 'hosting_default_panel select missing.');

        $this->assertStringContainsString('value="cpanel"', $m[0]);
        $this->assertStringContainsString('value="virtualizor"', $m[0]);
        $this->assertStringNotContainsString('value="snmp-monitor"', $m[0], 'A monitoring module is not a control panel.');
        $this->assertStringNotContainsString('value="ssh-console"', $m[0], 'A console module is not a control panel.');
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
