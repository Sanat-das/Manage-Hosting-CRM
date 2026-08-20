<?php

namespace Database\Seeders\Demo;

use Database\Seeders\Demo\Traits\WithIdempotentSeed;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Demo IPAM + DNS + the domain module.
 *
 * SCOPE
 * -----
 * Eleven tables, in dependency order:
 *
 *   vlans                 -> datacenters (nullable)
 *   ip_subnets            -> vlans + datacenters (both nullable)
 *   ip_addresses          -> ip_subnets + hosting_accounts (polymorphic)
 *   ip_allocation_history -> ip_addresses
 *   dns_zones             -> standalone
 *   dns_records           -> dns_zones
 *   domains               -> customers + orders (nullable)
 *   domain_pricing        -> standalone
 *   domain_pricing_terms  -> domain_pricing
 *   domain_search_logs    -> customers (nullable)
 *   domain_sync_log       -> standalone
 *
 * PLAN GAP CLAIMED HERE
 * ---------------------
 * The five `domain_*` tables sit in the row matrix but were never assigned to
 * a task in `.omo/plans/dummy-data.md`. The domain module belongs with DNS, so
 * this seeder owns them. See `.omo/evidence/task-16-ipam.txt`.
 *
 * All demo IPs use RFC 5737 ranges (192.0.2.0/24, 198.51.100.0/24,
 * 203.0.113.0/24) — documentation-only, never routable.
 *
 * FOREIGN KEYS RESOLVED LAZILY
 * ----------------------------
 * Datacenters and hosting_accounts are looked up at run time, never
 * assumed by id.
 *
 * ENUM VALUES FROM MIGRATIONS
 * ---------------------------
 * ip_subnets.ip_version: 4|6
 * ip_subnets.network_type: public|private|management|storage|dmz
 * ip_subnets.status: active|exhausted|reserved|retired
 * ip_addresses.ip_version: 4|6
 * ip_addresses.type: gateway|broadcast|network|reserved|available|assigned|floating|nat
 * ip_addresses.assigned_to_type: service|server|customer|inventory|App\Models\HostingAccount
 * ip_allocation_history.action: allocated|released|reserved|unreserved|ptr_updated|assigned|override
 * dns_zones.zone_type: forward|reverse
 * dns_zones.status: active|inactive
 * dns_records.type: A|AAAA|CNAME|MX|NS|TXT|SRV|PTR|SOA
 * dns_records.status: active|inactive
 * domains.type: register|transfer|existing
 * domains.status: pending|active|suspended|expired|cancelled|transferred|
 *                 pending_transfer|redemption
 * (domain_pricing / _terms / _search_logs / _sync_log declare no enums —
 *  `status` on domain_sync_log is a plain string column.)
 *
 * IDEMPOTENCY
 * -----------
 * Every write goes through `WithIdempotentSeed`; there is no raw insert here.
 * `vlans` and `ip_subnets` use `seedRow()` (updateOrInsert) because their
 * derived columns — datacenter link, `used_addresses`, `reserved_count` — must
 * converge on the plan even if an earlier run wrote different values. Every
 * other table uses `seedRowOnce()` (firstOrCreate), leaving operator edits
 * alone. Both are keyed on `DummyDataConfig::NATURAL_KEYS`, so N runs and one
 * run leave the same rows.
 *
 * `domain_sync_log`'s natural key includes its `payload` JSON, so payloads are
 * encoded through `stableJson()` (sorted keys, no timestamps, no randomness).
 * A volatile encoding would insert a duplicate on every single run.
 */
class IpamDnsSeeder extends Seeder
{
    use WithIdempotentSeed;

    private const PREFIX = 'DEMO';

    /** RFC 5737 documentation ranges — never routable. */
    private const SUBNETS = [
        ['cidr' => '192.0.2.0/24', 'name' => 'DEMO-Public-Production', 'network_type' => 'public', 'vlan_key' => 100, 'dc' => 1],
        ['cidr' => '198.51.100.0/24', 'name' => 'DEMO-Private-Backend', 'network_type' => 'private', 'vlan_key' => 200, 'dc' => 2],
        ['cidr' => '203.0.113.0/24', 'name' => 'DEMO-Storage-NAS', 'network_type' => 'storage', 'vlan_key' => 300, 'dc' => 1],
    ];

    /**
     * The address plan, indexed by position in `SUBNETS`.
     *
     * `assigned` is an offset into the ordered hosting-account list, resolved
     * at run time — never a hard-coded id. Declaring it here (rather than
     * inline) lets `seedSubnets()` derive `used_addresses` from the same
     * source of truth instead of patching the rows afterwards.
     */
    private const ADDRESS_PLAN = [
        // subnet 0: 192.0.2.0/24 — production public
        ['subnet' => 0, 'host' => 0, 'type' => 'network', 'assigned' => null, 'ptr' => null],
        ['subnet' => 0, 'host' => 1, 'type' => 'gateway', 'assigned' => null, 'ptr' => null],
        ['subnet' => 0, 'host' => 2, 'type' => 'reserved', 'assigned' => null, 'ptr' => null],
        ['subnet' => 0, 'host' => 10, 'type' => 'assigned', 'assigned' => 0, 'ptr' => 'demoshop.test'],
        ['subnet' => 0, 'host' => 11, 'type' => 'assigned', 'assigned' => 1, 'ptr' => 'demoblog.test'],
        ['subnet' => 0, 'host' => 12, 'type' => 'assigned', 'assigned' => 2, 'ptr' => 'demoagency.test'],
        ['subnet' => 0, 'host' => 13, 'type' => 'assigned', 'assigned' => 3, 'ptr' => 'demovps.test'],
        ['subnet' => 0, 'host' => 14, 'type' => 'assigned', 'assigned' => 4, 'ptr' => 'demolab.test'],
        ['subnet' => 0, 'host' => 15, 'type' => 'assigned', 'assigned' => 5, 'ptr' => 'demoarchive.test'],
        ['subnet' => 0, 'host' => 16, 'type' => 'available', 'assigned' => null, 'ptr' => null],
        ['subnet' => 0, 'host' => 17, 'type' => 'available', 'assigned' => null, 'ptr' => null],
        // subnet 1: 198.51.100.0/24 — private backend
        ['subnet' => 1, 'host' => 0, 'type' => 'network', 'assigned' => null, 'ptr' => null],
        ['subnet' => 1, 'host' => 1, 'type' => 'gateway', 'assigned' => null, 'ptr' => null],
        ['subnet' => 1, 'host' => 2, 'type' => 'reserved', 'assigned' => null, 'ptr' => null],
        ['subnet' => 1, 'host' => 10, 'type' => 'assigned', 'assigned' => 0, 'ptr' => null],
        ['subnet' => 1, 'host' => 11, 'type' => 'assigned', 'assigned' => 1, 'ptr' => null],
        ['subnet' => 1, 'host' => 12, 'type' => 'available', 'assigned' => null, 'ptr' => null],
        // subnet 2: 203.0.113.0/24 — storage
        ['subnet' => 2, 'host' => 0, 'type' => 'network', 'assigned' => null, 'ptr' => null],
        ['subnet' => 2, 'host' => 1, 'type' => 'gateway', 'assigned' => null, 'ptr' => null],
        ['subnet' => 2, 'host' => 2, 'type' => 'reserved', 'assigned' => null, 'ptr' => null],
        ['subnet' => 2, 'host' => 10, 'type' => 'assigned', 'assigned' => 2, 'ptr' => null],
        ['subnet' => 2, 'host' => 11, 'type' => 'assigned', 'assigned' => 3, 'ptr' => null],
        ['subnet' => 2, 'host' => 12, 'type' => 'available', 'assigned' => null, 'ptr' => null],
    ];

    public function run(): void
    {
        $this->seedVlans();
        $this->seedSubnets();
        $allocatedIps = $this->seedIpAddresses();
        $this->seedAllocationHistory($allocatedIps);
        $zones = $this->seedDnsZones();
        $this->seedDnsRecords($zones);
        $this->seedDomains();
        $this->seedDomainPricing();
        $this->seedDomainSearchLogs();
        $this->seedDomainSyncLog();

        $this->report();
    }

    /** Print every owned table's count next to its DummyDataConfig minimum. */
    private function report(): void
    {
        $tables = [
            'vlans', 'ip_subnets', 'ip_addresses', 'ip_allocation_history',
            'dns_zones', 'dns_records', 'domains', 'domain_pricing',
            'domain_pricing_terms', 'domain_search_logs', 'domain_sync_log',
        ];

        foreach ($tables as $table) {
            $count = $this->countOf($table);
            $min = DummyDataConfig::minRows($table);

            $this->command?->info(sprintf(
                'IpamDnsSeeder: %-22s %3d rows (min %d) %s',
                $table,
                $count,
                $min,
                $count >= $min ? 'OK' : 'SHORT',
            ));
        }
    }

    // -----------------------------------------------------------------
    // vlans
    // -----------------------------------------------------------------

    /** Seed 3 demo VLANs, linked to real datacenters. */
    private function seedVlans(): void
    {
        $datacenterIds = $this->datacenterIds();

        $plan = [
            ['vlan_id' => 100, 'name' => self::PREFIX.'-VLAN-PROD', 'desc' => 'Production public-facing network.', 'dc' => 1],
            ['vlan_id' => 200, 'name' => self::PREFIX.'-VLAN-MGMT', 'desc' => 'Management & backend network.', 'dc' => 2],
            ['vlan_id' => 300, 'name' => self::PREFIX.'-VLAN-STORAGE', 'desc' => 'Storage & backup network.', 'dc' => 1],
        ];

        foreach ($plan as $row) {
            $this->seedRow('vlans', [
                'name' => $row['name'],
                'vlan_id' => $row['vlan_id'],
                'description' => $row['desc'],
                'datacenter_id' => $datacenterIds[$row['dc']] ?? null,
                'subnet_id' => null,
            ]);
        }
    }

    // -----------------------------------------------------------------
    // ip_subnets
    // -----------------------------------------------------------------

    /** Seed 3 demo subnets over RFC 5737 ranges. */
    private function seedSubnets(): void
    {
        $vlanIds = $this->vlanIds();
        $datacenterIds = $this->datacenterIds();

        foreach (self::SUBNETS as $key => $row) {
            $this->seedRow('ip_subnets', [
                'name' => $row['name'],
                'subnet_cidr' => $row['cidr'],
                'gateway' => $this->firstUsable($row['cidr']),
                'netmask' => '255.255.255.0',
                'ip_version' => '4',
                'network_type' => $row['network_type'],
                'vlan_id' => $vlanIds[$row['vlan_key']] ?? null,
                'datacenter_id' => $datacenterIds[$row['dc']] ?? null,
                'total_addresses' => 254,
                'used_addresses' => $this->plannedUsage($key),
                'reserved_count' => $this->plannedCountOfType($key, 'reserved'),
                'description' => 'Demo subnet over RFC 5737 documentation range.',
                'status' => 'active',
            ]);
        }
    }

    // -----------------------------------------------------------------
    // ip_addresses
    // -----------------------------------------------------------------

    /**
     * Seed 20 demo IP addresses across the 3 subnets.
     *
     * @return array<int, array{id:int, ip_address:string, subnet_id:int, assigned_to_id:int|null}>
     */
    private function seedIpAddresses(): array
    {
        $subnetIds = $this->subnetIds();
        $hostingAccounts = $this->hostingAccountIds();

        if ($subnetIds === [] || $hostingAccounts === []) {
            $this->command?->warn('IpamDnsSeeder: missing subnets or hosting accounts — skipping IP addresses.');

            return [];
        }

        $subnets = [];
        foreach (self::SUBNETS as $key => $row) {
            $subnets[$key] = [
                'id' => $subnetIds[$row['cidr']],
                'base' => $this->networkBase($row['cidr']),
            ];
        }

        $allocated = [];
        $haList = array_values($hostingAccounts);

        foreach (self::ADDRESS_PLAN as $row) {
            $subnet = $subnets[$row['subnet']];
            $ip = $subnet['base'].$row['host'];
            $subnetId = $subnet['id'];

            $assignedToType = null;
            $assignedToId = null;

            if ($row['assigned'] !== null) {
                $assignedToType = 'App\Models\HostingAccount';
                $assignedToId = $haList[$row['assigned']] ?? null;
            }

            $id = (int) $this->seedRowOnce('ip_addresses', [
                'subnet_id' => $subnetId,
                'ip_address' => $ip,
                'ip_version' => '4',
                'type' => $row['type'],
                'assigned_to_type' => $assignedToType,
                'assigned_to_id' => $assignedToId,
                'inventory_asset_id' => null,
                'ptr_record' => $row['ptr'],
                'notes' => $row['type'] === 'assigned'
                    ? 'Allocated to hosting account '.$assignedToId.'.'
                    : 'Demo '.$row['type'].' address.',
                'last_seen_at' => $row['type'] === 'assigned' ? now()->subHours(2) : null,
            ]);

            if ($assignedToId !== null) {
                $allocated[] = [
                    'id' => $id,
                    'ip_address' => $ip,
                    'subnet_id' => $subnetId,
                    'assigned_to_id' => $assignedToId,
                ];
            }
        }

        return $allocated;
    }

    // -----------------------------------------------------------------
    // ip_allocation_history
    // -----------------------------------------------------------------

    /**
     * Seed allocation history entries for the allocated IPs.
     *
     * @param  array<int, array{id:int, ip_address:string, subnet_id:int, assigned_to_id:int}>  $allocatedIps
     */
    private function seedAllocationHistory(array $allocatedIps): void
    {
        if ($allocatedIps === []) {
            $this->command?->warn('IpamDnsSeeder: no allocated IPs — skipping allocation history.');

            return;
        }

        // Who the history rows are attributed to, resolved by e-mail (the
        // protected admin login) rather than assuming auto-increment id 1.
        $adminId = $this->adminUserId();

        $historyPlan = [
            ['index' => 0, 'action' => 'allocated', 'notes' => 'Initial allocation to hosting account.'],
            ['index' => 1, 'action' => 'allocated', 'notes' => 'Initial allocation to hosting account.'],
            ['index' => 2, 'action' => 'allocated', 'notes' => 'Initial allocation to hosting account.'],
            ['index' => 3, 'action' => 'allocated', 'notes' => 'Initial allocation to hosting account.'],
            ['index' => 4, 'action' => 'allocated', 'notes' => 'Initial allocation to hosting account.'],
            ['index' => 5, 'action' => 'allocated', 'notes' => 'Initial allocation to hosting account.'],
            ['index' => 6, 'action' => 'released', 'notes' => 'Released due to account suspension.'],
            ['index' => 7, 'action' => 'released', 'notes' => 'Released due to account termination.'],
            ['index' => 0, 'action' => 'reserved', 'notes' => 'Reserved for future assignment.'],
            ['index' => 3, 'action' => 'ptr_updated', 'notes' => 'PTR record updated.'],
        ];

        foreach ($historyPlan as $entry) {
            $ip = $allocatedIps[$entry['index']] ?? null;

            if ($ip === null) {
                continue;
            }

            $snapshot = json_encode([
                'ip_address' => $ip['ip_address'],
                'subnet_id' => $ip['subnet_id'],
                'assigned_to_type' => 'App\Models\HostingAccount',
                'assigned_to_id' => $ip['assigned_to_id'],
            ]);

            $previousType = null;
            $previousId = null;
            $newType = null;
            $newId = null;

            if ($entry['action'] === 'allocated') {
                $newType = 'App\Models\HostingAccount';
                $newId = $ip['assigned_to_id'];
            } elseif ($entry['action'] === 'released') {
                $previousType = 'App\Models\HostingAccount';
                $previousId = $ip['assigned_to_id'];
            } elseif ($entry['action'] === 'reserved') {
                $newType = 'inventory';
                $newId = 0;
            }

            $this->seedRowOnce('ip_allocation_history', [
                'ip_address_id' => $ip['id'],
                'action' => $entry['action'],
                'previous_assigned_to_type' => $previousType,
                'previous_assigned_to_id' => $previousId,
                'new_assigned_to_type' => $newType,
                'new_assigned_to_id' => $newId,
                'changed_by_user_id' => $adminId,
                'ip_address_snapshot' => $snapshot,
                'changed_at' => now()->subDays(30 - $entry['index'] * 2),
                'notes' => $entry['notes'],
            ]);
        }
    }

    /**
     * The protected admin login's id, resolved by e-mail rather than assumed
     * to be 1. Auto-increment ids are an artifact of insert order, never a
     * contract - only the login declared in DummyDataConfig::PROTECTED_ROWS
     * is one. Throws because `changed_by_user_id` records who made the
     * change: writing a guess would fabricate an actor.
     */
    private function adminUserId(): int
    {
        $protected = DummyDataConfig::PROTECTED_ROWS['users'] ?? [];

        if ($protected === []) {
            throw new RuntimeException('IpamDnsSeeder: no protected admin row declared in DummyDataConfig.');
        }

        $id = DB::table('users')->where($protected)->value('id');

        if ($id === null) {
            throw new RuntimeException(sprintf(
                'IpamDnsSeeder: admin user [%s] not found - run InitialDataSeeder first.',
                $protected['email'] ?? '?'
            ));
        }

        return (int) $id;
    }

    // -----------------------------------------------------------------
    // dns_zones
    // -----------------------------------------------------------------

    /** Seed 5 demo DNS zones (3 forward + 2 reverse). */
    private function seedDnsZones(): array
    {
        $zones = [];

        $forward = [
            ['name' => 'demoshop.test', 'desc' => 'Production shop domain.'],
            ['name' => 'demoblog.test', 'desc' => 'Production blog domain.'],
            ['name' => 'demoagency.test', 'desc' => 'Agency reseller domain.'],
        ];

        foreach ($forward as $row) {
            $id = (int) $this->seedRowOnce('dns_zones', [
                'name' => $row['name'],
                'zone_type' => 'forward',
                'serial' => 2026080801,
                'refresh' => 3600,
                'retry' => 900,
                'expire' => 604800,
                'ttl' => 86400,
                'master_nameserver' => 'ns1.demo.example',
                'admin_email' => 'hostmaster@'.$row['name'],
                'status' => 'active',
            ]);
            $zones[$row['name']] = $id;
        }

        $reverse = [
            ['name' => '2.0.192.in-addr.arpa', 'desc' => 'Reverse for 192.0.2.0/24.'],
            ['name' => '100.51.198.in-addr.arpa', 'desc' => 'Reverse for 198.51.100.0/24.'],
        ];

        foreach ($reverse as $row) {
            $id = (int) $this->seedRowOnce('dns_zones', [
                'name' => $row['name'],
                'zone_type' => 'reverse',
                'serial' => 2026080801,
                'refresh' => 3600,
                'retry' => 900,
                'expire' => 604800,
                'ttl' => 86400,
                'master_nameserver' => null,
                'admin_email' => null,
                'status' => 'active',
            ]);
            $zones[$row['name']] = $id;
        }

        return $zones;
    }

    // -----------------------------------------------------------------
    // dns_records
    // -----------------------------------------------------------------

    /** Seed 15 demo DNS records across the zones. */
    private function seedDnsRecords(array $zones): void
    {
        if ($zones === []) {
            $this->command?->warn('IpamDnsSeeder: no DNS zones — skipping records.');

            return;
        }

        $plan = [
            // demoshop.test (forward)
            ['zone' => 'demoshop.test', 'name' => '@', 'type' => 'A', 'content' => '192.0.2.10', 'priority' => 0],
            ['zone' => 'demoshop.test', 'name' => 'www', 'type' => 'CNAME', 'content' => '@', 'priority' => 0],
            ['zone' => 'demoshop.test', 'name' => '@', 'type' => 'MX', 'content' => 'mail.demoshop.test', 'priority' => 10],
            ['zone' => 'demoshop.test', 'name' => '@', 'type' => 'TXT', 'content' => 'v=spf1 include:_spf.demo.example ~all', 'priority' => 0],
            ['zone' => 'demoshop.test', 'name' => '@', 'type' => 'NS', 'content' => 'ns1.demo.example', 'priority' => 0],
            // demoblog.test (forward)
            ['zone' => 'demoblog.test', 'name' => '@', 'type' => 'A', 'content' => '192.0.2.11', 'priority' => 0],
            ['zone' => 'demoblog.test', 'name' => '@', 'type' => 'NS', 'content' => 'ns1.demo.example', 'priority' => 0],
            ['zone' => 'demoblog.test', 'name' => 'mail', 'type' => 'A', 'content' => '192.0.2.12', 'priority' => 0],
            // demoagency.test (forward)
            ['zone' => 'demoagency.test', 'name' => '@', 'type' => 'A', 'content' => '192.0.2.13', 'priority' => 0],
            ['zone' => 'demoagency.test', 'name' => 'www', 'type' => 'A', 'content' => '192.0.2.14', 'priority' => 0],
            // reverse zones
            ['zone' => '2.0.192.in-addr.arpa', 'name' => '10', 'type' => 'PTR', 'content' => 'demoshop.test.', 'priority' => 0],
            ['zone' => '2.0.192.in-addr.arpa', 'name' => '11', 'type' => 'PTR', 'content' => 'demoblog.test.', 'priority' => 0],
            ['zone' => '2.0.192.in-addr.arpa', 'name' => '12', 'type' => 'PTR', 'content' => 'mail.demoshop.test.', 'priority' => 0],
            ['zone' => '100.51.198.in-addr.arpa', 'name' => '10', 'type' => 'PTR', 'content' => 'demoagency.test.', 'priority' => 0],
            ['zone' => '100.51.198.in-addr.arpa', 'name' => '11', 'type' => 'PTR', 'content' => 'demovps.test.', 'priority' => 0],
        ];

        foreach ($plan as $row) {
            $zoneId = $zones[$row['zone']] ?? null;

            if ($zoneId === null) {
                continue;
            }

            $this->seedRowOnce('dns_records', [
                'zone_id' => $zoneId,
                'name' => $row['name'],
                'type' => $row['type'],
                'content' => $row['content'],
                'ttl' => 3600,
                'priority' => $row['priority'],
                'service_id' => null,
                'status' => 'active',
            ]);
        }
    }

    // -----------------------------------------------------------------
    // domains
    // -----------------------------------------------------------------

    /**
     * Seed 8 demo domains owned by real customers.
     *
     * Names mirror the `.test` hosting accounts from T11 so a domain, its
     * hosting account, its DNS zone and its PTR record all line up.
     */
    private function seedDomains(): void
    {
        $customerIds = $this->customerIds();

        if ($customerIds === []) {
            $this->command?->warn('IpamDnsSeeder: no demo customers — skipping domains.');

            return;
        }

        $orderIds = $this->orderIds();

        // registered_days_ago drives every date, so re-runs stay deterministic
        // relative to "today" without ever appearing in a natural key.
        $plan = [
            ['name' => 'demoshop.test', 'customer' => 0, 'type' => 'register', 'status' => 'active', 'registrar' => 'resellerclub', 'years' => 1, 'age' => 200, 'privacy' => true, 'auto_renew' => true, 'amount' => '899.00'],
            ['name' => 'demoblog.test', 'customer' => 1, 'type' => 'register', 'status' => 'active', 'registrar' => 'resellerclub', 'years' => 2, 'age' => 400, 'privacy' => false, 'auto_renew' => true, 'amount' => '849.00'],
            ['name' => 'demoagency.test', 'customer' => 2, 'type' => 'transfer', 'status' => 'active', 'registrar' => 'openprovider', 'years' => 1, 'age' => 120, 'privacy' => true, 'auto_renew' => true, 'amount' => '1299.00'],
            ['name' => 'demovps.test', 'customer' => 3, 'type' => 'register', 'status' => 'pending', 'registrar' => 'resellerclub', 'years' => 1, 'age' => 3, 'privacy' => false, 'auto_renew' => false, 'amount' => '899.00'],
            ['name' => 'demolab.test', 'customer' => 4, 'type' => 'register', 'status' => 'suspended', 'registrar' => 'openprovider', 'years' => 1, 'age' => 330, 'privacy' => false, 'auto_renew' => false, 'amount' => '899.00'],
            ['name' => 'demoarchive.test', 'customer' => 0, 'type' => 'existing', 'status' => 'expired', 'registrar' => 'resellerclub', 'years' => 1, 'age' => 420, 'privacy' => false, 'auto_renew' => false, 'amount' => '899.00'],
            ['name' => 'demodedi.test', 'customer' => 1, 'type' => 'register', 'status' => 'active', 'registrar' => 'cloudflare', 'years' => 5, 'age' => 700, 'privacy' => true, 'auto_renew' => true, 'amount' => '3999.00'],
            ['name' => 'demoaddon.test', 'customer' => 2, 'type' => 'transfer', 'status' => 'pending_transfer', 'registrar' => 'openprovider', 'years' => 1, 'age' => 10, 'privacy' => false, 'auto_renew' => true, 'amount' => '1299.00'],
        ];

        foreach ($plan as $index => $row) {
            $customerId = $customerIds[$row['customer'] % count($customerIds)];
            $registered = now()->startOfDay()->subDays($row['age']);
            $expiry = $registered->copy()->addYears($row['years']);

            $this->seedRowOnce('domains', [
                'customer_id' => $customerId,
                'order_id' => $orderIds[$index] ?? null,
                'name' => $row['name'],
                'type' => $row['type'],
                'registrar_id' => self::PREFIX.'-REG-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'registrar' => $row['registrar'],
                'registration_date' => $registered->toDateString(),
                'registration_period' => $row['years'],
                'expiry_date' => $expiry->toDateString(),
                'next_due_date' => $expiry->toDateString(),
                'next_invoice_date' => $expiry->copy()->subDays(30)->toDateString(),
                'recurring_amount' => $row['amount'],
                'payment_method' => 'razorpay',
                'subscription_id' => null,
                'auto_renew' => $row['auto_renew'],
                'privacy_enabled' => $row['privacy'],
                'nameservers' => json_encode(['ns1.demo.example', 'ns2.demo.example']),
                'dns_records' => null,
                'auth_code' => null,
                'lock_status' => $row['status'] === 'pending_transfer' ? false : true,
                'dns_management' => true,
                'email_forwarding' => false,
                'id_protection' => $row['privacy'],
                'status' => $row['status'],
            ]);
        }
    }

    // -----------------------------------------------------------------
    // domain_pricing + domain_pricing_terms
    // -----------------------------------------------------------------

    /**
     * Seed 10 TLD price rows, each with terms.
     *
     * Term prices scale linearly off the 1-year price with a small multi-year
     * discount, so `term_years * register_price` is never cheaper than the
     * longer term — coherent numbers for the pricing screens.
     */
    private function seedDomainPricing(): void
    {
        $plan = [
            ['tld' => 'com', 'register' => 899.00, 'renew' => 999.00, 'transfer' => 899.00, 'premium' => false, 'terms' => [1, 2, 5, 10]],
            ['tld' => 'net', 'register' => 1099.00, 'renew' => 1199.00, 'transfer' => 1099.00, 'premium' => false, 'terms' => [1, 2, 5]],
            ['tld' => 'org', 'register' => 999.00, 'renew' => 1099.00, 'transfer' => 999.00, 'premium' => false, 'terms' => [1, 2, 5]],
            ['tld' => 'in', 'register' => 649.00, 'renew' => 749.00, 'transfer' => 649.00, 'premium' => false, 'terms' => [1, 2, 5, 10]],
            ['tld' => 'co.in', 'register' => 549.00, 'renew' => 649.00, 'transfer' => 549.00, 'premium' => false, 'terms' => [1, 2]],
            ['tld' => 'info', 'register' => 1299.00, 'renew' => 1499.00, 'transfer' => 1299.00, 'premium' => false, 'terms' => [1, 2]],
            ['tld' => 'biz', 'register' => 1199.00, 'renew' => 1399.00, 'transfer' => 1199.00, 'premium' => false, 'terms' => [1, 2]],
            ['tld' => 'dev', 'register' => 1499.00, 'renew' => 1599.00, 'transfer' => 1499.00, 'premium' => false, 'terms' => [1, 2]],
            ['tld' => 'io', 'register' => 3999.00, 'renew' => 4299.00, 'transfer' => 3999.00, 'premium' => true, 'terms' => [1, 2]],
            ['tld' => 'store', 'register' => 2499.00, 'renew' => 2799.00, 'transfer' => 2499.00, 'premium' => false, 'terms' => [1, 2]],
        ];

        foreach ($plan as $row) {
            $pricingId = (int) $this->seedRowOnce('domain_pricing', [
                'tld' => $row['tld'],
                'register_price' => number_format($row['register'], 2, '.', ''),
                'renew_price' => number_format($row['renew'], 2, '.', ''),
                'transfer_price' => number_format($row['transfer'], 2, '.', ''),
                'currency' => 'INR',
                'premium' => $row['premium'],
                'enabled' => true,
                'synced_at' => null,
            ]);

            if ($pricingId === 0) {
                continue;
            }

            foreach ($row['terms'] as $years) {
                $this->seedRowOnce('domain_pricing_terms', [
                    'domain_pricing_id' => $pricingId,
                    'term_years' => $years,
                    'register_price' => $this->termPrice($row['register'], $years),
                    'renew_price' => $this->termPrice($row['renew'], $years),
                ]);
            }
        }
    }

    /**
     * Multi-year price: the yearly rate times the term, minus a 2%-per-extra-
     * year loyalty discount capped at 15%.
     */
    private function termPrice(float $yearly, int $years): string
    {
        $discount = min(0.15, 0.02 * ($years - 1));

        return number_format(round($yearly * $years * (1 - $discount), 2), 2, '.', '');
    }

    // -----------------------------------------------------------------
    // domain_search_logs
    // -----------------------------------------------------------------

    /** Seed 10 whois-style availability searches; some anonymous. */
    private function seedDomainSearchLogs(): void
    {
        $customerIds = $this->customerIds();

        $plan = [
            ['customer' => 0, 'name' => 'demoshop.test', 'available' => false],
            ['customer' => 0, 'name' => 'demoshop-store.test', 'available' => true],
            ['customer' => 1, 'name' => 'demoblog.test', 'available' => false],
            ['customer' => 1, 'name' => 'demoblog-news.test', 'available' => true],
            ['customer' => 2, 'name' => 'demoagency.test', 'available' => false],
            ['customer' => 2, 'name' => 'demoagency-hq.test', 'available' => true],
            ['customer' => 3, 'name' => 'demovps-cloud.test', 'available' => true],
            ['customer' => 4, 'name' => 'demolab-research.test', 'available' => true],
            ['customer' => null, 'name' => 'demoanon-one.test', 'available' => true],
            ['customer' => null, 'name' => 'demoanon-two.test', 'available' => false],
        ];

        foreach ($plan as $row) {
            $customerId = null;

            if ($row['customer'] !== null && $customerIds !== []) {
                $customerId = $customerIds[$row['customer'] % count($customerIds)];
            }

            $this->seedRowOnce('domain_search_logs', [
                'customer_id' => $customerId,
                'domain_name' => $row['name'],
                'results' => $this->stableJson([
                    'available' => $row['available'],
                    'currency' => 'INR',
                    'domain' => $row['name'],
                    'price' => $row['available'] ? '899.00' : null,
                    'registrar' => 'resellerclub',
                    'tld' => 'test',
                ]),
            ]);
        }
    }

    // -----------------------------------------------------------------
    // domain_sync_log
    // -----------------------------------------------------------------

    /**
     * Seed 5 registrar sync entries.
     *
     * `payload` is part of the natural key, so it is encoded with
     * `stableJson()` — sorted keys, no timestamps, no random values. A
     * volatile payload would make every re-run insert a fresh row.
     */
    private function seedDomainSyncLog(): void
    {
        $plan = [
            ['provider' => 'resellerclub', 'operation' => 'pricing_sync', 'status' => 'success', 'payload' => ['tld_count' => 10, 'currency' => 'INR', 'mode' => 'full'], 'error' => null],
            ['provider' => 'resellerclub', 'operation' => 'domain_sync', 'status' => 'success', 'payload' => ['domains' => 3, 'mode' => 'incremental'], 'error' => null],
            ['provider' => 'openprovider', 'operation' => 'pricing_sync', 'status' => 'success', 'payload' => ['tld_count' => 4, 'currency' => 'INR', 'mode' => 'full'], 'error' => null],
            ['provider' => 'openprovider', 'operation' => 'transfer_poll', 'status' => 'failed', 'payload' => ['domains' => 1, 'mode' => 'poll'], 'error' => 'Registrar returned HTTP 503 (demo fixture).'],
            ['provider' => 'cloudflare', 'operation' => 'nameserver_sync', 'status' => 'success', 'payload' => ['domains' => 1, 'mode' => 'push'], 'error' => null],
        ];

        foreach ($plan as $row) {
            $this->seedRowOnce('domain_sync_log', [
                'provider' => $row['provider'],
                'operation' => $row['operation'],
                'status' => $row['status'],
                'payload' => $this->stableJson($row['payload']),
                'error' => $row['error'],
            ]);
        }
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    /** How many addresses the plan assigns inside the subnet at `$key`. */
    private function plannedUsage(int $key): int
    {
        return count(array_filter(
            self::ADDRESS_PLAN,
            fn (array $row): bool => $row['subnet'] === $key && $row['assigned'] !== null
        ));
    }

    /** How many addresses of `$type` the plan places inside the subnet at `$key`. */
    private function plannedCountOfType(int $key, string $type): int
    {
        return count(array_filter(
            self::ADDRESS_PLAN,
            fn (array $row): bool => $row['subnet'] === $key && $row['type'] === $type
        ));
    }

    /**
     * Deterministic JSON: keys sorted, no timestamps, no random values.
     * Required whenever the encoded string is part of a natural key.
     *
     * @param  array<string, mixed>  $data
     */
    private function stableJson(array $data): string
    {
        ksort($data);

        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return list<int> demo customer ids, resolved via users.email LIKE 'client%' */
    private function customerIds(): array
    {
        return array_map(
            'intval',
            DB::table('customers')
                ->join('users', 'users.id', '=', 'customers.user_id')
                ->where('users.email', 'like', 'client%')
                ->orderBy('customers.id')
                ->pluck('customers.id')
                ->all()
        );
    }

    /** @return list<int> demo order ids (used as nullable domain parents) */
    private function orderIds(): array
    {
        return array_map(
            'intval',
            DB::table('orders')
                ->where('order_number', 'like', self::PREFIX.'%')
                ->orWhere('order_number', 'like', 'ORD-%')
                ->orderBy('id')
                ->pluck('id')
                ->all()
        );
    }

    /**
     * Datacenter ids keyed 1..N in `code` order, so the plans above can refer
     * to "the first datacenter" without knowing its code or its auto-increment
     * id. Returns an empty array when infrastructure has not been seeded yet,
     * in which case every reference falls back to null (the columns are
     * nullable and carry no FK constraint).
     *
     * @return array<int, int>
     */
    private function datacenterIds(): array
    {
        $ids = array_map(
            'intval',
            DB::table('datacenters')->orderBy('code')->pluck('id')->all()
        );

        $keyed = [];

        foreach ($ids as $index => $id) {
            $keyed[$index + 1] = $id;
        }

        return $keyed;
    }

    /** @return array<int, int> vlan_id => id */
    private function vlanIds(): array
    {
        return DB::table('vlans')
            ->pluck('id', 'vlan_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @return array<string, int> subnet_cidr => id */
    private function subnetIds(): array
    {
        return DB::table('ip_subnets')
            ->pluck('id', 'subnet_cidr')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @return array<int, int> list of hosting_account ids */
    private function hostingAccountIds(): array
    {
        return array_map(
            'intval',
            DB::table('hosting_accounts')
                ->orderBy('id')
                ->pluck('id')
                ->all()
        );
    }

    /** First usable host address in a /24 CIDR. */
    private function firstUsable(string $cidr): string
    {
        $base = $this->networkBase($cidr);

        return $base.'1';
    }

    /** Network base (first 3 octets with trailing dot) from a CIDR. */
    private function networkBase(string $cidr): string
    {
        $parts = explode('/', $cidr, 2);
        $ip = $parts[0];
        $octets = explode('.', $ip);

        return $octets[0].'.'.$octets[1].'.'.$octets[2].'.';
    }

    private function countOf(string $table): int
    {
        return (int) DB::table($table)->count();
    }
}
