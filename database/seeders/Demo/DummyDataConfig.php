<?php

namespace Database\Seeders\Demo;

/**
 * Single source of truth for the demo ("dummy data") seed profile.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * Both the demo seeders and the Feature tests that verify them read their
 * expectations from here. A seeder must produce AT LEAST `ROWS[$table]` rows
 * for every business table, and a test asserts exactly that. Changing the
 * profile therefore means editing one constant, never two codebases.
 *
 * PROFILE: "modest"
 * -----------------
 * Anchors: 5 customers, 8 products. Every leaf/child table carries 1-3 rows
 * per owning parent, so totals stay small enough to seed in seconds while
 * still exercising every relationship, pivot and log table in the schema.
 *
 * COVERAGE
 * --------
 * `ROWS` covers all 93 application tables of the schema. The 10 framework
 * tables listed in `SYSTEM_TABLES` are deliberately excluded - they are owned
 * by Laravel (cache/queue/session/auth plumbing) and are not business data.
 * 93 business + 10 system = 103 tables, matching `migrate:fresh`.
 *
 * NOTE ON TIMESTAMPS
 * ------------------
 * Several tables have partial or no timestamp columns
 * (`product_option_groups`, `customer_wallet`, `server_groups`,
 * `server_group_members`, `domain_search_logs`, `domain_sync_log`, `payments`
 * have `created_at` only; `ip_allocation_history` has `changed_at` only;
 * `invoice_items`, `product_option_pricing`, `product_option_values`,
 * `credits`, `activity_log`, `audit_log` have none or partial).
 * `WithIdempotentSeed` detects the actual columns at runtime rather than
 * assuming Eloquent's defaults.
 */
final class DummyDataConfig
{
    /** Number of demo customer accounts (the primary fan-out anchor). */
    public const CUSTOMERS = 5;

    /** Number of demo products in the catalog (the secondary fan-out anchor). */
    public const PRODUCTS = 8;

    /** Total application tables the demo seed must populate. */
    public const TOTAL_BUSINESS_TABLES = 93;

    /**
     * Framework-owned tables intentionally absent from `ROWS`.
     *
     * @var list<string>
     */
    public const SYSTEM_TABLES = [
        'cache',
        'cache_locks',
        'failed_jobs',
        'job_batches',
        'jobs',
        'migrations',
        'password_reset_tokens',
        'personal_access_tokens',
        'sessions',
        'sqlite_sequence',
    ];

    /**
     * Minimum row count per business table.
     *
     * Grouped by domain for readability; the array itself is flat
     * (table_name => minimum rows) and is what assertions consume.
     *
     * @var array<string, int>
     */
    public const ROWS = [
        // --- Identity & access (8) ------------------------------------
        // 1 admin + 3 staff + 5 customer-facing logins.
        'users' => 9,
        'customers' => self::CUSTOMERS,
        'adminlte_roles' => 6,
        'adminlte_permissions' => 84,
        'adminlte_permission_role' => 140,
        'adminlte_role_user' => 4,
        'passkeys' => 2,
        'impersonation_tokens' => 2,

        // --- Customer CRM (6) -----------------------------------------
        // 2 per customer for contacts/notes/wallet/consent; 1 per customer elsewhere.
        'customer_contacts' => 10,
        'customer_notes' => 10,
        'customer_wallet' => 10,
        'credits' => 5,
        'billing_cycles' => 10,
        'marketing_consent_log' => 10,

        // --- Product catalog (17) -------------------------------------
        // 8 products; 2 pricing rows + 2 meta rows + 2 resources each.
        'products' => self::PRODUCTS,
        'product_groups' => 6,
        'product_pricing' => 16,
        'product_meta' => 16,
        'product_addons' => 8,
        'product_option_groups' => 4,
        'product_option_group_product' => 27,
        'product_option_values' => 8,
        'product_option_pricing' => 16,
        // Product-options snapshot: 1 pivot per attached group; 2 link values
        // per pivot; each link value mirrors the source value's per-cycle
        // pricing (2-3 cycles each).
        'product_option_link_values' => 56,
        'product_option_link_value_pricing' => 160,
        'product_resources' => 16,
        'product_quota_summary' => 8,
        'product_bundles' => 3,
        'product_upgrade_paths' => 3,
        'product_upgrades' => 3,
        'catalog_products' => 8,

        // --- Sales & billing (11) -------------------------------------
        // 2 orders per customer; each order yields an invoice and line items.
        'orders' => 10,
        'order_items' => 15,
        'order_status_history' => 20,
        'invoices' => 10,
        'invoice_items' => 20,
        'invoice_pdf_log' => 5,
        'payments' => 8,
        'transactions' => 8,
        'quotes' => 5,
        'quote_items' => 10,
        'tax_rates' => 3,

        // --- Support (5) ----------------------------------------------
        'tickets' => 8,
        'ticket_replies' => 16,
        'knowledge_base' => 6,
        'chat_sessions' => 5,
        'chat_messages' => 15,

        // --- Domains & DNS (8) ----------------------------------------
        'domains' => 8,
        'domain_pricing' => 10,
        'domain_pricing_terms' => 20,
        'domain_search_logs' => 10,
        'domain_sync_log' => 5,
        'registrar_settings' => 6,
        'dns_zones' => 5,
        'dns_records' => 15,

        // --- Services & provisioning (10) -----------------------------
        'hosting_accounts' => 8,
        'service_instances' => 8,
        'subscription_periods' => 8,
        'subscription_changes' => 3,
        'usage_records' => 16,
        'resource_allocations' => 16,
        'resource_pools' => 5,
        'resource_types' => 18,
        'provisioning_adapters' => 5,
        'provisioning_events' => 6,

        // --- Infrastructure & inventory (14) --------------------------
        'servers' => 4,
        'server_groups' => 2,
        'server_group_members' => 4,
        'datacenters' => 2,
        'racks' => 4,
        'inventory_assets' => 10,
        'asset_relationships' => 5,
        'licenses' => 5,
        'license_assignments' => 5,
        'ip_subnets' => 3,
        'ip_addresses' => 20,
        'ip_allocation_history' => 10,
        'vlans' => 3,
        'ssl_certificates' => 5,

        // --- Communications (5) ---------------------------------------
        'email_templates' => 6,
        'email_queue' => 5,
        'emails' => 10,
        'notifications' => 5,
        'notification_preferences' => 10,

        // --- Logs & audit (4) -----------------------------------------
        'activity_log' => 15,
        'audit_log' => 15,
        'automation_log' => 10,
        'cron_logs' => 5,

        // --- Configuration (5) ----------------------------------------
        // Already populated by the existing config migrations/seeders;
        // listed so the matrix is exhaustive and regressions are caught.
        'payment_gateways' => 3,
        'gst_settings' => 1,
        'settings' => 17,
        'settings_properties' => 160,
        'sequences' => 1,
    ];

    /**
     * Natural (business) key columns per table, used for idempotent upserts.
     *
     * Where the schema declares a UNIQUE index the key mirrors it exactly.
     * Otherwise a deterministic, seed-controlled column combination is used
     * so re-running the seeder updates instead of duplicating. Volatile
     * columns (timestamps, ids, hashes) are never part of a natural key.
     *
     * @var array<string, list<string>>
     */
    public const NATURAL_KEYS = [
        // Identity & access
        'users' => ['email'],
        'customers' => ['user_id'],
        'adminlte_roles' => ['name'],
        'adminlte_permissions' => ['name'],
        'adminlte_permission_role' => ['permission_id', 'role_id'],
        'adminlte_role_user' => ['role_id', 'user_id'],
        'passkeys' => ['credential_id'],
        'impersonation_tokens' => ['token'],

        // Customer CRM
        'customer_contacts' => ['customer_id', 'email'],
        'customer_notes' => ['customer_id', 'note'],
        'customer_wallet' => ['customer_id', 'type', 'description'],
        'credits' => ['customer_id', 'description'],
        'billing_cycles' => ['customer_id', 'cycle_start', 'cycle_end'],
        'marketing_consent_log' => ['customer_id', 'contact_type'],

        // Product catalog
        'products' => ['name'],
        'product_groups' => ['slug'],
        'product_pricing' => ['product_id', 'billing_cycle'],
        'product_meta' => ['product_id', 'meta_key'],
        'product_addons' => ['product_id', 'name'],
        'product_option_groups' => ['name'],
        'product_option_group_product' => ['product_id', 'option_group_id'],
        'product_option_values' => ['option_group_id', 'label'],
        'product_option_pricing' => ['option_value_id', 'billing_cycle'],
        'product_option_link_values' => ['product_option_group_product_id', 'label'],
        'product_option_link_value_pricing' => ['product_option_link_value_id', 'billing_cycle'],
        'product_resources' => ['product_id', 'resource_type_id'],
        'product_quota_summary' => ['product_id'],
        'product_bundles' => ['bundle_product_id', 'component_product_id'],
        'product_upgrade_paths' => ['from_product_id', 'to_product_id'],
        'product_upgrades' => ['from_product_id', 'to_product_id'],
        'catalog_products' => ['sku'],

        // Sales & billing
        'orders' => ['order_number'],
        'order_items' => ['order_id', 'product_name'],
        'order_status_history' => ['order_id', 'from_status', 'to_status'],
        'invoices' => ['invoice_no'],
        'invoice_items' => ['invoice_id', 'description'],
        'invoice_pdf_log' => ['invoice_id', 'file_name'],
        'payments' => ['transaction_id'],
        'transactions' => ['transaction_id'],
        'quotes' => ['quote_no'],
        'quote_items' => ['quote_id', 'description'],
        'tax_rates' => ['name'],

        // Support
        'tickets' => ['ticket_no'],
        'ticket_replies' => ['ticket_id', 'message'],
        'knowledge_base' => ['slug'],
        'chat_sessions' => ['customer_id', 'email', 'department'],
        'chat_messages' => ['session_id', 'message'],

        // Domains & DNS
        'domains' => ['name'],
        'domain_pricing' => ['tld'],
        'domain_pricing_terms' => ['domain_pricing_id', 'term_years'],
        'domain_search_logs' => ['customer_id', 'domain_name'],
        'domain_sync_log' => ['provider', 'operation', 'payload'],
        'registrar_settings' => ['registrar', 'setting_key'],
        'dns_zones' => ['name'],
        'dns_records' => ['zone_id', 'name', 'type', 'content'],

        // Services & provisioning
        'hosting_accounts' => ['username', 'domain'],
        'service_instances' => ['service_tag'],
        'subscription_periods' => ['service_id', 'start_date', 'end_date'],
        'subscription_changes' => ['service_id', 'change_type', 'effective_date'],
        'usage_records' => ['service_id', 'resource_type_id', 'metric', 'billing_period_start'],
        'resource_allocations' => ['service_id', 'resource_type_id'],
        'resource_pools' => ['name'],
        'resource_types' => ['slug'],
        'provisioning_adapters' => ['name'],
        'provisioning_events' => ['event_type', 'payload'],

        // Infrastructure & inventory
        'servers' => ['name'],
        'server_groups' => ['name'],
        'server_group_members' => ['server_group_id', 'server_id'],
        'datacenters' => ['code'],
        'racks' => ['datacenter_id', 'name'],
        'inventory_assets' => ['asset_tag'],
        'asset_relationships' => ['parent_kind', 'parent_id', 'child_kind', 'child_id', 'relationship_type'],
        'licenses' => ['license_key'],
        'license_assignments' => ['license_id', 'assigned_to_type', 'assigned_to_id'],
        'ip_subnets' => ['subnet_cidr'],
        'ip_addresses' => ['subnet_id', 'ip_address'],
        'ip_allocation_history' => ['ip_address_id', 'action', 'notes'],
        'vlans' => ['vlan_id'],
        'ssl_certificates' => ['domain_name', 'certificate_type'],

        // Communications
        'email_templates' => ['name'],
        'email_queue' => ['to_email', 'subject'],
        'emails' => ['customer_id', 'to_email', 'subject'],
        'notifications' => ['id'],
        'notification_preferences' => ['preferrable_type', 'preferrable_id', 'type', 'channel'],

        // Logs & audit
        'activity_log' => ['user_id', 'action', 'description'],
        'audit_log' => ['user_id', 'action', 'entity_type', 'entity_id'],
        'automation_log' => ['action', 'entity_type', 'entity_id'],
        'cron_logs' => ['job_name', 'command'],

        // Configuration
        'payment_gateways' => ['code'],
        'gst_settings' => ['gstin'],
        'settings' => ['setting_key'],
        'settings_properties' => ['group', 'name'],
        'sequences' => ['key'],
    ];

    /**
     * Tables whose rows must never be touched or recreated by demo seeders.
     * `InitialDataSeeder` owns the admin login and must stay authoritative.
     *
     * @var list<string>
     */
    public const PROTECTED_ROWS = [
        'users' => ['email' => 'admin@localhost.com'],
    ];

    /** All business table names covered by the matrix. */
    public static function tables(): array
    {
        return array_keys(self::ROWS);
    }

    /** Minimum expected rows for a table, or 0 when it is not a business table. */
    public static function minRows(string $table): int
    {
        return self::ROWS[$table] ?? 0;
    }

    /** Natural key columns for a table. */
    public static function naturalKey(string $table): array
    {
        return self::NATURAL_KEYS[$table] ?? ['id'];
    }
}
