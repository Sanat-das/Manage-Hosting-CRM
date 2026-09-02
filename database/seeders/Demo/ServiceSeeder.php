<?php

namespace Database\Seeders\Demo;

use Database\Seeders\Demo\Traits\WithIdempotentSeed;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Demo services & assets.
 *
 * SCOPE
 * -----
 * Eight tables, in dependency order:
 *
 *   hosting_accounts      -> customers + products + servers  (panel-side accounts)
 *   catalog_products      -> only topped up when empty; `service_instances`
 *                            has a hard FK to it and Task 6 owns the table
 *   service_instances     -> customers + catalog_products    (billing-side services)
 *   subscription_periods  -> service_instances (2 per service: expired + active)
 *   subscription_changes  -> service_instances (renewal / upgrade / addon)
 *   usage_records         -> service_instances + resource_types
 *   licenses              -> inventory_assets (1:1, `inventory_asset_id` UNIQUE)
 *   license_assignments   -> licenses -> servers / service_instances
 *   ssl_certificates      -> customers
 *
 * TWO PARALLEL SERVICE MODELS EXIST — BOTH ARE SEEDED
 * ---------------------------------------------------
 * `hosting_accounts` (2026_07_30_120030) is the legacy/panel record and links
 * to `products` + `servers` with plain unsigned columns (no FK constraints).
 * `service_instances` (2026_07_30_120100) is the newer subscription record and
 * links to `catalog_products` with a real `foreignId()->constrained()`.
 * `subscription_periods`, `subscription_changes` and `usage_records` all hang
 * off `service_instances.id` via `service_id` — NOT off hosting accounts —
 * so the periods are seeded per service instance even though the brief phrased
 * it as "per hosting account". The migration wins.
 *
 * FOREIGN KEYS ARE RESOLVED LAZILY AT RUN TIME
 * --------------------------------------------
 * Nothing here assumes an id. Customers are found through
 * `users.email LIKE 'client%'`, products/servers by name, resource types by
 * slug and inventory assets by `asset_tag`, every time `run()` executes. The
 * dev database already contains an operator-created customer (id 5), so demo
 * customer ids start at 6 — hard-coded ids would silently mis-link.
 *
 * ENUM VALUES COME FROM THE MIGRATIONS
 * ------------------------------------
 * sqlite compiles Laravel enums into CHECK constraints, so a guessed value is
 * a hard failure. Verbatim, from the migrations:
 *
 *   hosting_accounts.status        pending|active|suspended|terminated
 *   service_instances.status       pending|provisioning|active|suspended|terminated|cancelled
 *   subscription_periods.billing_cycle
 *                                  free|one_time|hourly|daily|monthly|quarterly|
 *                                  semi_annual|annual|biennial|triennial|usage_based|custom
 *   subscription_periods.status    active|expired|cancelled|upgraded|downgraded
 *   subscription_changes.change_type
 *                                  upgrade|downgrade|renewal|cancellation|addon
 *   usage_records.metric           disk_bytes|bandwidth_bytes|cpu_seconds|memory_bytes|
 *                                  iops|network_packets|license_seat_hours
 *   usage_records.source           adapter_poll|api_webhook|manual|estimated
 *   licenses.license_type          windows|cpanel|plesk|litespeed|cloudlinux|
 *                                  directadmin|virtualizor|solusvm|other
 *   licenses.status                active|expired|revoked|pending
 *   license_assignments.assigned_to_type
 *                                  service|customer|server
 *   ssl_certificates.certificate_type
 *                                  single|wildcard|multidomain
 *   ssl_certificates.status        active|pending|expired|revoked|failed
 *   catalog_products.product_type  shared_hosting|reseller|vps|dedicated|domain|
 *                                  addon|bundle|license|other
 *   catalog_products.provisioning_method
 *                                  manual|cpanel|plesk|directadmin|proxmox|vmware|
 *                                  hyperv|solusvm|virtualizor|docker|kubernetes|api|custom_script
 *   catalog_products.billing_model one_time|recurring|usage_based|tiered
 *
 * Note `subscription_periods.billing_cycle` is WIDER than
 * `products.billing_cycle` (which has no hourly/daily/usage_based/custom) —
 * the two enums are not interchangeable.
 *
 * IDEMPOTENCY
 * -----------
 * Every write goes through `seedRowOnce()` (firstOrCreate on the table's
 * natural key from `DummyDataConfig::NATURAL_KEYS`). Nothing is ever updated,
 * so a re-run cannot churn a row and an operator edit survives. That matters
 * here because several payloads carry relative dates (`now()->subMonths(...)`)
 * which would otherwise be rewritten on every run.
 *
 * DOMAINS
 * -------
 * All demo hostnames use the reserved `.test` TLD (RFC 6761) so nothing in the
 * demo data can ever resolve to, or be confused with, a real customer domain.
 */
class ServiceSeeder extends Seeder
{
    use WithIdempotentSeed;

    /** Prefix shared by every human-readable key this seeder owns. */
    private const PREFIX = 'DEMO';

    public function run(): void
    {
        $customers = $this->customerIds();

        if ($customers === []) {
            $this->command?->warn('ServiceSeeder: no customers found — run CustomerSeeder first. Nothing seeded.');

            return;
        }

        $this->seedHostingAccounts($customers);

        $services = $this->seedServiceInstances($customers);

        if ($services !== []) {
            $this->seedSubscriptionPeriods($services);
            $this->seedUsageRecords($services);
        }

        $this->seedLicenses($services);
        $this->seedSslCertificates($customers);

        $this->command?->info(sprintf(
            'ServiceSeeder: %d hosting_accounts, %d service_instances, %d subscription_periods, '
            .'%d subscription_changes, %d usage_records, %d licenses, %d license_assignments, %d ssl_certificates.',
            $this->countOf('hosting_accounts'),
            $this->countOf('service_instances'),
            $this->countOf('subscription_periods'),
            $this->countOf('subscription_changes'),
            $this->countOf('usage_records'),
            $this->countOf('licenses'),
            $this->countOf('license_assignments'),
            $this->countOf('ssl_certificates'),
        ));
    }

    // -----------------------------------------------------------------
    // hosting_accounts
    // -----------------------------------------------------------------

    /**
     * Panel-side accounts: six domains spread over the demo customers, the
     * demo product catalog and the demo server fleet, covering three of the
     * four `status` members (active / suspended / pending). `terminated` is
     * deliberately left out — a terminated account has no panel presence and
     * would only add noise to the demo UI.
     *
     * `product_id` and `server_id` are plain unsigned columns with NO foreign
     * key constraint, so a stale id would fail silently rather than error;
     * both are resolved by name at run time and the row is skipped when the
     * product cannot be found.
     *
     * @param  list<int>  $customers
     */
    private function seedHostingAccounts(array $customers): void
    {
        $products = $this->productIds();
        $servers = $this->serverIds();

        $plan = [
            [
                'username' => 'demoshop',
                'domain' => 'demoshop.test',
                'product' => 'Demo Starter Shared Hosting',
                'server' => 'web01.demo.example',
                'status' => 'active',
                'disk_quota' => 10240,
                'disk_used' => 3872,
                'bandwidth_quota' => 102400,
                'bandwidth_used' => 41230,
                'due_months' => 1,
            ],
            [
                'username' => 'demoblog',
                'domain' => 'demoblog.test',
                'product' => 'Demo Business Shared Hosting',
                'server' => 'web01.demo.example',
                'status' => 'active',
                'disk_quota' => 51200,
                'disk_used' => 18944,
                'bandwidth_quota' => 512000,
                'bandwidth_used' => 210500,
                'due_months' => 2,
            ],
            [
                'username' => 'demoagency',
                'domain' => 'demoagency.test',
                'product' => 'Demo Reseller Bronze',
                'server' => 'web02.demo.example',
                'status' => 'active',
                'disk_quota' => 102400,
                'disk_used' => 47311,
                'bandwidth_quota' => 1024000,
                'bandwidth_used' => 388904,
                'due_months' => 3,
            ],
            [
                'username' => 'demovps',
                'domain' => 'demovps.test',
                'product' => 'Demo Cloud VPS 2GB',
                'server' => 'vps01.demo.example',
                'status' => 'active',
                'disk_quota' => 40960,
                'disk_used' => 12200,
                'bandwidth_quota' => 2048000,
                'bandwidth_used' => 640000,
                'due_months' => 1,
            ],
            [
                'username' => 'demolab',
                'domain' => 'demolab.test',
                'product' => 'Demo Cloud VPS 8GB',
                'server' => 'vps02.demo.example',
                'status' => 'suspended',
                'disk_quota' => 163840,
                'disk_used' => 158900,
                'bandwidth_quota' => 4096000,
                'bandwidth_used' => 4096000,
                'due_months' => -1,
            ],
            [
                'username' => 'demoarchive',
                'domain' => 'demoarchive.test',
                'product' => 'Demo Legacy Hosting Pack',
                'server' => 'web02.demo.example',
                'status' => 'pending',
                'disk_quota' => 5120,
                'disk_used' => 0,
                'bandwidth_quota' => 51200,
                'bandwidth_used' => 0,
                'due_months' => 1,
            ],
            [
                'username' => 'demodedi',
                'domain' => 'demodedi.test',
                'product' => 'Demo Dedicated E3 Server',
                'server' => 'vps01.demo.example',
                'status' => 'active',
                'disk_quota' => 1024000,
                'disk_used' => 204800,
                'bandwidth_quota' => 10240000,
                'bandwidth_used' => 1536000,
                'due_months' => 1,
            ],
            [
                'username' => 'demoaddon',
                'domain' => 'demoaddon.test',
                'product' => 'Demo SSL & Backup Addon',
                'server' => 'web01.demo.example',
                'status' => 'active',
                'disk_quota' => 20480,
                'disk_used' => 6144,
                'bandwidth_quota' => 204800,
                'bandwidth_used' => 81920,
                'due_months' => 6,
            ],
        ];

        foreach (array_values($plan) as $index => $row) {
            $productId = $products[$row['product']] ?? null;

            if ($productId === null) {
                continue;
            }

            $suspended = $row['status'] === 'suspended';

            $this->seedRowOnce('hosting_accounts', [
                'customer_id' => $customers[$index % count($customers)],
                'product_id' => $productId,
                'server_id' => $servers[$row['server']] ?? null,
                'order_id' => null,
                'username' => $row['username'],
                'domain' => $row['domain'],
                'disk_quota' => $row['disk_quota'],
                'disk_used' => $row['disk_used'],
                'bandwidth_quota' => $row['bandwidth_quota'],
                'bandwidth_used' => $row['bandwidth_used'],
                'panel_account_id' => strtoupper(self::PREFIX).'-PANEL-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'username_prefix' => substr($row['username'], 0, 4),
                'password' => null,
                'status' => $row['status'],
                'next_due_date' => now()->addMonths($row['due_months'])->toDateString(),
                'suspended_reason' => $suspended ? 'Demo data: disk and bandwidth quota exhausted.' : null,
                'suspended_at' => $suspended ? now()->subDays(9) : null,
            ]);
        }
    }

    // -----------------------------------------------------------------
    // catalog_products + service_instances
    // -----------------------------------------------------------------

    /**
     * `service_instances.catalog_product_id` is a constrained foreign key, so
     * a catalog row must exist before any service can be written.
     *
     * `catalog_products` is owned by the product-extras seeder, which may or
     * may not have run yet — the two are allowed to run in either order. So
     * the catalog entry is resolved BY NAME first (lowest id wins, which keeps
     * the choice deterministic when several rows share a name) and only
     * created as a `DEMO-CAT-*` fallback when nothing matches. That way this
     * seeder never duplicates a catalog row the other seeder already owns, and
     * never depends on it having run.
     *
     * @return array<string, int> logical key => catalog_products.id
     */
    private function catalogProductIds(): array
    {
        $catalog = [
            'shared_start' => ['DEMO-CAT-SHARED-START', 'Demo Starter Shared Hosting', 'shared_hosting', 'cpanel', 'recurring', true],
            'reseller_bronze' => ['DEMO-CAT-RESELLER-BRZ', 'Demo Reseller Bronze', 'reseller', 'cpanel', 'recurring', true],
            'vps_2gb' => ['DEMO-CAT-VPS-2GB', 'Demo Cloud VPS 2GB', 'vps', 'virtualizor', 'recurring', false],
            'vps_8gb' => ['DEMO-CAT-VPS-8GB', 'Demo Cloud VPS 8GB', 'vps', 'virtualizor', 'recurring', false],
        ];

        $ids = [];
        $sort = 0;

        foreach ($catalog as $key => [$sku, $name, $type, $method, $model, $requiresDomain]) {
            $sort++;

            $existing = DB::table('catalog_products')
                ->where('name', $name)
                ->orderBy('id')
                ->value('id');

            if ($existing !== null) {
                $ids[$key] = (int) $existing;

                continue;
            }

            $ids[$key] = (int) $this->seedRowOnce('catalog_products', [
                'sku' => $sku,
                'name' => $name,
                'category_id' => null,
                'description' => 'Demo catalog entry backing the seeded service instances.',
                'product_type' => $type,
                'provisioning_method' => $method,
                'provisioning_config' => null,
                'billing_model' => $model,
                'require_domain' => $requiresDomain,
                'show_in_order' => true,
                'only_admin' => false,
                'sort_order' => $sort,
                'status' => 'active',
                'version' => 1,
            ]);
        }

        return $ids;
    }

    /**
     * Billing-side services. Four instances covering three `status` members
     * (active / suspended / provisioning) and mirroring four of the hosting
     * accounts above, so the demo tells one coherent story across both models.
     *
     * @param  list<int>  $customers
     * @return array<string, array{id:int, customer_id:int, cycle:string, amount:float}>
     */
    private function seedServiceInstances(array $customers): array
    {
        $catalog = $this->catalogProductIds();
        $servers = $this->serverIds();

        $plan = [
            [
                'tag' => self::PREFIX.'-SVC-0001',
                'sku' => 'shared_start',
                'username' => 'demoshop',
                'domain' => 'demoshop.test',
                'server' => 'web01.demo.example',
                'method' => 'cpanel',
                'status' => 'active',
                'cycle' => 'monthly',
                'amount' => 499.00,
            ],
            [
                'tag' => self::PREFIX.'-SVC-0002',
                'sku' => 'reseller_bronze',
                'username' => 'demoagency',
                'domain' => 'demoagency.test',
                'server' => 'web02.demo.example',
                'method' => 'cpanel',
                'status' => 'active',
                'cycle' => 'annual',
                'amount' => 11988.00,
            ],
            [
                'tag' => self::PREFIX.'-SVC-0003',
                'sku' => 'vps_2gb',
                'username' => 'demovps',
                'domain' => 'demovps.test',
                'server' => 'vps01.demo.example',
                'method' => 'virtualizor',
                'status' => 'active',
                'cycle' => 'quarterly',
                'amount' => 3597.00,
            ],
            [
                'tag' => self::PREFIX.'-SVC-0004',
                'sku' => 'vps_8gb',
                'username' => 'demolab',
                'domain' => 'demolab.test',
                'server' => 'vps02.demo.example',
                'method' => 'virtualizor',
                'status' => 'suspended',
                'cycle' => 'monthly',
                'amount' => 2999.00,
            ],
            [
                'tag' => self::PREFIX.'-SVC-0005',
                'sku' => 'shared_start',
                'username' => 'demoblog',
                'domain' => 'demoblog.test',
                'server' => 'web01.demo.example',
                'method' => 'cpanel',
                'status' => 'active',
                'cycle' => 'monthly',
                'amount' => 499.00,
            ],
            [
                'tag' => self::PREFIX.'-SVC-0006',
                'sku' => 'vps_2gb',
                'username' => 'demopro',
                'domain' => 'demopro.test',
                'server' => 'vps01.demo.example',
                'method' => 'virtualizor',
                'status' => 'active',
                'cycle' => 'quarterly',
                'amount' => 3597.00,
            ],
            [
                'tag' => self::PREFIX.'-SVC-0007',
                'sku' => 'reseller_bronze',
                'username' => 'demoslvr',
                'domain' => 'demoslvr.test',
                'server' => 'web02.demo.example',
                'method' => 'cpanel',
                'status' => 'provisioning',
                'cycle' => 'semi_annual',
                'amount' => 7500.00,
            ],
            [
                'tag' => self::PREFIX.'-SVC-0008',
                'sku' => 'vps_8gb',
                'username' => 'demotest',
                'domain' => 'demotest.test',
                'server' => 'vps02.demo.example',
                'method' => 'virtualizor',
                'status' => 'active',
                'cycle' => 'annual',
                'amount' => 47999.00,
            ],
        ];

        $services = [];

        foreach (array_values($plan) as $index => $row) {
            $catalogId = $catalog[$row['sku']] ?? null;

            if ($catalogId === null) {
                continue;
            }

            $customerId = $customers[$index % count($customers)];
            $suspended = $row['status'] === 'suspended';

            $id = (int) $this->seedRowOnce('service_instances', [
                'customer_id' => $customerId,
                'catalog_product_id' => $catalogId,
                'order_id' => null,
                'server_id' => $servers[$row['server']] ?? null,
                'service_tag' => $row['tag'],
                'username' => $row['username'],
                'domain' => $row['domain'],
                'password_hash' => null,
                'provisioning_method' => $row['method'],
                'provisioning_config' => json_encode([
                    'package' => $row['tag'],
                    'shell' => false,
                ]),
                'provisioning_adapter_id' => null,
                'external_id' => strtolower($row['tag']).'@demo',
                'status' => $row['status'],
                'suspension_reason' => $suspended ? 'Demo data: unpaid renewal invoice.' : null,
                'suspended_at' => $suspended ? now()->subDays(9) : null,
                'terminated_at' => null,
                'next_billing_date' => now()->addMonth()->toDateString(),
            ]);

            $services[$row['tag']] = [
                'id' => $id,
                'customer_id' => $customerId,
                'cycle' => $row['cycle'],
                'amount' => $row['amount'],
            ];
        }

        return $services;
    }

    // -----------------------------------------------------------------
    // subscription_periods + subscription_changes
    // -----------------------------------------------------------------

    /**
     * Two periods per service: the previous term (`expired`) and the current
     * one (`active`), chained through `parent_period_id`. That shape is what
     * makes a `renewal` change row meaningful — it has a real from/to pair to
     * point at.
     *
     * Period length follows the service's own billing cycle so the dates stay
     * internally consistent (a quarterly service gets 3-month terms).
     *
     * @param  array<string, array{id:int, customer_id:int, cycle:string, amount:float}>  $services
     */
    private function seedSubscriptionPeriods(array $services): void
    {
        $periods = [];

        foreach ($services as $tag => $service) {
            $months = $this->cycleMonths($service['cycle']);
            $currentStart = now()->startOfMonth()->subMonths($months);
            $previousStart = (clone $currentStart)->subMonths($months);

            $previousId = (int) $this->seedRowOnce('subscription_periods', [
                'service_id' => $service['id'],
                'billing_cycle' => $service['cycle'],
                'start_date' => $previousStart->toDateString(),
                'end_date' => (clone $currentStart)->subDay()->toDateString(),
                'next_invoice_date' => $currentStart->toDateString(),
                'amount' => $service['amount'],
                'currency' => 'INR',
                'tax_rate' => 18.00,
                'status' => 'expired',
                'parent_period_id' => null,
            ]);

            $currentEnd = (clone $currentStart)->addMonths($months)->subDay();

            $currentId = (int) $this->seedRowOnce('subscription_periods', [
                'service_id' => $service['id'],
                'billing_cycle' => $service['cycle'],
                'start_date' => $currentStart->toDateString(),
                'end_date' => $currentEnd->toDateString(),
                'next_invoice_date' => (clone $currentEnd)->addDay()->toDateString(),
                'amount' => $service['amount'],
                'currency' => 'INR',
                'tax_rate' => 18.00,
                'status' => 'active',
                'parent_period_id' => $previousId,
            ]);

            $periods[$tag] = [
                'previous' => $previousId,
                'current' => $currentId,
                'start' => $currentStart,
            ];
        }

        $this->seedSubscriptionChanges($services, $periods);
    }

    /**
     * Three lifecycle events, one per change type that a demo dataset actually
     * benefits from: a straight `renewal`, an `upgrade` carrying proration, and
     * an `addon`. `downgrade` and `cancellation` are skipped — they would need
     * matching terminated/cancelled services to be honest.
     *
     * @param  array<string, array{id:int, customer_id:int, cycle:string, amount:float}>  $services
     * @param  array<string, array{previous:int, current:int, start:Carbon}>  $periods
     */
    private function seedSubscriptionChanges(array $services, array $periods): void
    {
        $plan = [
            [self::PREFIX.'-SVC-0001', 'renewal', 0.00, 499.00, null],
            [self::PREFIX.'-SVC-0002', 'upgrade', 1250.00, 4400.00, 42],
            [self::PREFIX.'-SVC-0003', 'addon', 0.00, 350.00, null],
        ];

        foreach ($plan as [$tag, $type, $credit, $charge, $prorationDays]) {
            if (! isset($services[$tag], $periods[$tag])) {
                continue;
            }

            $this->seedRowOnce('subscription_changes', [
                'service_id' => $services[$tag]['id'],
                'from_subscription_period_id' => $periods[$tag]['previous'],
                'to_subscription_period_id' => $periods[$tag]['current'],
                'change_type' => $type,
                'credit_amount' => $credit,
                'charge_amount' => $charge,
                'proration_days' => $prorationDays,
                'invoice_id' => null,
                'effective_date' => $periods[$tag]['start']->toDateString(),
            ]);
        }
    }

    // -----------------------------------------------------------------
    // usage_records
    // -----------------------------------------------------------------

    /**
     * Two metered readings per service — disk and bandwidth — for the current
     * billing month. `resource_type_id` is a constrained FK, so the resource
     * type is looked up by slug (`storage`, `bandwidth`) and the row is skipped
     * if the resource catalog has not been seeded yet.
     *
     * The natural key is (service_id, resource_type_id, metric,
     * billing_period_start), and `billing_period_start` is pinned to the start
     * of the current month rather than "today", so re-running inside the same
     * month matches the existing row instead of inserting a new one.
     *
     * @param  array<string, array{id:int, customer_id:int, cycle:string, amount:float}>  $services
     */
    private function seedUsageRecords(array $services): void
    {
        $resourceTypes = $this->resourceTypeIds();
        $periodStart = now()->startOfMonth();
        $periodEnd = now()->endOfMonth();

        $metrics = [
            ['slug' => 'storage', 'metric' => 'disk_bytes', 'unit' => 'bytes', 'base' => 3_221_225_472],
            ['slug' => 'bandwidth', 'metric' => 'bandwidth_bytes', 'unit' => 'bytes', 'base' => 42_949_672_960],
        ];

        $index = 0;

        foreach ($services as $service) {
            $index++;

            foreach ($metrics as $metric) {
                $resourceTypeId = $resourceTypes[$metric['slug']] ?? null;

                if ($resourceTypeId === null) {
                    continue;
                }

                $this->seedRowOnce('usage_records', [
                    'service_id' => $service['id'],
                    'resource_type_id' => $resourceTypeId,
                    'metric' => $metric['metric'],
                    'value' => $metric['base'] * $index,
                    'unit' => $metric['unit'],
                    'recorded_at' => $periodStart->copy()->addDays(14)->setTime(2, 0),
                    'source' => 'adapter_poll',
                    'billing_period_start' => $periodStart->toDateString(),
                    'billing_period_end' => $periodEnd->toDateString(),
                    'invoiced' => false,
                    'invoice_item_id' => null,
                ]);
            }
        }
    }

    // -----------------------------------------------------------------
    // licenses + license_assignments
    // -----------------------------------------------------------------

    /**
     * `licenses.inventory_asset_id` is a UNIQUE constrained FK — one license
     * per asset, and a license cannot exist without one. The two demo server
     * assets (`DEMO-SRV-0001/0002`) therefore carry one control-panel license
     * each; assets already holding a license are skipped so a re-run (or a
     * hand-added license) can never trip the unique index.
     *
     * Assignments cover two of the three `assigned_to_type` members: `server`
     * (the physical node the license is installed on) and `service` (the
     * customer-facing service consuming a seat). `seats_available` is written
     * as seats minus the assignments made here, so the numbers add up.
     *
     * @param  array<string, array{id:int, customer_id:int, cycle:string, amount:float}>  $services
     */
    private function seedLicenses(array $services): void
    {
        $assets = $this->inventoryAssetIds();
        $servers = $this->serverIds();

        $plan = [
            [
                'asset_tag' => self::PREFIX.'-SRV-0001',
                'license_type' => 'cpanel',
                'license_key' => self::PREFIX.'-LIC-CPANEL-0001',
                'vendor' => 'cPanel L.L.C.',
                'seats' => 5,
                'cost' => 4250.00,
                'server' => 'web01.demo.example',
                'service' => self::PREFIX.'-SVC-0001',
            ],
            [
                'asset_tag' => self::PREFIX.'-SRV-0002',
                'license_type' => 'windows',
                'license_key' => self::PREFIX.'-LIC-WINDOWS-0001',
                'vendor' => 'Microsoft',
                'seats' => 4,
                'cost' => 9800.00,
                'server' => 'web02.demo.example',
                'service' => self::PREFIX.'-SVC-0004',
            ],
            [
                'asset_tag' => self::PREFIX.'-SWT-0001',
                'license_type' => 'plesk',
                'license_key' => self::PREFIX.'-LIC-PLESK-0001',
                'vendor' => 'Plesk International GmbH',
                'seats' => 10,
                'cost' => 6500.00,
                'server' => 'web01.demo.example',
                'service' => self::PREFIX.'-SVC-0005',
            ],
            [
                'asset_tag' => self::PREFIX.'-SWT-0002',
                'license_type' => 'litespeed',
                'license_key' => self::PREFIX.'-LIC-LITESPEED-0001',
                'vendor' => 'LiteSpeed Technologies',
                'seats' => 8,
                'cost' => 3200.00,
                'server' => 'vps01.demo.example',
                'service' => self::PREFIX.'-SVC-0006',
            ],
            [
                'asset_tag' => self::PREFIX.'-RTR-0001',
                'license_type' => 'cloudlinux',
                'license_key' => self::PREFIX.'-LIC-CLOUDLINUX-0001',
                'vendor' => 'CloudLinux Inc.',
                'seats' => 6,
                'cost' => 4800.00,
                'server' => 'vps02.demo.example',
                'service' => self::PREFIX.'-SVC-0007',
            ],
        ];

        foreach ($plan as $row) {
            $assetId = $assets[$row['asset_tag']] ?? null;

            if ($assetId === null) {
                continue;
            }

            $alreadyLicensed = DB::table('licenses')
                ->where('inventory_asset_id', $assetId)
                ->where('license_key', '!=', $row['license_key'])
                ->exists();

            if ($alreadyLicensed) {
                continue;
            }

            $targets = [];

            if (isset($servers[$row['server']])) {
                $targets[] = ['server', $servers[$row['server']], 'Installed on the host node.'];
            }

            if (isset($services[$row['service']])) {
                $targets[] = ['service', $services[$row['service']]['id'], 'Seat consumed by a customer service.'];
            }

            $licenseId = (int) $this->seedRowOnce('licenses', [
                'inventory_asset_id' => $assetId,
                'license_type' => $row['license_type'],
                'license_key' => $row['license_key'],
                'seats' => $row['seats'],
                'seats_available' => max(0, $row['seats'] - count($targets)),
                'vendor' => $row['vendor'],
                'purchase_order' => self::PREFIX.'-PO-'.substr($row['license_key'], -4),
                'expiry_date' => now()->addMonths(10)->toDateString(),
                'renewal_date' => now()->addMonths(9)->toDateString(),
                'cost' => $row['cost'],
                'status' => 'active',
                'notes' => 'Demo license attached to inventory asset '.$row['asset_tag'].'.',
            ]);

            foreach ($targets as [$type, $targetId, $note]) {
                $this->seedRowOnce('license_assignments', [
                    'license_id' => $licenseId,
                    'assigned_to_type' => $type,
                    'assigned_to_id' => $targetId,
                    'assigned_at' => now()->subMonths(2)->startOfDay(),
                    'released_at' => null,
                    'notes' => $note,
                ]);
            }
        }
    }

    // -----------------------------------------------------------------
    // ssl_certificates
    // -----------------------------------------------------------------

    /**
     * Four certificates over the reserved `.test` TLD, covering four of the
     * five `status` members and two of the three `certificate_type` members.
     *
     * DATE RULES
     * ----------
     * No `issue_date` is ever in the future — a certificate cannot be issued
     * tomorrow. The valid ones expire in the future; exactly one row is left
     * genuinely expired (issued and expired in the past) because the expiry
     * dashboards and the renewal reminder job need a negative case to render.
     * The `pending` row has no dates at all, which is what an in-flight
     * issuance actually looks like.
     *
     * @param  list<int>  $customers
     */
    private function seedSslCertificates(array $customers): void
    {
        $plan = [
            [
                'domain_name' => 'secure.demoshop.test',
                'certificate_type' => 'single',
                'provider' => "Let's Encrypt",
                'status' => 'active',
                'issued_months_ago' => 2,
                'expires_in_months' => 1,
                'notes' => 'Auto-renewing DV certificate.',
            ],
            [
                'domain_name' => 'demoagency.test',
                'certificate_type' => 'wildcard',
                'provider' => 'Sectigo',
                'status' => 'active',
                'issued_months_ago' => 4,
                'expires_in_months' => 8,
                'notes' => 'Wildcard covering all reseller subdomains.',
            ],
            [
                'domain_name' => 'mail.demoblog.test',
                'certificate_type' => 'single',
                'provider' => "Let's Encrypt",
                'status' => 'pending',
                'issued_months_ago' => null,
                'expires_in_months' => null,
                'notes' => 'Domain control validation in progress.',
            ],
            [
                'domain_name' => 'legacy.demolab.test',
                'certificate_type' => 'single',
                'provider' => 'Sectigo',
                'status' => 'expired',
                'issued_months_ago' => 15,
                'expires_in_months' => -3,
                'notes' => 'Left expired on purpose so the expiry report has a negative case.',
            ],
            [
                'domain_name' => 'portal.demodedi.test',
                'certificate_type' => 'multidomain',
                'provider' => 'Sectigo',
                'status' => 'active',
                'issued_months_ago' => 6,
                'expires_in_months' => 6,
                'notes' => 'Multi-domain SAN cert covering portal and api labels.',
            ],
        ];

        foreach (array_values($plan) as $index => $row) {
            $issueDate = $row['issued_months_ago'] === null
                ? null
                : now()->subMonths($row['issued_months_ago'])->toDateString();

            $expiryDate = $row['expires_in_months'] === null
                ? null
                : now()->addMonths($row['expires_in_months'])->toDateString();

            $this->seedRowOnce('ssl_certificates', [
                'customer_id' => $customers[$index % count($customers)],
                'domain_name' => $row['domain_name'],
                'certificate_type' => $row['certificate_type'],
                'provider' => $row['provider'],
                'status' => $row['status'],
                'issue_date' => $issueDate,
                'expiry_date' => $expiryDate,
                'order_id' => null,
                'notes' => $row['notes'],
            ]);
        }
    }

    // -----------------------------------------------------------------
    // lazy foreign-key resolution
    // -----------------------------------------------------------------

    /**
     * Demo customers, preferred over operator-created ones so the demo data
     * clusters on the accounts CustomerSeeder owns. Falls back to whatever
     * customers exist.
     *
     * @return list<int>
     */
    private function customerIds(): array
    {
        $demo = DB::table('customers')
            ->join('users', 'users.id', '=', 'customers.user_id')
            ->where('users.email', 'like', 'client%@example.com')
            ->orderBy('customers.id')
            ->pluck('customers.id')
            ->all();

        if ($demo !== []) {
            return array_map('intval', $demo);
        }

        return array_map('intval', DB::table('customers')->orderBy('id')->pluck('id')->all());
    }

    /** @return array<string, int> name => id */
    private function productIds(): array
    {
        return DB::table('products')->pluck('id', 'name')
            ->map(fn ($id) => (int) $id)->all();
    }

    /** @return array<string, int> name => id */
    private function serverIds(): array
    {
        return DB::table('servers')->pluck('id', 'name')
            ->map(fn ($id) => (int) $id)->all();
    }

    /** @return array<string, int> slug => id */
    private function resourceTypeIds(): array
    {
        return DB::table('resource_types')->pluck('id', 'slug')
            ->map(fn ($id) => (int) $id)->all();
    }

    /** @return array<string, int> asset_tag => id */
    private function inventoryAssetIds(): array
    {
        return DB::table('inventory_assets')->pluck('id', 'asset_tag')
            ->map(fn ($id) => (int) $id)->all();
    }

    /** Months spanned by one billing cycle, for period date arithmetic. */
    private function cycleMonths(string $cycle): int
    {
        return match ($cycle) {
            'quarterly' => 3,
            'semi_annual' => 6,
            'annual' => 12,
            'biennial' => 24,
            'triennial' => 36,
            default => 1,
        };
    }

    private function countOf(string $table): int
    {
        return (int) DB::table($table)->count();
    }
}
