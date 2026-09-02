<?php

namespace Database\Seeders\Demo;

use Database\Seeders\Demo\Traits\WithIdempotentSeed;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo infrastructure: datacenters -> racks, and server_groups -> servers
 * wired together through server_group_members.
 *
 * IDEMPOTENCY
 * -----------
 * `InitialDataSeeder` already ships one datacenter (`DC01` / "Primary DC"),
 * one rack ("Rack A1") and two server groups ("Primary cPanel Servers",
 * "VPS Nodes"). Those rows are human-visible baseline config, so this seeder
 * touches them with `seedRowOnce()` (firstOrCreate semantics) on their natural
 * keys - `datacenters.code`, `racks.(datacenter_id,name)`, `server_groups.name`.
 * Re-running never duplicates them and never overwrites local edits.
 *
 * SCHEMA FACTS THIS SEEDER RESPECTS (read from the migrations, not guessed)
 * -------------------------------------------------------------------------
 * - `servers.panel_type` is enum('cpanel','plesk','directadmin','custom').
 *   There is NO `virtualizor` member and NO `provisioning_module` column on
 *   `servers`; `provisioning_module` lives on `products`. VPS nodes therefore
 *   use the `custom` panel type, which is what the schema allows.
 *   (2026_07_30_120020_create_config_tables.php)
 * - `server_groups` has `created_at` only, `server_group_members` has no
 *   timestamps at all. `WithIdempotentSeed` resolves the real column list at
 *   runtime, so neither table gets an `updated_at` written to it.
 * - `racks.datacenter_id` is a plain unsignedInteger (no FK constraint).
 *
 * All IPv4 literals come from the RFC 5737 documentation ranges
 * (192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24) so nothing in the demo data
 * can ever route to a real host.
 */
class InfrastructureSeeder extends Seeder
{
    use WithIdempotentSeed;

    /**
     * Datacenters. The first entry MUST keep code `DC01` so it matches the row
     * `InitialDataSeeder` created instead of adding a second primary DC.
     */
    private const DATACENTERS = [
        [
            'name' => 'Primary DC',
            'code' => 'DC01',
            'address' => '1 Documentation Way',
            'city' => 'New York',
            'state' => 'NY',
            'country' => 'US',
            'timezone' => 'America/New_York',
            'status' => 'active',
        ],
        [
            'name' => 'Frankfurt Edge',
            'code' => 'DC02',
            'address' => '22 Beispielstrasse',
            'city' => 'Frankfurt',
            'state' => 'Hessen',
            'country' => 'DE',
            'timezone' => 'Europe/Berlin',
            'status' => 'active',
        ],
        [
            'name' => 'Mumbai South',
            'code' => 'DC03',
            'address' => '404 Example Marg',
            'city' => 'Mumbai',
            'state' => 'Maharashtra',
            'country' => 'IN',
            'timezone' => 'Asia/Kolkata',
            'status' => 'maintenance',
        ],
    ];

    /**
     * Racks keyed by the datacenter code they belong to. "Rack A1" under DC01
     * is the pre-existing `InitialDataSeeder` rack and is matched, not recreated.
     *
     * @var array<string, list<array{name: string, u_height: int, u_available: int, power_capacity_watts: int, status: string}>>
     */
    private const RACKS = [
        'DC01' => [
            ['name' => 'Rack A1', 'u_height' => 42, 'u_available' => 30, 'power_capacity_watts' => 8000, 'status' => 'active'],
            ['name' => 'Rack A2', 'u_height' => 42, 'u_available' => 38, 'power_capacity_watts' => 8000, 'status' => 'active'],
        ],
        'DC02' => [
            ['name' => 'Rack B1', 'u_height' => 47, 'u_available' => 41, 'power_capacity_watts' => 10000, 'status' => 'active'],
            ['name' => 'Rack B2', 'u_height' => 47, 'u_available' => 47, 'power_capacity_watts' => 10000, 'status' => 'inactive'],
        ],
        'DC03' => [
            ['name' => 'Rack C1', 'u_height' => 42, 'u_available' => 36, 'power_capacity_watts' => 6000, 'status' => 'active'],
            ['name' => 'Rack C2', 'u_height' => 42, 'u_available' => 42, 'power_capacity_watts' => 6000, 'status' => 'maintenance'],
            ['name' => 'Rack C3', 'u_height' => 42, 'u_available' => 40, 'power_capacity_watts' => 6000, 'status' => 'active'],
        ],
    ];

    /**
     * Server groups. Both names match the `InitialDataSeeder` rows exactly so
     * they are reused rather than duplicated.
     */
    private const SERVER_GROUPS = [
        [
            'name' => 'Primary cPanel Servers',
            'description' => 'Main cPanel/WHM server cluster',
            'load_balancing' => 'round_robin',
            'status' => 'active',
        ],
        [
            'name' => 'VPS Nodes',
            'description' => 'Virtualizor VPS host nodes',
            'load_balancing' => 'least_loaded',
            'status' => 'active',
        ],
    ];

    /**
     * Servers. `group` names the owning server group and `priority` is the
     * weight written to the `server_group_members` pivot.
     */
    private const SERVERS = [
        [
            'name' => 'web01.demo.example',
            'ip_address' => '192.0.2.11',
            'panel_type' => 'cpanel',
            'api_url' => 'https://192.0.2.11:2087',
            'api_username' => 'root',
            'max_accounts' => 250,
            'status' => 'active',
            'group' => 'Primary cPanel Servers',
            'priority' => 10,
        ],
        [
            'name' => 'web02.demo.example',
            'ip_address' => '192.0.2.12',
            'panel_type' => 'cpanel',
            'api_url' => 'https://192.0.2.12:2087',
            'api_username' => 'root',
            'max_accounts' => 250,
            'status' => 'active',
            'group' => 'Primary cPanel Servers',
            'priority' => 20,
        ],
        [
            'name' => 'vps01.demo.example',
            'ip_address' => '198.51.100.21',
            'panel_type' => 'custom',
            'api_url' => 'https://198.51.100.21:4085',
            'api_username' => 'apiuser',
            'max_accounts' => 60,
            'status' => 'active',
            'group' => 'VPS Nodes',
            'priority' => 10,
        ],
        [
            'name' => 'vps02.demo.example',
            'ip_address' => '203.0.113.22',
            'panel_type' => 'custom',
            'api_url' => 'https://203.0.113.22:4085',
            'api_username' => 'apiuser',
            'max_accounts' => 60,
            'status' => 'inactive',
            'group' => 'VPS Nodes',
            'priority' => 20,
        ],
    ];

    public function run(): void
    {
        $datacenterIds = $this->seedDatacenters();
        $this->seedRacks($datacenterIds);

        $groupIds = $this->seedServerGroups();
        $this->seedServers($groupIds);

        $this->report();
    }

    /**
     * @return array<string, int> datacenter code => id
     */
    private function seedDatacenters(): array
    {
        $ids = [];

        foreach (self::DATACENTERS as $datacenter) {
            $ids[$datacenter['code']] = (int) $this->seedRowOnce('datacenters', $datacenter);
        }

        return $ids;
    }

    /** @param array<string, int> $datacenterIds */
    private function seedRacks(array $datacenterIds): void
    {
        foreach (self::RACKS as $code => $racks) {
            if (! isset($datacenterIds[$code])) {
                continue;
            }

            foreach ($racks as $rack) {
                $this->seedRowOnce('racks', ['datacenter_id' => $datacenterIds[$code]] + $rack);
            }
        }
    }

    /**
     * @return array<string, int> group name => id
     */
    private function seedServerGroups(): array
    {
        $ids = [];

        foreach (self::SERVER_GROUPS as $group) {
            $ids[$group['name']] = (int) $this->seedRowOnce('server_groups', $group);
        }

        return $ids;
    }

    /** @param array<string, int> $groupIds */
    private function seedServers(array $groupIds): void
    {
        foreach (self::SERVERS as $server) {
            $groupName = $server['group'];
            $priority = $server['priority'];
            unset($server['group'], $server['priority']);

            $serverId = (int) $this->seedRow('servers', $server);

            if ($serverId === 0 || ! isset($groupIds[$groupName])) {
                continue;
            }

            // server_group_members is UNIQUE(server_group_id, server_id) and has
            // no timestamps; seedRow matches on that natural key so `priority`
            // stays correctable on a re-run.
            $this->seedRow('server_group_members', [
                'server_group_id' => $groupIds[$groupName],
                'server_id' => $serverId,
                'priority' => $priority,
            ]);
        }
    }

    private function report(): void
    {
        foreach (['datacenters', 'racks', 'server_groups', 'servers', 'server_group_members'] as $table) {
            $this->command?->info(sprintf(
                '  %-22s %3d rows (min %d)',
                $table,
                DB::table($table)->count(),
                DummyDataConfig::minRows($table),
            ));
        }
    }
}
