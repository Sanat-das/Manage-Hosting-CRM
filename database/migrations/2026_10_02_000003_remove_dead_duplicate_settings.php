<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the dead duplicate settings left behind by the T4.2 settings port.
 *
 * Each typed property below is displayed on the settings page but read by no
 * production code — the live behaviour is owned by a legacy key (tax_rate,
 * currency, notify_*) or by a non-settings column, so the typed copy is dead
 * weight offering a second control for one behaviour.
 *
 * The seven legacy keys are the mirror image: untyped `settings` rows that no
 * longer back a declared property. Removing both halves stops the settings
 * page from presenting duplicates.
 *
 * LIVE keys (tax_rate, renewal_invoice_days, currency, timezone, state_code,
 * hosting_welcome_email_enabled, notify_overdue_invoices, notify_domain_expiry,
 * company_state, company_gstin, company_name, company_email, company_phone,
 * company_address) are deliberately untouched.
 */
return new class extends Migration
{
    /**
     * settings_properties group => dead typed property names.
     *
     * Hardcoded rather than derived from the settings classes: a migration must
     * keep doing the same thing when those classes change later.
     */
    private const DEAD_PROPERTIES = [
        'user' => [
            'user_default_timezone',
            'user_session_timeout_minutes',
            'user_two_factor_enforced',
            'user_max_login_attempts',
        ],
        'automation' => [
            'automation_welcome_email',
            'automation_overdue_actions',
            'automation_suspend_after_due_days',
            'automation_terminate_after_due_days',
            'automation_domain_expiry_notices',
            'automation_domain_expiry_reminder_days',
            'automation_renewal_invoices',
        ],
        'hosting' => [
            'hosting_suspend_after_days',
            'hosting_terminate_after_days',
        ],
        'domain' => [
            'domain_renewal_reminder_days',
        ],
        'product' => [
            'product_gst_applicable',
        ],
    ];

    /**
     * Legacy `settings` key => its group, for the dead untyped duplicates.
     *
     * @var array<string, string>
     */
    private const DEAD_LEGACY_KEYS = [
        'default_currency' => 'general',
        'default_tax_rate' => 'general',
        'session_timeout' => 'security',
        'max_login_attempts' => 'security',
        'force_2fa' => 'security',
        'domain_expiry_warning_days' => 'notification',
        'gst_enabled' => 'billing',
    ];

    public function up(): void
    {
        $table = config('settings.repositories.database.table', 'settings_properties');

        if (Schema::hasTable($table)) {
            foreach (self::DEAD_PROPERTIES as $group => $names) {
                DB::table($table)
                    ->where('group', $group)
                    ->whereIn('name', $names)
                    ->delete();
            }
        }

        if (Schema::hasTable('settings')) {
            DB::table('settings')
                ->whereIn('setting_key', array_keys(self::DEAD_LEGACY_KEYS))
                ->delete();
        }
    }

    public function down(): void
    {
        $table = config('settings.repositories.database.table', 'settings_properties');
        $now = now();

        if (Schema::hasTable($table)) {
            foreach (self::DEAD_PROPERTIES as $group => $names) {
                foreach ($names as $name) {
                    DB::table($table)->insert([
                        'group' => $group,
                        'name' => $name,
                        'payload' => 'null',
                        'locked' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }

        if (Schema::hasTable('settings')) {
            foreach (self::DEAD_LEGACY_KEYS as $key => $group) {
                DB::table('settings')->insert([
                    'setting_key' => $key,
                    'setting_value' => '',
                    'group' => $group,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
};
