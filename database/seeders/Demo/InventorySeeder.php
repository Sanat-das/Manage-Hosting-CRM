<?php

namespace Database\Seeders\Demo;

use Database\Seeders\Demo\Traits\WithIdempotentSeed;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo inventory: `inventory_assets` + `asset_relationships`.
 *
 * SCOPE / INDEPENDENCE
 * --------------------
 * This seeder is deliberately self-contained. It writes to exactly two tables
 * and references nothing outside them: `datacenter_id`, `rack_id` and the
 * `licenses.inventory_asset_id` link are all left alone so the file can run
 * before, after, or without the datacenter/rack/server seeders. Every
 * `asset_relationships` row therefore points at assets created right here.
 *
 * It also stays clear of the legacy `reference-inventory-assets.sql` import:
 * all demo tags use the `DEMO-*` prefix, so a later reference import cannot
 * collide with (or be clobbered by) this data.
 *
 * ENUM VALUES ARE READ FROM THE MIGRATION, NOT INVENTED
 * -----------------------------------------------------
 * `2026_07_30_120070_create_inventory_tables.php` declares
 * `inventory_assets.asset_type` as an enum, and sqlite compiles that into a
 * CHECK constraint, so a wrong value is a hard failure rather than a silent
 * cast. The allowed values are:
 *
 *   server, ram_module, cpu, ssd, hdd, gpu, raid_controller, nic, switch,
 *   pdu, other_hardware, software_license, ipv4_address, ipv6_address,
 *   ssl_certificate, domain
 *
 * There is no `router`, `ups` or `storage` member, so the demo fleet maps
 * those roles onto the members that do exist (`other_hardware` for the edge
 * router, `pdu` for the UPS/power feed, `ssd`/`hdd` for storage) and records
 * the real-world role in `notes`.
 *
 * `status` and `lifecycle_state` share one enum: ordered, received, in_stock,
 * installed, assigned, maintenance, retired, disposed.
 *
 * `asset_relationships.relationship_type` is a plain `string(50)` in the
 * migration, but `AssetRelationship::RELATIONSHIP_TYPES` narrows it to
 * hosted_on / hosted_in / manages / contains and the model throws on anything
 * else, so only those four are used here. `parent_kind`/`child_kind` are
 * likewise free-form strings; `inventory_asset` is used because the rows link
 * inventory assets to each other and nothing else.
 *
 * IDEMPOTENCY
 * -----------
 * Assets are written with `seedRowOnce()` (firstOrCreate on the unique
 * `asset_tag`), so a hand-edited demo asset survives a re-run. Relationships
 * use `seedRowOnce()` against the composite unique index
 * (parent_kind, parent_id, child_kind, child_id, relationship_type).
 * `seedUpTo()` tops both tables up should `DummyDataConfig::ROWS` ever be
 * raised above the curated fleet size.
 */
class InventorySeeder extends Seeder
{
    use WithIdempotentSeed;

    /** Prefix that keeps demo tags disjoint from the legacy reference import. */
    private const TAG_PREFIX = 'DEMO';

    /** `parent_kind`/`child_kind` value used for asset-to-asset links. */
    private const KIND = 'inventory_asset';

    public function run(): void
    {
        $assetIds = $this->seedAssets();
        $this->seedRelationships($assetIds);

        $this->command?->info(sprintf(
            'InventorySeeder: %d inventory_assets, %d asset_relationships.',
            $this->countOf('inventory_assets'),
            $this->countOf('asset_relationships'),
        ));
    }

    /**
     * Seed the curated fleet, then top up to `ROWS['inventory_assets']`.
     *
     * Definitions are ordered parents-first so a child's `parent_asset_id` can
     * be resolved from the ids collected earlier in the same pass.
     *
     * @return array<string, int> asset_tag => id
     */
    private function seedAssets(): array
    {
        $ids = [];

        foreach ($this->assetDefinitions() as $definition) {
            $parentTag = $definition['parent_tag'] ?? null;
            unset($definition['parent_tag']);

            if ($parentTag !== null) {
                $definition['parent_asset_id'] = $ids[$parentTag] ?? null;
            }

            $ids[$definition['asset_tag']] = (int) $this->seedRowOnce('inventory_assets', $definition);
        }

        $shortfall = DummyDataConfig::minRows('inventory_assets') - count($ids);

        if ($shortfall > 0) {
            $base = count($ids);
            $this->seedUpTo(
                'inventory_assets',
                fn (int $i): array => $this->fillerAsset($base + $i),
                $shortfall,
            );
        }

        return $ids;
    }

    /**
     * Link the fleet together: components inside their host server, network
     * gear feeding the servers it serves.
     *
     * @param  array<string, int>  $assetIds
     */
    private function seedRelationships(array $assetIds): void
    {
        $seeded = 0;

        foreach ($this->relationshipDefinitions() as $definition) {
            $parentId = $assetIds[$definition['parent_tag']] ?? null;
            $childId = $assetIds[$definition['child_tag']] ?? null;

            if ($parentId === null || $childId === null || $parentId === $childId) {
                continue;
            }

            $this->seedRowOnce('asset_relationships', [
                'parent_kind' => self::KIND,
                'parent_id' => $parentId,
                'child_kind' => self::KIND,
                'child_id' => $childId,
                'relationship_type' => $definition['relationship_type'],
                'label' => $definition['label'],
                'sort_order' => $seeded,
                'notes' => $definition['notes'],
            ]);

            $seeded++;
        }
    }

    /**
     * The curated demo fleet: 2 servers, 2 switches, an edge router, a UPS
     * feed, two storage devices and two server components.
     *
     * @return list<array<string, mixed>>
     */
    private function assetDefinitions(): array
    {
        return [
            [
                'asset_tag' => self::TAG_PREFIX.'-SRV-0001',
                'serial_number' => 'SN-DELL-R650-000001',
                'asset_type' => 'server',
                'manufacturer' => 'Dell',
                'model' => 'PowerEdge R650',
                'vendor' => 'Dell Technologies',
                'purchase_date' => '2024-02-14',
                'purchase_cost' => 489000.00,
                'warranty_expiry' => '2027-02-14',
                'rack_u_position' => 12,
                'status' => 'installed',
                'lifecycle_state' => 'installed',
                'notes' => 'Demo primary hypervisor host.',
            ],
            [
                'asset_tag' => self::TAG_PREFIX.'-SRV-0002',
                'serial_number' => 'SN-HPE-DL380-000002',
                'asset_type' => 'server',
                'manufacturer' => 'HPE',
                'model' => 'ProLiant DL380 Gen10',
                'vendor' => 'Hewlett Packard Enterprise',
                'purchase_date' => '2024-05-02',
                'purchase_cost' => 421500.00,
                'warranty_expiry' => '2027-05-02',
                'rack_u_position' => 14,
                'status' => 'installed',
                'lifecycle_state' => 'installed',
                'notes' => 'Demo secondary hypervisor host.',
            ],
            [
                'asset_tag' => self::TAG_PREFIX.'-SWT-0001',
                'serial_number' => 'SN-JNPR-EX4300-000003',
                'asset_type' => 'switch',
                'manufacturer' => 'Juniper',
                'model' => 'EX4300-48T',
                'vendor' => 'Juniper Networks',
                'purchase_date' => '2023-11-20',
                'purchase_cost' => 315000.00,
                'warranty_expiry' => '2026-11-20',
                'rack_u_position' => 40,
                'status' => 'installed',
                'lifecycle_state' => 'installed',
                'notes' => 'Demo top-of-rack access switch.',
            ],
            [
                'asset_tag' => self::TAG_PREFIX.'-SWT-0002',
                'serial_number' => 'SN-ARISTA-7050X-000004',
                'asset_type' => 'switch',
                'manufacturer' => 'Arista',
                'model' => '7050SX3-48YC8',
                'vendor' => 'Arista Networks',
                'purchase_date' => '2024-01-09',
                'purchase_cost' => 528000.00,
                'warranty_expiry' => '2027-01-09',
                'rack_u_position' => 41,
                'status' => 'in_stock',
                'lifecycle_state' => 'received',
                'notes' => 'Demo spare aggregation switch, not yet cabled.',
            ],
            [
                // No `router` member in the asset_type enum -> other_hardware.
                'asset_tag' => self::TAG_PREFIX.'-RTR-0001',
                'serial_number' => 'SN-MIKROTIK-CCR2004-000005',
                'asset_type' => 'other_hardware',
                'manufacturer' => 'MikroTik',
                'model' => 'CCR2004-16G-2S+',
                'vendor' => 'MikroTik',
                'purchase_date' => '2023-09-01',
                'purchase_cost' => 62500.00,
                'warranty_expiry' => '2026-09-01',
                'rack_u_position' => 42,
                'status' => 'installed',
                'lifecycle_state' => 'installed',
                'notes' => 'Role: edge router (enum has no `router` member).',
            ],
            [
                // No `ups` member in the asset_type enum -> pdu (power feed).
                'asset_tag' => self::TAG_PREFIX.'-UPS-0001',
                'serial_number' => 'SN-APC-SMT3000-000006',
                'asset_type' => 'pdu',
                'manufacturer' => 'APC',
                'model' => 'Smart-UPS SMT3000RMI2U',
                'vendor' => 'Schneider Electric',
                'purchase_date' => '2023-07-18',
                'purchase_cost' => 148000.00,
                'warranty_expiry' => '2026-07-18',
                'rack_u_position' => 1,
                'status' => 'installed',
                'lifecycle_state' => 'installed',
                'notes' => 'Role: rack UPS / power feed (enum has no `ups` member).',
            ],
            [
                // No `storage` member in the asset_type enum -> ssd.
                'asset_tag' => self::TAG_PREFIX.'-STO-0001',
                'serial_number' => 'SN-SAMSUNG-PM9A3-000007',
                'asset_type' => 'ssd',
                'manufacturer' => 'Samsung',
                'model' => 'PM9A3 7.68TB U.2',
                'vendor' => 'Samsung Semiconductor',
                'purchase_date' => '2024-02-14',
                'purchase_cost' => 78400.00,
                'warranty_expiry' => '2029-02-14',
                'status' => 'assigned',
                'lifecycle_state' => 'assigned',
                'notes' => 'Role: NVMe storage tier (enum has no `storage` member).',
                'parent_tag' => self::TAG_PREFIX.'-SRV-0001',
            ],
            [
                'asset_tag' => self::TAG_PREFIX.'-STO-0002',
                'serial_number' => 'SN-SEAGATE-EXOSX18-000008',
                'asset_type' => 'hdd',
                'manufacturer' => 'Seagate',
                'model' => 'Exos X18 18TB',
                'vendor' => 'Seagate Technology',
                'purchase_date' => '2024-05-02',
                'purchase_cost' => 34900.00,
                'warranty_expiry' => '2029-05-02',
                'status' => 'assigned',
                'lifecycle_state' => 'assigned',
                'notes' => 'Role: backup storage tier.',
                'parent_tag' => self::TAG_PREFIX.'-SRV-0002',
            ],
            [
                'asset_tag' => self::TAG_PREFIX.'-NIC-0001',
                'serial_number' => 'SN-INTEL-X710-000009',
                'asset_type' => 'nic',
                'manufacturer' => 'Intel',
                'model' => 'X710-DA2 10GbE',
                'vendor' => 'Intel',
                'purchase_date' => '2024-02-14',
                'purchase_cost' => 22600.00,
                'warranty_expiry' => '2027-02-14',
                'status' => 'assigned',
                'lifecycle_state' => 'assigned',
                'notes' => 'Dual-port 10GbE uplink card.',
                'parent_tag' => self::TAG_PREFIX.'-SRV-0001',
            ],
            [
                'asset_tag' => self::TAG_PREFIX.'-RAM-0001',
                'serial_number' => 'SN-MICRON-32GDDR4-000010',
                'asset_type' => 'ram_module',
                'manufacturer' => 'Micron',
                'model' => 'MTA36ASF4G72PZ 32GB DDR4-3200',
                'vendor' => 'Micron Technology',
                'purchase_date' => '2024-02-14',
                'purchase_cost' => 11200.00,
                'warranty_expiry' => '2029-02-14',
                'status' => 'assigned',
                'lifecycle_state' => 'assigned',
                'notes' => 'Registered ECC DIMM.',
                'parent_tag' => self::TAG_PREFIX.'-SRV-0001',
            ],
        ];
    }

    /**
     * Relationship blueprint, expressed in asset tags so it stays readable and
     * so ids never have to be hard-coded.
     *
     * @return list<array<string, string>>
     */
    private function relationshipDefinitions(): array
    {
        return [
            [
                'parent_tag' => self::TAG_PREFIX.'-SRV-0001',
                'child_tag' => self::TAG_PREFIX.'-STO-0001',
                'relationship_type' => 'contains',
                'label' => 'NVMe storage tier',
                'notes' => 'Demo: 7.68TB U.2 drive installed in the primary host.',
            ],
            [
                'parent_tag' => self::TAG_PREFIX.'-SRV-0001',
                'child_tag' => self::TAG_PREFIX.'-NIC-0001',
                'relationship_type' => 'contains',
                'label' => '10GbE uplink card',
                'notes' => 'Demo: dual-port NIC installed in the primary host.',
            ],
            [
                'parent_tag' => self::TAG_PREFIX.'-SRV-0001',
                'child_tag' => self::TAG_PREFIX.'-RAM-0001',
                'relationship_type' => 'contains',
                'label' => '32GB DDR4 module',
                'notes' => 'Demo: memory module installed in the primary host.',
            ],
            [
                'parent_tag' => self::TAG_PREFIX.'-SRV-0002',
                'child_tag' => self::TAG_PREFIX.'-STO-0002',
                'relationship_type' => 'contains',
                'label' => 'Backup storage tier',
                'notes' => 'Demo: 18TB SATA drive installed in the secondary host.',
            ],
            [
                'parent_tag' => self::TAG_PREFIX.'-SWT-0001',
                'child_tag' => self::TAG_PREFIX.'-SRV-0001',
                'relationship_type' => 'manages',
                'label' => 'ToR switch port 1/0/12',
                'notes' => 'Demo: access switch serving the primary host.',
            ],
            [
                'parent_tag' => self::TAG_PREFIX.'-SWT-0001',
                'child_tag' => self::TAG_PREFIX.'-SRV-0002',
                'relationship_type' => 'manages',
                'label' => 'ToR switch port 1/0/14',
                'notes' => 'Demo: access switch serving the secondary host.',
            ],
            [
                'parent_tag' => self::TAG_PREFIX.'-RTR-0001',
                'child_tag' => self::TAG_PREFIX.'-SWT-0001',
                'relationship_type' => 'manages',
                'label' => 'Edge router uplink',
                'notes' => 'Demo: edge router feeding the top-of-rack switch.',
            ],
            [
                'parent_tag' => self::TAG_PREFIX.'-UPS-0001',
                'child_tag' => self::TAG_PREFIX.'-SWT-0002',
                'relationship_type' => 'hosted_on',
                'label' => 'Protected power feed',
                'notes' => 'Demo: spare switch cabled to the rack UPS.',
            ],
        ];
    }

    /**
     * Deterministic filler used only when `ROWS['inventory_assets']` exceeds
     * the curated fleet size, so the row matrix stays satisfiable.
     *
     * @return array<string, mixed>
     */
    private function fillerAsset(int $index): array
    {
        $n = str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);

        return [
            'asset_tag' => self::TAG_PREFIX.'-GEN-'.$n,
            'serial_number' => 'SN-DEMO-GENERIC-'.$n,
            'asset_type' => 'other_hardware',
            'manufacturer' => 'Generic',
            'model' => 'Demo Filler Unit '.$n,
            'vendor' => 'Demo Vendor',
            'status' => 'in_stock',
            'lifecycle_state' => 'received',
            'notes' => 'Auto-generated to satisfy DummyDataConfig::ROWS.',
        ];
    }

    private function countOf(string $table): int
    {
        return (int) DB::table($table)->count();
    }
}
