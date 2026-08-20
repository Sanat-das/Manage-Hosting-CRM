<?php

namespace Database\Seeders\Demo;

use Database\Seeders\Demo\Traits\WithIdempotentSeed;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Demo product extras.
 *
 * Seeds, idempotently:
 *   product_addons          8 addons attached to demo products
 *   product_upgrades        3 directed upgrade rows (upgrade_type enum)
 *   product_upgrade_paths   3 upgrade path configs (enabled flag)
 *   product_bundles         3 bundle-component links
 *   catalog_products        8 catalog entries (unique SKU)
 *   product_resources       16 resource allocations (2 per product × 8)
 *   product_quota_summary   8 quota summaries (1 per product)
 *
 * ENUM VALUES ARE TAKEN FROM THE MIGRATIONS, NOT GUESSED
 * -----------------------------------------------------
 * product_addons.billing_cycle        one_time|monthly|quarterly|semi_annual|annual
 *                                     (narrower than product_pricing — NO
 *                                      free/biennial/triennial)
 * product_upgrades.upgrade_type       upgrade|downgrade|both
 * product_bundles.discount_type       percent|fixed
 * catalog_products.product_type       shared_hosting|reseller|vps|dedicated|
 *                                     domain|addon|bundle|license|other
 * catalog_products.provisioning_method  manual|cpanel|plesk|directadmin|
 *                                        proxmox|vmware|hyperv|solusvm|
 *                                        virtualizor|docker|kubernetes|api|
 *                                        custom_script
 * catalog_products.billing_model      one_time|recurring|usage_based|tiered
 * catalog_products.status             active|inactive|retired
 *
 * SQLite compiles these into CHECK constraints, so any invented value aborts
 * the seed. Every literal below is copied verbatim from the migration list.
 *
 * MIGRATION SOURCES
 * -----------------
 * - product_addons + product_upgrades: 2026_07_30_120010_create_product_tables.php
 * - product_upgrade_paths:             2026_08_14_000002_create_product_upgrade_paths_table.php
 * - product_bundles:                   2026_08_14_000001_create_product_bundles_table.php
 * - catalog_products:                  2026_07_30_120020_create_config_tables.php
 * - product_resources:                 2026_07_30_120090_create_resource_provisioning_tables.php
 * - product_quota_summary:             2026_07_30_120090_create_resource_provisioning_tables.php
 *
 * PLAN GAP
 * --------
 * product_resources and product_quota_summary are declared in
 * DummyDataConfig::ROWS (min 16 / min 8) but were not assigned to any task in
 * the plan brief. This seeder owns them so the row matrix stays satisfied.
 *
 * SCHEMA NOTES THAT SHAPE THE DATA
 * --------------------------------
 * - product_upgrades has NO timestamp columns (only id + FKs + enum + bool).
 * - product_resources has `created_at` only — no `updated_at`. The trait
 *   detects this and preserves created_at across re-runs.
 * - product_quota_summary has NO `id` and NO `created_at` — only product_id
 *   (UNIQUE/FK), summary_json, and updated_at. seedRow returns null for such
 *   tables; we do not chain off the return value.
 * - catalog_products uses SoftDeletes (deleted_at column); the trait writes
 *   through the query builder so soft-deleted rows are not an issue here.
 * - product_addons.product_id is nullable (global addons). The natural key
 *   (product_id, name) handles NULL via IS NULL in updateOrInsert.
 */
class ProductExtrasSeeder extends Seeder
{
    use WithIdempotentSeed;

    /**
     * Demo product names — resolved to ids at runtime, never hardcoded.
     *
     * @var list<string>
     */
    private const PRODUCT_NAMES = [
        'Demo Starter Shared Hosting',
        'Demo Business Shared Hosting',
        'Demo Reseller Bronze',
        'Demo Cloud VPS 2GB',
        'Demo Cloud VPS 8GB',
        'Demo Dedicated E3 Server',
        'Demo .com Domain Registration',
        'Demo SSL & Backup Addon',
        'Demo Legacy Hosting Pack',
    ];

    /**
     * Product types -> product_groups.slug, for resolving catalog_products.
     *
     * @var array<string, string>
     */
    private const CATALOG_CATEGORY_MAP = [
        'shared_hosting' => 'shared-hosting',
        'reseller' => 'reseller-hosting',
        'vps' => 'vps-hosting',
        'dedicated' => 'dedicated-servers',
        'domain' => 'domain-registration',
        'addon' => 'addons-extras',
    ];

    public function run(): void
    {
        $products = $this->resolveProducts();
        $resourceTypes = $this->resolveResourceTypes();
        $groups = $this->resolveGroups();

        $this->seedAddons($products);
        $this->seedUpgrades($products);
        $this->seedUpgradePaths($products);
        $this->seedBundles($products);
        $this->seedCatalogProducts($groups);
        $this->seedProductResources($products, $resourceTypes);
        $this->seedQuotaSummaries($products);

        $this->report();
    }

    // ------------------------------------------------------------------
    // Foreign-key resolution
    // ------------------------------------------------------------------

    /**
     * @return array<string, int> name => products.id
     */
    private function resolveProducts(): array
    {
        $rows = DB::table('products')
            ->whereIn('name', self::PRODUCT_NAMES)
            ->pluck('id', 'name');

        foreach (self::PRODUCT_NAMES as $name) {
            if (! $rows->has($name)) {
                throw new RuntimeException(sprintf(
                    'ProductExtrasSeeder requires demo product "%s" — run ProductSeeder first.',
                    $name
                ));
            }
        }

        return $rows->all();
    }

    /**
     * @return array<string, int> slug => resource_types.id
     */
    private function resolveResourceTypes(): array
    {
        return DB::table('resource_types')->pluck('id', 'slug')->all();
    }

    /**
     * @return array<string, int> slug => product_groups.id
     */
    private function resolveGroups(): array
    {
        return DB::table('product_groups')->pluck('id', 'slug')->all();
    }

    // ------------------------------------------------------------------
    // product_addons (>= 8)
    // ------------------------------------------------------------------

    /**
     * @param  array<string, int>  $products
     */
    private function seedAddons(array $products): void
    {
        $rows = [
            [
                'product_id' => $products['Demo Starter Shared Hosting'],
                'name' => 'Extra 50GB SSD Storage',
                'description' => 'One-time add-on: 50 GB of additional SSD disk space.',
                'billing_cycle' => 'one_time',
                'setup_fee' => 0.00,
                'price' => 499.00,
                'welcome_email_template_id' => null,
                'status' => 'active',
            ],
            [
                'product_id' => $products['Demo Starter Shared Hosting'],
                'name' => 'Dedicated IP Address',
                'description' => 'A dedicated IPv4 address for this hosting account.',
                'billing_cycle' => 'one_time',
                'setup_fee' => 0.00,
                'price' => 299.00,
                'welcome_email_template_id' => null,
                'status' => 'active',
            ],
            [
                'product_id' => $products['Demo Business Shared Hosting'],
                'name' => 'LiteSpeed Cache Pro',
                'description' => 'Enterprise LiteSpeed + LSCache for faster page loads.',
                'billing_cycle' => 'annual',
                'setup_fee' => 0.00,
                'price' => 1299.00,
                'welcome_email_template_id' => null,
                'status' => 'active',
            ],
            [
                'product_id' => $products['Demo Business Shared Hosting'],
                'name' => 'Priority Support',
                'description' => 'Jump the queue with 24h priority ticket handling.',
                'billing_cycle' => 'monthly',
                'setup_fee' => 0.00,
                'price' => 199.00,
                'welcome_email_template_id' => null,
                'status' => 'active',
            ],
            [
                'product_id' => $products['Demo Cloud VPS 2GB'],
                'name' => 'Additional IPv4 Address',
                'description' => 'One extra public IPv4 address routed to this VPS.',
                'billing_cycle' => 'quarterly',
                'setup_fee' => 0.00,
                'price' => 399.00,
                'welcome_email_template_id' => null,
                'status' => 'active',
            ],
            [
                'product_id' => $products['Demo Cloud VPS 8GB'],
                'name' => 'cPanel License',
                'description' => 'cPanel/WHM license added to this virtual server.',
                'billing_cycle' => 'monthly',
                'setup_fee' => 0.00,
                'price' => 1499.00,
                'welcome_email_template_id' => null,
                'status' => 'active',
            ],
            [
                'product_id' => $products['Demo SSL & Backup Addon'],
                'name' => 'Extended Backup Retention',
                'description' => 'Extend offsite backup retention from 30 to 90 days.',
                'billing_cycle' => 'monthly',
                'setup_fee' => 0.00,
                'price' => 299.00,
                'welcome_email_template_id' => null,
                'status' => 'active',
            ],
            [
                'product_id' => $products['Demo Legacy Hosting Pack'],
                'name' => 'Legacy Migration Assistance',
                'description' => 'One-time assisted migration from the legacy platform.',
                'billing_cycle' => 'one_time',
                'setup_fee' => 0.00,
                'price' => 1999.00,
                'welcome_email_template_id' => null,
                'status' => 'active',
            ],
        ];

        $this->seedRows('product_addons', $rows);
    }

    // ------------------------------------------------------------------
    // product_upgrades (>= 3) — NO timestamps on this table
    // ------------------------------------------------------------------

    /**
     * @param  array<string, int>  $products
     */
    private function seedUpgrades(array $products): void
    {
        $rows = [
            [
                'from_product_id' => $products['Demo Starter Shared Hosting'],
                'to_product_id' => $products['Demo Business Shared Hosting'],
                'upgrade_type' => 'upgrade',
                'allowed' => true,
            ],
            [
                'from_product_id' => $products['Demo Cloud VPS 2GB'],
                'to_product_id' => $products['Demo Cloud VPS 8GB'],
                'upgrade_type' => 'upgrade',
                'allowed' => true,
            ],
            [
                'from_product_id' => $products['Demo Business Shared Hosting'],
                'to_product_id' => $products['Demo Reseller Bronze'],
                'upgrade_type' => 'both',
                'allowed' => true,
            ],
        ];

        $this->seedRows('product_upgrades', $rows);
    }

    // ------------------------------------------------------------------
    // product_upgrade_paths (>= 3)
    // ------------------------------------------------------------------

    /**
     * @param  array<string, int>  $products
     */
    private function seedUpgradePaths(array $products): void
    {
        $rows = [
            [
                'from_product_id' => $products['Demo Starter Shared Hosting'],
                'to_product_id' => $products['Demo Business Shared Hosting'],
                'enabled' => true,
            ],
            [
                'from_product_id' => $products['Demo Cloud VPS 2GB'],
                'to_product_id' => $products['Demo Cloud VPS 8GB'],
                'enabled' => true,
            ],
            [
                'from_product_id' => $products['Demo Business Shared Hosting'],
                'to_product_id' => $products['Demo Reseller Bronze'],
                'enabled' => true,
            ],
        ];

        $this->seedRows('product_upgrade_paths', $rows);
    }

    // ------------------------------------------------------------------
    // product_bundles (>= 3)
    // ------------------------------------------------------------------

    /**
     * @param  array<string, int>  $products
     */
    private function seedBundles(array $products): void
    {
        $rows = [
            [
                'bundle_product_id' => $products['Demo Starter Shared Hosting'],
                'component_product_id' => $products['Demo .com Domain Registration'],
                'quantity' => 1,
                'discount_type' => 'percent',
                'discount_value' => 100.00,
                'sort_order' => 1,
            ],
            [
                'bundle_product_id' => $products['Demo Business Shared Hosting'],
                'component_product_id' => $products['Demo SSL & Backup Addon'],
                'quantity' => 1,
                'discount_type' => 'fixed',
                'discount_value' => 1499.00,
                'sort_order' => 1,
            ],
            [
                'bundle_product_id' => $products['Demo Cloud VPS 8GB'],
                'component_product_id' => $products['Demo .com Domain Registration'],
                'quantity' => 1,
                'discount_type' => 'percent',
                'discount_value' => 50.00,
                'sort_order' => 1,
            ],
        ];

        $this->seedRows('product_bundles', $rows);
    }

    // ------------------------------------------------------------------
    // catalog_products (>= 8)
    // ------------------------------------------------------------------

    /**
     * @param  array<string, int>  $groups  slug => product_groups.id
     */
    private function seedCatalogProducts(array $groups): void
    {
        $entries = [
            ['sku' => 'DEMO-CAT-001', 'name' => 'Demo Starter Shared Hosting', 'product_type' => 'shared_hosting', 'provisioning_method' => 'cpanel', 'billing_model' => 'recurring', 'require_domain' => true, 'show_in_order' => true, 'only_admin' => false, 'sort_order' => 10, 'status' => 'active', 'version' => 1, 'category_slug' => 'shared-hosting'],
            ['sku' => 'DEMO-CAT-002', 'name' => 'Demo Business Shared Hosting', 'product_type' => 'shared_hosting', 'provisioning_method' => 'cpanel', 'billing_model' => 'recurring', 'require_domain' => true, 'show_in_order' => true, 'only_admin' => false, 'sort_order' => 20, 'status' => 'active', 'version' => 1, 'category_slug' => 'shared-hosting'],
            ['sku' => 'DEMO-CAT-003', 'name' => 'Demo Reseller Bronze', 'product_type' => 'reseller', 'provisioning_method' => 'cpanel', 'billing_model' => 'recurring', 'require_domain' => true, 'show_in_order' => true, 'only_admin' => false, 'sort_order' => 30, 'status' => 'active', 'version' => 1, 'category_slug' => 'reseller-hosting'],
            ['sku' => 'DEMO-CAT-004', 'name' => 'Demo Cloud VPS 2GB', 'product_type' => 'vps', 'provisioning_method' => 'virtualizor', 'billing_model' => 'recurring', 'require_domain' => false, 'show_in_order' => true, 'only_admin' => false, 'sort_order' => 40, 'status' => 'active', 'version' => 1, 'category_slug' => 'vps-hosting'],
            ['sku' => 'DEMO-CAT-005', 'name' => 'Demo Cloud VPS 8GB', 'product_type' => 'vps', 'provisioning_method' => 'virtualizor', 'billing_model' => 'recurring', 'require_domain' => false, 'show_in_order' => true, 'only_admin' => false, 'sort_order' => 50, 'status' => 'active', 'version' => 1, 'category_slug' => 'vps-hosting'],
            ['sku' => 'DEMO-CAT-006', 'name' => 'Demo Dedicated E3 Server', 'product_type' => 'dedicated', 'provisioning_method' => 'custom_script', 'billing_model' => 'recurring', 'require_domain' => false, 'show_in_order' => true, 'only_admin' => false, 'sort_order' => 60, 'status' => 'active', 'version' => 1, 'category_slug' => 'dedicated-servers'],
            ['sku' => 'DEMO-CAT-007', 'name' => 'Demo .com Domain', 'product_type' => 'domain', 'provisioning_method' => 'manual', 'billing_model' => 'one_time', 'require_domain' => true, 'show_in_order' => true, 'only_admin' => false, 'sort_order' => 70, 'status' => 'active', 'version' => 1, 'category_slug' => 'domain-registration'],
            ['sku' => 'DEMO-CAT-008', 'name' => 'Demo SSL & Backup Addon', 'product_type' => 'addon', 'provisioning_method' => 'manual', 'billing_model' => 'one_time', 'require_domain' => true, 'show_in_order' => true, 'only_admin' => false, 'sort_order' => 80, 'status' => 'active', 'version' => 1, 'category_slug' => 'addons-extras'],
        ];

        $rows = [];

        foreach ($entries as $entry) {
            $slug = $entry['category_slug'];
            unset($entry['category_slug']);

            $rows[] = $entry + [
                'category_id' => $groups[$slug] ?? null,
                'description' => null,
                'provisioning_config' => null,
            ];
        }

        $this->seedRows('catalog_products', $rows);
    }

    // ------------------------------------------------------------------
    // product_resources (>= 16) — 2 per product × 8 products
    // ------------------------------------------------------------------

    /**
     * @param  array<string, int>  $products  name => id
     * @param  array<string, int>  $resourceTypes  slug => id
     */
    private function seedProductResources(array $products, array $resourceTypes): void
    {
        $defs = [
            ['name' => 'Demo Starter Shared Hosting', 'resources' => [
                ['slug' => 'cpu_core', 'quantity' => 1.0000, 'is_required' => true, 'is_upgradable' => true, 'min' => 1.0000, 'max' => 4.0000],
                ['slug' => 'ram', 'quantity' => 1024.0000, 'is_required' => true, 'is_upgradable' => true, 'min' => 512.0000, 'max' => 4096.0000],
            ]],
            ['name' => 'Demo Business Shared Hosting', 'resources' => [
                ['slug' => 'cpu_core', 'quantity' => 2.0000, 'is_required' => true, 'is_upgradable' => true, 'min' => 1.0000, 'max' => 8.0000],
                ['slug' => 'ram', 'quantity' => 2048.0000, 'is_required' => true, 'is_upgradable' => true, 'min' => 1024.0000, 'max' => 8192.0000],
            ]],
            ['name' => 'Demo Reseller Bronze', 'resources' => [
                ['slug' => 'cpu_core', 'quantity' => 4.0000, 'is_required' => true, 'is_upgradable' => true, 'min' => 2.0000, 'max' => 16.0000],
                ['slug' => 'ram', 'quantity' => 4096.0000, 'is_required' => true, 'is_upgradable' => true, 'min' => 2048.0000, 'max' => 16384.0000],
            ]],
            ['name' => 'Demo Cloud VPS 2GB', 'resources' => [
                ['slug' => 'cpu_core', 'quantity' => 2.0000, 'is_required' => true, 'is_upgradable' => true, 'min' => 1.0000, 'max' => 4.0000],
                ['slug' => 'ram', 'quantity' => 2048.0000, 'is_required' => true, 'is_upgradable' => true, 'min' => 1024.0000, 'max' => 4096.0000],
            ]],
            ['name' => 'Demo Cloud VPS 8GB', 'resources' => [
                ['slug' => 'cpu_core', 'quantity' => 4.0000, 'is_required' => true, 'is_upgradable' => true, 'min' => 2.0000, 'max' => 8.0000],
                ['slug' => 'ram', 'quantity' => 8192.0000, 'is_required' => true, 'is_upgradable' => true, 'min' => 4096.0000, 'max' => 16384.0000],
            ]],
            ['name' => 'Demo Dedicated E3 Server', 'resources' => [
                ['slug' => 'cpu_core', 'quantity' => 8.0000, 'is_required' => true, 'is_upgradable' => false, 'min' => 8.0000, 'max' => 8.0000],
                ['slug' => 'ram', 'quantity' => 32768.0000, 'is_required' => true, 'is_upgradable' => false, 'min' => 32768.0000, 'max' => 32768.0000],
            ]],
            ['name' => 'Demo .com Domain Registration', 'resources' => [
                ['slug' => 'domains', 'quantity' => 1.0000, 'is_required' => true, 'is_upgradable' => false, 'min' => 1.0000, 'max' => 1.0000],
                ['slug' => 'email_accounts', 'quantity' => 0.0000, 'is_required' => false, 'is_upgradable' => true, 'min' => 0.0000, 'max' => 100.0000],
            ]],
            ['name' => 'Demo SSL & Backup Addon', 'resources' => [
                ['slug' => 'ssl_certificates', 'quantity' => 1.0000, 'is_required' => true, 'is_upgradable' => false, 'min' => 1.0000, 'max' => 1.0000],
                ['slug' => 'backup_storage', 'quantity' => 10240.0000, 'is_required' => true, 'is_upgradable' => true, 'min' => 5120.0000, 'max' => 51200.0000],
            ]],
        ];

        $rows = [];

        foreach ($defs as $def) {
            $productId = $products[$def['name']];

            foreach ($def['resources'] as $resource) {
                $rows[] = [
                    'product_id' => $productId,
                    'resource_type_id' => $resourceTypes[$resource['slug']],
                    'quantity' => $resource['quantity'],
                    'is_required' => $resource['is_required'],
                    'is_upgradable' => $resource['is_upgradable'],
                    'min_quantity' => $resource['min'],
                    'max_quantity' => $resource['max'],
                ];
            }
        }

        $this->seedRows('product_resources', $rows);
    }

    // ------------------------------------------------------------------
    // product_quota_summary (>= 8) — NO id, NO created_at
    // ------------------------------------------------------------------

    /**
     * @param  array<string, int>  $products  name => id
     */
    private function seedQuotaSummaries(array $products): void
    {
        // products.quota_* were removed from the schema (plan task 1), so the
        // denormalized summary can no longer be derived from the product row.
        // It is now fed from a static demo profile keyed by product name.
        $quotas = [
            'Demo Starter Shared Hosting' => ['disk_mb' => 10240, 'bandwidth_mb' => 102400, 'email_accounts' => 10, 'databases' => 2, 'cpu_cores' => 1, 'cpu_mhz' => 1000, 'ram_mb' => 1024, 'ips' => 0, 'ftp_accounts' => 2, 'subdomains' => 5],
            'Demo Business Shared Hosting' => ['disk_mb' => 51200, 'bandwidth_mb' => 512000, 'email_accounts' => 100, 'databases' => 25, 'cpu_cores' => 2, 'cpu_mhz' => 2000, 'ram_mb' => 2048, 'ips' => 0, 'ftp_accounts' => 10, 'subdomains' => 50],
            'Demo Reseller Bronze' => ['disk_mb' => 102400, 'bandwidth_mb' => 1024000, 'email_accounts' => 500, 'databases' => 100, 'cpu_cores' => 4, 'cpu_mhz' => 2400, 'ram_mb' => 4096, 'ips' => 1, 'ftp_accounts' => 50, 'subdomains' => 200],
            'Demo Cloud VPS 2GB' => ['disk_mb' => 51200, 'bandwidth_mb' => 2048000, 'email_accounts' => 0, 'databases' => 0, 'cpu_cores' => 2, 'cpu_mhz' => 2600, 'ram_mb' => 2048, 'ips' => 1, 'ftp_accounts' => 0, 'subdomains' => 0],
            'Demo Cloud VPS 8GB' => ['disk_mb' => 204800, 'bandwidth_mb' => 5120000, 'email_accounts' => 0, 'databases' => 0, 'cpu_cores' => 4, 'cpu_mhz' => 3200, 'ram_mb' => 8192, 'ips' => 2, 'ftp_accounts' => 0, 'subdomains' => 0],
            'Demo Dedicated E3 Server' => ['disk_mb' => 1048576, 'bandwidth_mb' => 10240000, 'email_accounts' => 0, 'databases' => 0, 'cpu_cores' => 8, 'cpu_mhz' => 3400, 'ram_mb' => 32768, 'ips' => 5, 'ftp_accounts' => 0, 'subdomains' => 0],
            'Demo .com Domain Registration' => ['disk_mb' => 0, 'bandwidth_mb' => 0, 'email_accounts' => 0, 'databases' => 0, 'cpu_cores' => 0, 'cpu_mhz' => 0, 'ram_mb' => 0, 'ips' => 0, 'ftp_accounts' => 0, 'subdomains' => 0],
            'Demo SSL & Backup Addon' => ['disk_mb' => 10240, 'bandwidth_mb' => 0, 'email_accounts' => 0, 'databases' => 0, 'cpu_cores' => 0, 'cpu_mhz' => 0, 'ram_mb' => 0, 'ips' => 0, 'ftp_accounts' => 0, 'subdomains' => 0],
            'Demo Legacy Hosting Pack' => ['disk_mb' => 20480, 'bandwidth_mb' => 204800, 'email_accounts' => 25, 'databases' => 10, 'cpu_cores' => 1, 'cpu_mhz' => 1200, 'ram_mb' => 1024, 'ips' => 0, 'ftp_accounts' => 5, 'subdomains' => 20],
        ];

        $payload = [];

        foreach ($products as $name => $id) {
            if (! isset($quotas[$name])) {
                continue;
            }

            $payload[] = [
                'product_id' => $id,
                'summary_json' => json_encode($quotas[$name]),
            ];
        }

        $this->seedRows('product_quota_summary', $payload);
    }

    // ------------------------------------------------------------------
    // Report
    // ------------------------------------------------------------------

    private function report(): void
    {
        $tables = [
            'product_addons',
            'product_upgrades',
            'product_upgrade_paths',
            'product_bundles',
            'catalog_products',
            'product_resources',
            'product_quota_summary',
        ];

        $this->command?->info('ProductExtrasSeeder summary:');

        foreach ($tables as $table) {
            $count = (int) DB::table($table)->count();
            $minimum = DummyDataConfig::minRows($table);
            $status = $count >= $minimum ? 'OK' : 'LOW';
            $this->command?->info(sprintf(
                '  %-26s %3d  (min %d)  [%s]',
                $table, $count, $minimum, $status
            ));
        }
    }
}
