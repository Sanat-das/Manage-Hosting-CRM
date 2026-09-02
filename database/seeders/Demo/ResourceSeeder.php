<?php

namespace Database\Seeders\Demo;

use Database\Seeders\Demo\Traits\WithIdempotentSeed;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo seeder for the resource & provisioning domain.
 *
 * TABLES OWNED BY THIS SEEDER
 * ---------------------------
 * - `resource_types`         (18 rows, CONFIG - created by migration 2026_07_30_120090)
 * - `provisioning_adapters`  (5 rows,  CONFIG - created by the same migration)
 * - `resource_pools`         (demo, DummyDataConfig::ROWS)
 * - `resource_allocations`   (demo, DummyDataConfig::ROWS)
 * - `provisioning_events`    (demo, 2 per adapter)
 *
 * CONFIG ROWS ARE NEVER RECREATED
 * -------------------------------
 * `resource_types` and `provisioning_adapters` are seeded by the migration
 * itself and are treated as human-editable configuration. They are asserted
 * with `seedRowOnce()` (firstOrCreate semantics) so an operator's edits to an
 * existing row survive a re-run, and a wiped table is repaired back to the
 * migration's baseline of 18 / 5 rows.
 *
 * ENUM VALUES ARE READ FROM THE MIGRATION, NOT GUESSED
 * ----------------------------------------------------
 * SQLite compiles Laravel enums into CHECK constraints, so an invented value
 * throws `CHECK constraint failed` on the test connection even though MySQL
 * would silently accept it. The allowed values below are copied verbatim from
 * `database/migrations/2026_07_30_120090_create_resource_provisioning_tables.php`:
 *
 *   resource_types.category         capacity | discrete
 *   resource_pools.pool_type        hypervisor | network | storage | license
 *   resource_pools.status           active | maintenance | retired
 *   resource_allocations.status     allocated | released
 *   provisioning_adapters.method    manual | cpanel | plesk | directadmin |
 *                                   proxmox | vmware | hyperv | solusvm |
 *                                   virtualizor | docker | kubernetes | api |
 *                                   custom_script
 *   provisioning_events.status      pending | processing | completed | failed | retrying
 *   provisioning_events.priority    low | normal | high | critical
 *
 * SCHEMA NOTES THAT SHAPE THE DATA
 * --------------------------------
 * - `resource_pools` has NO `resource_type_id` column. A pool is linked to a
 *   resource type semantically, through its `pool_type` + `unit`, which this
 *   seeder mirrors from the matching `resource_types.unit`.
 * - `resource_allocations.service_id`, `pool_id`, `inventory_asset_id`,
 *   `resource_pools.server_id` and `.datacenter_id` are plain unsigned
 *   integers with NO foreign key constraint, so allocations can be seeded
 *   before `service_instances` / `servers` exist. `resource_type_id` IS a real
 *   FK and is always resolved from `resource_types`.
 * - `provisioning_events` has no adapter foreign key; the owning adapter is
 *   recorded inside `payload.adapter`.
 * - `provisioning_events` has `created_at` only (no `updated_at`), and
 *   `resource_allocations` has no timestamp columns at all. The trait detects
 *   this at runtime, so nothing here needs to special-case it.
 */
class ResourceSeeder extends Seeder
{
    use WithIdempotentSeed;

    /** Baseline resource types owned by the migration, keyed by slug. */
    private const RESOURCE_TYPES = [
        ['name' => 'CPU Core', 'slug' => 'cpu_core', 'category' => 'capacity', 'unit' => 'cores', 'description' => 'Processing cores allocated to a service'],
        ['name' => 'CPU Speed', 'slug' => 'cpu_speed', 'category' => 'capacity', 'unit' => 'MHz', 'description' => 'Total CPU speed in megahertz'],
        ['name' => 'RAM', 'slug' => 'ram', 'category' => 'capacity', 'unit' => 'MB', 'description' => 'Random access memory in megabytes'],
        ['name' => 'Storage', 'slug' => 'storage', 'category' => 'capacity', 'unit' => 'MB', 'description' => 'Primary disk storage in megabytes'],
        ['name' => 'Bandwidth', 'slug' => 'bandwidth', 'category' => 'capacity', 'unit' => 'GB', 'description' => 'Monthly data transfer allowance in gigabytes'],
        ['name' => 'Public IPv4', 'slug' => 'public_ipv4', 'category' => 'capacity', 'unit' => 'count', 'description' => 'Public IPv4 addresses'],
        ['name' => 'Public IPv6', 'slug' => 'public_ipv6', 'category' => 'capacity', 'unit' => 'count', 'description' => 'Public IPv6 addresses or /64 blocks'],
        ['name' => 'Backup Storage', 'slug' => 'backup_storage', 'category' => 'capacity', 'unit' => 'MB', 'description' => 'Backup storage space in megabytes'],
        ['name' => 'GPU Memory', 'slug' => 'gpu_memory', 'category' => 'capacity', 'unit' => 'MB', 'description' => 'GPU VRAM in megabytes (GPU instances)'],
        ['name' => 'Email Accounts', 'slug' => 'email_accounts', 'category' => 'discrete', 'unit' => 'count', 'description' => 'Mailbox accounts per hosting plan'],
        ['name' => 'Databases', 'slug' => 'databases', 'category' => 'discrete', 'unit' => 'count', 'description' => 'Database instances (MySQL, PostgreSQL, etc.)'],
        ['name' => 'FTP Accounts', 'slug' => 'ftp_accounts', 'category' => 'discrete', 'unit' => 'count', 'description' => 'FTP/SFTP user accounts'],
        ['name' => 'Subdomains', 'slug' => 'subdomains', 'category' => 'discrete', 'unit' => 'count', 'description' => 'Allowed subdomains per plan'],
        ['name' => 'Domains', 'slug' => 'domains', 'category' => 'discrete', 'unit' => 'count', 'description' => 'Domain names hosted under this plan'],
        ['name' => 'SSL Certificates', 'slug' => 'ssl_certificates', 'category' => 'discrete', 'unit' => 'count', 'description' => 'SSL/TLS certificates included'],
        ['name' => 'Windows License', 'slug' => 'windows_license', 'category' => 'discrete', 'unit' => 'count', 'description' => 'Windows Server licenses for dedicated/VPS'],
        ['name' => 'cPanel License', 'slug' => 'cpanel_license', 'category' => 'discrete', 'unit' => 'count', 'description' => 'cPanel/WHM licenses per server or account'],
        ['name' => 'Dedicated Server Asset', 'slug' => 'dedicated_server_asset', 'category' => 'discrete', 'unit' => 'count', 'description' => 'Physical dedicated server units'],
    ];

    /** Baseline provisioning adapters owned by the migration, keyed by name. */
    private const ADAPTERS = [
        ['name' => 'cpanel', 'adapter_class' => 'Integrations\\CPanel', 'method' => 'cpanel', 'api_endpoint_template' => 'https://{host}:2087/whm/json-api/cpanel', 'is_enabled' => 1],
        ['name' => 'plesk', 'adapter_class' => 'Integrations\\Plesk', 'method' => 'plesk', 'api_endpoint_template' => 'https://{host}:8443/enterprise/control/agent.php', 'is_enabled' => 1],
        ['name' => 'directadmin', 'adapter_class' => 'Integrations\\DirectAdmin', 'method' => 'directadmin', 'api_endpoint_template' => 'https://{host}:2222/CMD_API', 'is_enabled' => 1],
        ['name' => 'virtualizor', 'adapter_class' => 'Integrations\\Virtualizor', 'method' => 'virtualizor', 'api_endpoint_template' => 'https://{host}:4085', 'is_enabled' => 1],
        ['name' => 'custom', 'adapter_class' => 'Integrations\\CustomScript', 'method' => 'custom_script', 'api_endpoint_template' => null, 'is_enabled' => 1],
    ];

    /**
     * Demo resource pools. `type_slug` is the `resource_types` row the pool
     * meters; it is resolved to that type's `unit` and then dropped, since
     * `resource_pools` carries no `resource_type_id` column.
     */
    private const POOLS = [
        ['name' => 'Hypervisor Pool - Node A', 'pool_type' => 'hypervisor', 'type_slug' => 'cpu_core', 'total_capacity' => 128, 'status' => 'active'],
        ['name' => 'Hypervisor Pool - Node B', 'pool_type' => 'hypervisor', 'type_slug' => 'ram', 'total_capacity' => 524288, 'status' => 'active'],
        ['name' => 'Storage Pool - SSD Tier 1', 'pool_type' => 'storage', 'type_slug' => 'storage', 'total_capacity' => 8388608, 'status' => 'active'],
        ['name' => 'Network Pool - Public IPv4 /22', 'pool_type' => 'network', 'type_slug' => 'public_ipv4', 'total_capacity' => 1024, 'status' => 'active'],
        ['name' => 'License Pool - cPanel', 'pool_type' => 'license', 'type_slug' => 'cpanel_license', 'total_capacity' => 50, 'status' => 'maintenance'],
    ];

    /**
     * Allocation profile: which resource type is drawn from which pool, and
     * how much a single demo service consumes. Cycled across services.
     */
    private const ALLOCATION_PROFILE = [
        ['type_slug' => 'cpu_core', 'pool' => 'Hypervisor Pool - Node A', 'quantity' => 2, 'status' => 'allocated'],
        ['type_slug' => 'ram', 'pool' => 'Hypervisor Pool - Node B', 'quantity' => 4096, 'status' => 'allocated'],
        ['type_slug' => 'storage', 'pool' => 'Storage Pool - SSD Tier 1', 'quantity' => 51200, 'status' => 'allocated'],
        ['type_slug' => 'public_ipv4', 'pool' => 'Network Pool - Public IPv4 /22', 'quantity' => 1, 'status' => 'released'],
    ];

    /**
     * Two provisioning events per adapter (10 total), spanning the whole
     * status enum so dashboards and queue workers have realistic snapshots.
     */
    private const EVENT_PROFILE = [
        ['event_type' => 'account.create', 'status' => 'completed', 'priority' => 'high', 'attempts' => 1],
        ['event_type' => 'account.suspend', 'status' => 'pending', 'priority' => 'normal', 'attempts' => 0],
        ['event_type' => 'account.unsuspend', 'status' => 'processing', 'priority' => 'normal', 'attempts' => 1],
        ['event_type' => 'account.terminate', 'status' => 'failed', 'priority' => 'critical', 'attempts' => 3],
        ['event_type' => 'package.change', 'status' => 'retrying', 'priority' => 'low', 'attempts' => 2],
        ['event_type' => 'server.sync', 'status' => 'completed', 'priority' => 'normal', 'attempts' => 1],
        ['event_type' => 'ssl.install', 'status' => 'pending', 'priority' => 'high', 'attempts' => 0],
        ['event_type' => 'dns.zone.create', 'status' => 'completed', 'priority' => 'low', 'attempts' => 1],
        ['event_type' => 'vm.provision', 'status' => 'processing', 'priority' => 'critical', 'attempts' => 1],
        ['event_type' => 'vm.rebuild', 'status' => 'failed', 'priority' => 'normal', 'attempts' => 2],
    ];

    public function run(): void
    {
        $typeIds = $this->seedResourceTypes();
        $typeUnits = $this->resourceTypeUnits();
        $adapters = $this->seedAdapters();

        $poolIds = $this->seedPools($typeUnits);
        $this->seedAllocations($typeIds, $poolIds);
        $this->seedEvents($adapters);

        $this->report();
    }

    /**
     * Repair/assert the 18 migration-owned resource types without disturbing
     * any operator edits. Returns slug => id.
     *
     * @return array<string, int>
     */
    private function seedResourceTypes(): array
    {
        foreach (self::RESOURCE_TYPES as $type) {
            $this->seedRowOnce('resource_types', $type);
        }

        return DB::table('resource_types')->pluck('id', 'slug')->all();
    }

    /** @return array<string, string|null> slug => unit */
    private function resourceTypeUnits(): array
    {
        return DB::table('resource_types')->pluck('unit', 'slug')->all();
    }

    /**
     * Repair/assert the 5 migration-owned adapters. Returns name => id.
     *
     * @return array<string, int>
     */
    private function seedAdapters(): array
    {
        foreach (self::ADAPTERS as $adapter) {
            $this->seedRowOnce('provisioning_adapters', $adapter);
        }

        return DB::table('provisioning_adapters')->pluck('id', 'name')->all();
    }

    /**
     * @param  array<string, string|null>  $typeUnits
     * @return array<string, int> pool name => id
     */
    private function seedPools(array $typeUnits): array
    {
        $datacenterId = DB::table('datacenters')->min('id');
        $serverIds = DB::table('servers')->orderBy('id')->pluck('id')->all();

        $this->seedUpTo('resource_pools', function (int $i) use ($typeUnits, $datacenterId, $serverIds): array {
            $pool = self::POOLS[$i % count(self::POOLS)];
            $slug = $pool['type_slug'];
            unset($pool['type_slug']);

            $pool['unit'] = $typeUnits[$slug] ?? 'count';
            $pool['datacenter_id'] = $datacenterId;
            $pool['server_id'] = $serverIds[$i % max(count($serverIds), 1)] ?? null;
            $pool['parent_id'] = null;

            return $pool;
        }, max(DummyDataConfig::minRows('resource_pools'), count(self::POOLS)));

        return DB::table('resource_pools')->pluck('id', 'name')->all();
    }

    /**
     * Allocations are keyed on (service_id, resource_type_id). Services are
     * referenced by plain integer, falling back to a synthetic 1..N range
     * while `service_instances` is still empty.
     *
     * @param  array<string, int>  $typeIds
     * @param  array<string, int>  $poolIds
     */
    private function seedAllocations(array $typeIds, array $poolIds): void
    {
        $serviceIds = DB::table('service_instances')->orderBy('id')->pluck('id')->all();
        $profileSize = count(self::ALLOCATION_PROFILE);

        $this->seedUpTo('resource_allocations', function (int $i) use ($typeIds, $poolIds, $serviceIds, $profileSize): array {
            $profile = self::ALLOCATION_PROFILE[$i % $profileSize];
            $slot = intdiv($i, $profileSize);
            $serviceId = $serviceIds[$slot] ?? ($slot + 1);
            $released = $profile['status'] === 'released';

            return [
                'service_id' => $serviceId,
                'resource_type_id' => $typeIds[$profile['type_slug']],
                'pool_id' => $poolIds[$profile['pool']] ?? null,
                'inventory_asset_id' => null,
                'quantity_allocated' => $profile['quantity'],
                'allocated_at' => now()->subDays(30 - ($i % 30))->startOfHour(),
                'released_at' => $released ? now()->subDays(2)->startOfHour() : null,
                'status' => $profile['status'],
            ];
        });
    }

    /**
     * Two events per adapter. The adapter is recorded in `payload` because
     * `provisioning_events` holds no adapter foreign key; `payload` is also
     * half of the table's natural key, so it must be encoded deterministically
     * (stable key order, no volatile values) for re-runs to match.
     *
     * @param  array<string, int>  $adapters
     */
    private function seedEvents(array $adapters): void
    {
        $adapterNames = array_keys($adapters);
        $eventsPerAdapter = 2;
        $rows = [];

        foreach ($adapterNames as $a => $name) {
            for ($n = 0; $n < $eventsPerAdapter; $n++) {
                $profile = self::EVENT_PROFILE[($a * $eventsPerAdapter + $n) % count(self::EVENT_PROFILE)];
                $done = $profile['status'] === 'completed';
                $failed = in_array($profile['status'], ['failed', 'retrying'], true);
                $running = $profile['status'] === 'processing';

                $rows[] = [
                    'event_type' => $profile['event_type'],
                    'payload' => json_encode([
                        'adapter' => $name,
                        'adapter_id' => $adapters[$name],
                        'service_id' => $a + 1,
                        'sequence' => $n + 1,
                    ]),
                    'status' => $profile['status'],
                    'priority' => $profile['priority'],
                    'attempts' => $profile['attempts'],
                    'max_attempts' => 3,
                    'last_error' => $failed ? 'Adapter '.$name.' returned HTTP 500 for '.$profile['event_type'] : null,
                    'scheduled_at' => now()->subHours(12 - $a)->startOfHour(),
                    'locked_by' => $running ? 'worker-'.($a + 1) : null,
                    'locked_at' => $running ? now()->subMinutes(5)->startOfMinute() : null,
                    'completed_at' => $done ? now()->subHours(11 - $a)->startOfHour() : null,
                ];
            }
        }

        $this->seedRows('provisioning_events', $rows);
    }

    private function report(): void
    {
        foreach (['resource_types', 'provisioning_adapters', 'resource_pools', 'resource_allocations', 'provisioning_events'] as $table) {
            $this->command?->info(sprintf(
                '  %-22s %4d rows (min %d)',
                $table,
                DB::table($table)->count(),
                DummyDataConfig::minRows($table)
            ));
        }
    }
}
