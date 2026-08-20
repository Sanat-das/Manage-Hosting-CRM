<?php

namespace App\Support;

use App\Settings\AnalyticsSettings;
use App\Settings\AutomationSettings;
use App\Settings\BillingSettings;
use App\Settings\CatalogSettings;
use App\Settings\CronSettings;
use App\Settings\DomainSettings;
use App\Settings\EmailSettings;
use App\Settings\GeneralSettings;
use App\Settings\HostingSettings;
use App\Settings\IntegrationSettings;
use App\Settings\InventorySettings;
use App\Settings\IpamSettings;
use App\Settings\ProductSettings;
use App\Settings\RoleSettings;
use App\Settings\SupportSettings;
use App\Settings\UserSettings;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\Settings;

/**
 * Back-compat read helper for settings.
 *
 * Typed keys (the legacy `settings` keys that now live in spatie typed
 * classes PLUS the T4.2 ported groups) are delegated to their typed class,
 * which is request-cached by the container. Untyped keys fall back to the
 * legacy `settings` table, cached for the duration of a single request.
 */
class AppSettings
{
    /**
     * Typed settings key => settings class that owns it.
     *
     * @var array<string, class-string<Settings>>
     */
    public const TYPED_KEYS = [
        // general
        'company_name' => GeneralSettings::class,
        'company_email' => GeneralSettings::class,
        'company_phone' => GeneralSettings::class,
        'company_address' => GeneralSettings::class,
        'date_format' => GeneralSettings::class,
        'timezone' => GeneralSettings::class,
        // billing
        'currency' => BillingSettings::class,
        'invoice_next_number' => BillingSettings::class,
        'invoice_prefix' => BillingSettings::class,
        'tax_rate' => BillingSettings::class,
        // email
        'smtp_host' => EmailSettings::class,
        'smtp_port' => EmailSettings::class,
        'smtp_username' => EmailSettings::class,
        'smtp_password' => EmailSettings::class,
        'smtp_encryption' => EmailSettings::class,
        // support
        'ticket_next_number' => SupportSettings::class,
        'ticket_prefix' => SupportSettings::class,
        // domain (T4.2)
        'domain_default_registrar' => DomainSettings::class,
        'domain_auto_registration' => DomainSettings::class,
        'domain_transfer_enabled' => DomainSettings::class,
        'domain_transfer_lock' => DomainSettings::class,
        'domain_transfer_lock_days' => DomainSettings::class,
        'domain_nameserver1' => DomainSettings::class,
        'domain_nameserver2' => DomainSettings::class,
        'domain_nameserver3' => DomainSettings::class,
        'domain_nameserver4' => DomainSettings::class,
        'domain_dns_enabled' => DomainSettings::class,
        'domain_dns_provider' => DomainSettings::class,
        'domain_whois_privacy' => DomainSettings::class,
        'domain_pricing_tier' => DomainSettings::class,
        'domain_renewal_reminder_days' => DomainSettings::class,
        // integration (T4.2)
        'cpanel_enabled' => IntegrationSettings::class,
        'cpanel_host' => IntegrationSettings::class,
        'cpanel_port' => IntegrationSettings::class,
        'cpanel_api_token' => IntegrationSettings::class,
        'plesk_enabled' => IntegrationSettings::class,
        'plesk_host' => IntegrationSettings::class,
        'plesk_port' => IntegrationSettings::class,
        'plesk_username' => IntegrationSettings::class,
        'plesk_password' => IntegrationSettings::class,
        'resellerclub_enabled' => IntegrationSettings::class,
        'resellerclub_api_id' => IntegrationSettings::class,
        'resellerclub_api_key' => IntegrationSettings::class,
        'resellerclub_username' => IntegrationSettings::class,
        // hosting (T4.2)
        'hosting_default_panel' => HostingSettings::class,
        'hosting_default_server_group' => HostingSettings::class,
        'hosting_auto_provision' => HostingSettings::class,
        'hosting_provision_retries' => HostingSettings::class,
        'hosting_suspend_on_overdue' => HostingSettings::class,
        'hosting_suspend_after_days' => HostingSettings::class,
        'hosting_terminate_after_days' => HostingSettings::class,
        'hosting_unsuspend_on_payment' => HostingSettings::class,
        'hosting_allow_account_creation' => HostingSettings::class,
        'hosting_max_accounts_per_server' => HostingSettings::class,
        'hosting_documentation_url' => HostingSettings::class,
        'hosting_terms_url' => HostingSettings::class,
        'hosting_welcome_email_enabled' => HostingSettings::class,
        'hosting_backup_enabled' => HostingSettings::class,
        // ipam (T4.2)
        'ipam_enabled' => IpamSettings::class,
        'ipam_auto_allocate' => IpamSettings::class,
        'ipam_default_ipv4_gateway' => IpamSettings::class,
        'ipam_default_ipv6_prefix' => IpamSettings::class,
        'ipam_allow_public_ipv6' => IpamSettings::class,
        'ipam_reservation_hold_days' => IpamSettings::class,
        'ipam_scan_interval_minutes' => IpamSettings::class,
        'ipam_dns_reverse_zone' => IpamSettings::class,
        'ipam_low_capacity_warning_percent' => IpamSettings::class,
        'ipam_auto_release_unused' => IpamSettings::class,
        'ipam_unused_release_days' => IpamSettings::class,
        'ipam_validate_networks' => IpamSettings::class,
        'ipam_vlan_tracking' => IpamSettings::class,
        'ipam_audit_retention_days' => IpamSettings::class,
        // inventory (T4.2)
        'inventory_track_stock' => InventorySettings::class,
        'inventory_low_stock_threshold' => InventorySettings::class,
        'inventory_auto_restock' => InventorySettings::class,
        'inventory_restock_min_quantity' => InventorySettings::class,
        'inventory_notify_low_stock' => InventorySettings::class,
        'inventory_stock_unit' => InventorySettings::class,
        // catalog (T4.2)
        'catalog_show_inactive' => CatalogSettings::class,
        'catalog_require_domain_for_hosting' => CatalogSettings::class,
        'catalog_display_prices_with_tax' => CatalogSettings::class,
        'catalog_show_out_of_stock' => CatalogSettings::class,
        'catalog_allow_preorders' => CatalogSettings::class,
        'catalog_default_sort' => CatalogSettings::class,
        'catalog_products_per_page' => CatalogSettings::class,
        'catalog_featured_product_ids' => CatalogSettings::class,
        'catalog_hide_addons' => CatalogSettings::class,
        'catalog_price_precision' => CatalogSettings::class,
        'catalog_currency_symbol' => CatalogSettings::class,
        'catalog_show_reviews' => CatalogSettings::class,
        'catalog_bundle_discount_default' => CatalogSettings::class,
        // product (T4.2)
        'product_sku_prefix' => ProductSettings::class,
        'product_require_domain' => ProductSettings::class,
        'product_enable_upgrades' => ProductSettings::class,
        'product_enable_downgrades' => ProductSettings::class,
        'product_allow_custom_pricing' => ProductSettings::class,
        'product_trial_enabled' => ProductSettings::class,
        'product_trial_days' => ProductSettings::class,
        'product_default_billing_cycle' => ProductSettings::class,
        'product_prorated_charges' => ProductSettings::class,
        'product_catalog_sync_enabled' => ProductSettings::class,
        'product_approval_required' => ProductSettings::class,
        'product_license_key_prefix' => ProductSettings::class,
        'product_show_in_order_form' => ProductSettings::class,
        'product_reseller_markup_percent' => ProductSettings::class,
        'product_gst_applicable' => ProductSettings::class,
        'product_version_management' => ProductSettings::class,
        // analytics (T4.2)
        'analytics_enabled' => AnalyticsSettings::class,
        'analytics_tracking_code' => AnalyticsSettings::class,
        'analytics_track_admin' => AnalyticsSettings::class,
        'analytics_retention_days' => AnalyticsSettings::class,
        'analytics_dashboard_widgets' => AnalyticsSettings::class,
        'analytics_export_enabled' => AnalyticsSettings::class,
        'analytics_anonymize_ip' => AnalyticsSettings::class,
        'analytics_event_tracking' => AnalyticsSettings::class,
        'analytics_privacy_consent' => AnalyticsSettings::class,
        'analytics_report_email' => AnalyticsSettings::class,
        'analytics_daily_report' => AnalyticsSettings::class,
        'analytics_weekly_report' => AnalyticsSettings::class,
        // automation (T4.2)
        'automation_workflows_enabled' => AutomationSettings::class,
        'automation_default_workflow' => AutomationSettings::class,
        'automation_auto_close_tickets' => AutomationSettings::class,
        'automation_auto_close_ticket_days' => AutomationSettings::class,
        'automation_welcome_email' => AutomationSettings::class,
        'automation_invoice_reminders' => AutomationSettings::class,
        'automation_invoice_reminder_days' => AutomationSettings::class,
        'automation_overdue_actions' => AutomationSettings::class,
        'automation_suspend_after_due_days' => AutomationSettings::class,
        'automation_terminate_after_due_days' => AutomationSettings::class,
        'automation_domain_expiry_notices' => AutomationSettings::class,
        'automation_domain_expiry_reminder_days' => AutomationSettings::class,
        'automation_renewal_invoices' => AutomationSettings::class,
        // cron (T4.2)
        'cron_scheduler_enabled' => CronSettings::class,
        'cron_heartbeat_enabled' => CronSettings::class,
        'cron_domain_expiry_check' => CronSettings::class,
        'cron_overdue_invoice_check' => CronSettings::class,
        'cron_backup_check' => CronSettings::class,
        'cron_usage_sync' => CronSettings::class,
        'cron_pricing_sync' => CronSettings::class,
        'cron_report_generation' => CronSettings::class,
        'cron_log_cleanup_days' => CronSettings::class,
        'cron_lock_timeout_minutes' => CronSettings::class,
        'cron_max_runtime_minutes' => CronSettings::class,
        'cron_notify_on_failure' => CronSettings::class,
        'cron_notify_email' => CronSettings::class,
        // role (T4.2)
        'role_default_role' => RoleSettings::class,
        'role_allow_assignment' => RoleSettings::class,
        'role_show_permissions' => RoleSettings::class,
        'role_guard' => RoleSettings::class,
        'role_protect_system_roles' => RoleSettings::class,
        // user (T4.2)
        'user_default_timezone' => UserSettings::class,
        'user_email_verification' => UserSettings::class,
        'user_allow_social_login' => UserSettings::class,
        'user_profile_editable' => UserSettings::class,
        'user_allow_self_delete' => UserSettings::class,
        'user_password_expiry_days' => UserSettings::class,
        'user_session_timeout_minutes' => UserSettings::class,
        'user_two_factor_enforced' => UserSettings::class,
        'user_inactive_lock_days' => UserSettings::class,
        'user_max_login_attempts' => UserSettings::class,
    ];

    private static ?array $cache = null;

    public static function get(string $key, ?string $default = null): ?string
    {
        if (isset(self::TYPED_KEYS[$key])) {
            $settings = app(self::TYPED_KEYS[$key]);
            $value = $settings->{$key};

            return $value === null ? $default : (string) $value;
        }

        if (self::$cache === null) {
            self::$cache = DB::table('settings')
                ->pluck('setting_value', 'setting_key')
                ->toArray();
        }

        return self::$cache[$key] ?? $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        return filter_var(
            self::get($key, $default ? 'yes' : 'no'),
            FILTER_VALIDATE_BOOLEAN
        );
    }
}
