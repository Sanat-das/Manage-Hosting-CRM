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
 * Baseline inventory guard for admin/settings.
 *
 * - Captures the 193 name="settings[*]" keys rendered by
 *   resources/views/admin/settings/index.blade.php (84 baseline + 94 task-8 typed
 *   surfaced + 11 imap_* for ticket email piping + 4 security hardening toggles)
 *   and asserts the set is unchanged after refactors (no drop/rename).
 * - Guards GET query count: 1 legacy settings pluck + 16 typed group loads = <=17.
 *   Ensures SettingsController::loadAll() does not introduce N+1 per-key queries.
 */
class AdminSettingsInventoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Baseline set - 193 keys rendered by admin/settings/index.blade.php (84 + 94 typed + 11 imap + 4 security hardening).
     * Documented verbatim so any drop or rename fails this test.
     * Sorted alphabetically for diff stability; source order is the blade file.
     * v2: 2026-08-24 Task 8 surfaced remaining 94 TYPED_KEYS (all 160 typed + 18 legacy).
     * v3: 2026-08-28 added the 11 imap_* keys (Incoming Mail card, ticket email piping + inbound policy).
     * v4: 2026-09-03 added 4 security hardening toggles (honeypot, headers, strong password, math captcha).
     * Legacy default_currency kept disabled (deprecated alias for typed currency in Billing) - no duplicate editable.
     */
    public const BASELINE_KEYS = [
        'analytics_anonymize_ip',
        'analytics_daily_report',
        'analytics_dashboard_widgets',
        'analytics_enabled',
        'analytics_event_tracking',
        'analytics_export_enabled',
        'analytics_privacy_consent',
        'analytics_report_email',
        'analytics_retention_days',
        'analytics_track_admin',
        'analytics_tracking_code',
        'analytics_weekly_report',
        'auto_generate_invoice',
        'automation_auto_close_ticket_days',
        'automation_auto_close_tickets',
        'automation_default_workflow',
        'automation_domain_expiry_notices',
        'automation_domain_expiry_reminder_days',
        'automation_invoice_reminder_days',
        'automation_invoice_reminders',
        'automation_overdue_actions',
        'automation_renewal_invoices',
        'automation_suspend_after_due_days',
        'automation_terminate_after_due_days',
        'automation_welcome_email',
        'automation_workflows_enabled',
        'catalog_allow_preorders',
        'catalog_bundle_discount_default',
        'catalog_currency_symbol',
        'catalog_default_sort',
        'catalog_display_prices_with_tax',
        'catalog_featured_product_ids',
        'catalog_hide_addons',
        'catalog_price_precision',
        'catalog_products_per_page',
        'catalog_require_domain_for_hosting',
        'catalog_show_inactive',
        'catalog_show_out_of_stock',
        'catalog_show_reviews',
        'company_address',
        'company_email',
        'company_name',
        'company_phone',
        'cpanel_api_token',
        'cpanel_enabled',
        'cpanel_host',
        'cpanel_port',
        'cron_backup_check',
        'cron_domain_expiry_check',
        'cron_heartbeat_enabled',
        'cron_lock_timeout_minutes',
        'cron_log_cleanup_days',
        'cron_max_runtime_minutes',
        'cron_notify_email',
        'cron_notify_on_failure',
        'cron_overdue_invoice_check',
        'cron_pricing_sync',
        'cron_report_generation',
        'cron_scheduler_enabled',
        'cron_usage_sync',
        'currency',
        'date_format',
        'default_currency',
        'default_tax_rate',
        'domain_auto_registration',
        'domain_default_registrar',
        'domain_dns_enabled',
        'domain_dns_provider',
        'domain_expiry_warning_days',
        'domain_nameserver1',
        'domain_nameserver2',
        'domain_nameserver3',
        'domain_nameserver4',
        'domain_pricing_tier',
        'domain_renewal_reminder_days',
        'domain_transfer_enabled',
        'domain_transfer_lock',
        'domain_transfer_lock_days',
        'domain_whois_privacy',
        'due_days',
        'force_2fa',
        'gst_enabled',
        'hosting_allow_account_creation',
        'hosting_auto_provision',
        'hosting_backup_enabled',
        'hosting_default_panel',
        'hosting_default_server_group',
        'hosting_documentation_url',
        'hosting_max_accounts_per_server',
        'hosting_provision_retries',
        'hosting_suspend_after_days',
        'hosting_suspend_on_overdue',
        'hosting_terminate_after_days',
        'hosting_terms_url',
        'hosting_unsuspend_on_payment',
        'hosting_welcome_email_enabled',
        'imap_auto_create_customers',
        'imap_default_department',
        'imap_delete_after_fetch',
        'imap_enabled',
        'imap_encryption',
        'imap_folder',
        'imap_host',
        'imap_password',
        'imap_port',
        'imap_username',
        'imap_validate_cert',
        'inventory_auto_restock',
        'inventory_low_stock_threshold',
        'inventory_notify_low_stock',
        'inventory_restock_min_quantity',
        'inventory_stock_unit',
        'inventory_track_stock',
        'invoice_next_number',
        'invoice_prefix',
        'ipam_allow_public_ipv6',
        'ipam_audit_retention_days',
        'ipam_auto_allocate',
        'ipam_auto_release_unused',
        'ipam_default_ipv4_gateway',
        'ipam_default_ipv6_prefix',
        'ipam_dns_reverse_zone',
        'ipam_enabled',
        'ipam_low_capacity_warning_percent',
        'ipam_reservation_hold_days',
        'ipam_scan_interval_minutes',
        'ipam_unused_release_days',
        'ipam_validate_networks',
        'ipam_vlan_tracking',
        'lockout_duration',
        'mail_from_address',
        'mail_from_name',
        'max_login_attempts',
        'notify_domain_expiry',
        'notify_new_tickets',
        'notify_overdue_invoices',
        'password_min_length',
        'plesk_enabled',
        'plesk_host',
        'plesk_password',
        'plesk_port',
        'plesk_username',
        'product_allow_custom_pricing',
        'product_approval_required',
        'product_catalog_sync_enabled',
        'product_default_billing_cycle',
        'product_enable_downgrades',
        'product_enable_upgrades',
        'product_gst_applicable',
        'product_license_key_prefix',
        'product_prorated_charges',
        'product_require_domain',
        'product_reseller_markup_percent',
        'product_show_in_order_form',
        'product_sku_prefix',
        'product_trial_days',
        'product_trial_enabled',
        'product_version_management',
        'quote_prefix',
        'registration_enabled',
        'resellerclub_api_id',
        'resellerclub_api_key',
        'resellerclub_enabled',
        'resellerclub_username',
        'role_allow_assignment',
        'role_default_role',
        'role_guard',
        'role_protect_system_roles',
        'role_show_permissions',
        'security_headers_enabled',
        'security_honeypot_enabled',
        'security_math_captcha_enabled',
        'security_strong_password_enabled',
        'session_timeout',
        'smtp_encryption',
        'smtp_host',
        'smtp_password',
        'smtp_port',
        'smtp_username',
        'tax_rate',
        'ticket_next_number',
        'ticket_prefix',
        'timezone',
        'user_allow_self_delete',
        'user_allow_social_login',
        'user_default_timezone',
        'user_email_verification',
        'user_inactive_lock_days',
        'user_max_login_attempts',
        'user_password_expiry_days',
        'user_profile_editable',
        'user_session_timeout_minutes',
        'user_two_factor_enforced',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Spatie settings are container-scoped singletons; flush so each test
        // resolves a fresh instance for its own DB (same as NewSettingsGroupsTest).
        app()->forgetScopedInstances();

        $ref = new \ReflectionClass(AppSettings::class);
        $prop = $ref->getProperty('cache');
        $prop->setValue(null, null);
    }

    public function test_baseline_field_inventory_is_unchanged(): void
    {
        $response = $this->actingAsSettingsAdmin()
            ->get(route('admin.settings.index'));

        $response->assertStatus(200);

        $html = $response->getContent();
        preg_match_all('/name="settings\[([^\]]+)\]"/', $html, $matches);
        $keys = array_unique($matches[1] ?? []);
        sort($keys);

        $expected = self::BASELINE_KEYS;
        sort($expected);

        $this->assertCount(193, $keys, 'Baseline field count changed - expected 193 name="settings[*]" keys. Got: ' . implode(', ', $keys));
        $this->assertSame($expected, $keys, 'Baseline field set changed - keys were dropped, renamed, or added.');
    }

    public function test_get_query_count_is_bounded_and_has_no_n_plus_one(): void
    {
        // 1 legacy settings pluck in middleware (security hardening toggles) + 1 legacy pluck in
        // SettingsController::loadAll() + 16 typed group loads (distinct classes in AppSettings::TYPED_KEYS) = 18.
        // Guard against N+1 per-key queries (would be ~160+ queries if each TYPED_KEYS entry hit DB).
        DB::enableQueryLog();

        $this->actingAsSettingsAdmin()
            ->get(route('admin.settings.index'))
            ->assertStatus(200);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Filter to only setting-related queries (settings + settings_properties).
        // Auth/permission lookups (users, roles, permissions) occur before the controller
        // but are not part of SettingsController::loadAll(); we bound the total separately
        // and the setting-specific slice.
        $settingQueries = array_filter($queries, function (array $entry): bool {
            $sql = $entry['query'] ?? '';
            return str_contains($sql, 'settings') || str_contains($sql, 'settings_properties');
        });

        $totalCount = count($queries);
        $settingCount = count($settingQueries);

        $this->assertLessThanOrEqual(
            18,
            $settingCount,
            "GET admin.settings.index issued {$settingCount} setting queries (expected <=18 = 2 plucks + 16 typed groups). "
            . "Total queries: {$totalCount}. Possible N+1. Queries: " . json_encode(array_column($settingQueries, 'query'))
        );

        // Hard N+1 check: 160 TYPED_KEYS should never produce 160 queries.
        $this->assertLessThan(
            50,
            $settingCount,
            "N+1 detected: {$settingCount} setting queries far exceeds 17 group loads for 160 TYPED_KEYS."
        );
    }

    /**
     * A JSON-null payload in settings_properties (e.g. literal `null` for the
     * non-nullable string cpanel_host) makes Spatie hydration throw
     * "Cannot assign null to property ... of type string" during
     * app(IntegrationSettings::class). The page must degrade gracefully:
     * 200 + every field rendered, poisoned group falling back to defaults.
     */
    public function test_corrupted_typed_payload_degrades_to_defaults_instead_of_500(): void
    {
        // Poison the integration group - updateOrInsert respects the
        // unique(group, name) constraint whether or not the seeder seeded it.
        DB::table('settings_properties')->updateOrInsert(
            ['group' => 'integration', 'name' => 'cpanel_host'],
            ['payload' => 'null', 'locked' => false, 'created_at' => now(), 'updated_at' => now()],
        );

        // Flush scoped singletons so the controller re-hydrates from the poisoned row.
        app()->forgetScopedInstances();

        $response = $this->actingAsSettingsAdmin()
            ->get(route('admin.settings.index'));

        $response->assertStatus(200);

        $html = $response->getContent();

        // Every field still renders - poisoned group falls back to defaults,
        // other groups unaffected.
        preg_match_all('/name="settings\[([^\]]+)\]"/', $html, $matches);
        $keys = array_unique($matches[1] ?? []);
        sort($keys);
        $expected = self::BASELINE_KEYS;
        sort($expected);
        $this->assertSame($expected, $keys, 'Poisoned row dropped fields from the rendered page.');

        // cpanel_host renders with its class default '' (empty value attribute).
        preg_match('/<input\b[^>]*name="settings\[cpanel_host\]"[^>]*>/is', $html, $inputTag);
        $this->assertNotEmpty($inputTag, 'cpanel_host input missing from rendered page.');
        $this->assertStringContainsString(
            'value=""',
            $inputTag[0],
            'cpanel_host should fall back to its empty default.'
        );
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
