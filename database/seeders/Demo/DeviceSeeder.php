<?php

namespace Database\Seeders\Demo;

use Database\Seeders\Demo\Traits\WithIdempotentSeed;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Demo inventory: `devices` + `inventory_items`.
 *
 * SCOPE / INDEPENDENCE
 * --------------------
 * This seeder is deliberately self-contained. It writes to exactly four
 * tables: `devices`, `inventory_items`, `manufacturers` and `device_types`.
 * It references nothing outside them: `datacenter_id`, `rack_id` and the
 * `licenses.device_id` link are all left alone so the file can run
 * before, after, or without the datacenter/rack/server seeders. Every
 * `inventory_items` row points at a device asset created right here.
 *
 * It also stays clear of the legacy `reference-devices.sql` import:
 * all demo tags use the `DEMO-*` prefix, so a later reference import cannot
 * collide with (or be clobbered by) this data.
 *
 * DEVICE TYPES CARRY THE CLASSIFICATION
 * -------------------------------------
 * Devices carry no type column; their classification lives on the linked
 * `device_types.category` (server, switch, pdu, other_hardware). This seeder
 * therefore upserts the manufacturers and device types for the curated
 * fleet on the fly — `manufacturers` by unique name, `device_types` by the
 * unique (manufacturer_id, model) pair — and links each device via
 * `device_type_id`. Categories mirror the legacy heuristics:
 * server→server, switch→switch, pdu→pdu, router/UPS roles→other_hardware/pdu.
 * Rows are written with `seedRowOnce()` semantics for devices (firstOrCreate
 * on the unique `asset_tag`), so a hand-edited demo asset survives a re-run.
 *
 * COMPONENTS ARE `inventory_items`, NOT ASSETS
 * --------------------------------------------
 * Drives, NICs and DIMMs were split out of `devices` into the new
 * `inventory_items` table. This seeder therefore emits no `ssd`/`hdd`/`nic`/
 * `ram_module` asset rows; items link directly to their device via
 * `device_id`.
 * `status` on an item is the same lifecycle enum as on an asset.
 *
 * `status` is the single lifecycle enum: ordered, received, in_stock,
 * installed, assigned, maintenance, retired, disposed.
 *
 * IDEMPOTENCY
 * -----------
 * Assets are written with `seedRowOnce()` (firstOrCreate on the unique
 * `asset_tag`), so a hand-edited demo asset survives a re-run. Items
 * use `seedRowOnce()` against `inventory_items`; the table declares no
 * natural key in `DummyDataConfig::NATURAL_KEYS`, so the whole (deterministic)
 * row payload is the match and re-runs insert nothing twice.
 * `ROWS['devices']` ever be raised above the curated fleet size.
 */
class DeviceSeeder extends Seeder
{
    use WithIdempotentSeed;

    /** Prefix that keeps demo tags disjoint from the legacy reference import. */
    private const TAG_PREFIX = 'DEMO';

    public function run(): void
    {
        $this->seedAssets();
        $this->seedItems();

        $this->command?->info(sprintf(
            'DeviceSeeder: %d devices, %d inventory_items.',
            $this->countOf('devices'),
            $this->countOf('inventory_items'),
        ));
    }

    /**
     * Seed the curated fleet, then top up to `ROWS['devices']`.
     *
     * Definitions are ordered parents-first; components are seeded separately
     * as `inventory_items` by seedItems(), not here.
     *
     * @return array<string, int> asset_tag => id
     */
    private function seedAssets(): array
    {
        $ids = [];

        foreach ($this->assetDefinitions() as $definition) {
            $category = $definition['category'];
            unset($definition['category']);

            $ids[$definition['asset_tag']] = (int) $this->seedDevice($definition, $category);
        }

        $shortfall = DummyDataConfig::minRows('devices') - count($ids);

        if ($shortfall > 0) {
            $base = count($ids);

            for ($i = 0; $i < $shortfall; $i++) {
                $filler = $this->fillerAsset($base + $i);
                $category = $filler['category'];
                unset($filler['category']);

                $this->seedDevice($filler, $category);
            }
        }

        return $ids;
    }

    /**
     * Link a curated device row to its catalog type: upsert the manufacturer
     * and the device type (created with $category when absent), then insert
     * the device only when its asset_tag is unknown.
     *
     * @param  array<string, mixed>  $definition
     */
    private function seedDevice(array $definition, string $category): int|string|null
    {
        $manufacturerName = $definition['manufacturer'] ?? null;
        $model = $definition['model'] ?? null;

        if ($manufacturerName !== null && $model !== null) {
            $manufacturerId = $this->seedManufacturer((string) $manufacturerName);
            $definition['device_type_id'] = $this->seedDeviceType($manufacturerId, (string) $model, $category);
        }

        return $this->seedRowOnce('devices', $definition);
    }

    /**
     * Upsert a manufacturer by unique name; ids are looked up first so a
     * re-run never mints a duplicate slug.
     */
    private function seedManufacturer(string $name): int
    {
        $existing = DB::table('manufacturers')->where('name', $name)->first(['id']);

        if ($existing !== null) {
            return (int) $existing->id;
        }

        return (int) DB::table('manufacturers')->insertGetId([
            'name' => $name,
            'slug' => $this->uniqueSlug('manufacturers', $name),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Upsert a device type by the unique (manufacturer_id, model) pair;
     * absent types are created with $category.
     */
    private function seedDeviceType(int $manufacturerId, string $model, string $category): int
    {
        $existing = DB::table('device_types')
            ->where('manufacturer_id', $manufacturerId)
            ->where('model', $model)
            ->first(['id']);

        if ($existing !== null) {
            return (int) $existing->id;
        }

        $manufacturerName = (string) DB::table('manufacturers')->where('id', $manufacturerId)->value('name');

        return (int) DB::table('device_types')->insertGetId([
            'manufacturer_id' => $manufacturerId,
            'model' => $model,
            'slug' => $this->uniqueSlug('device_types', $manufacturerName.' '.$model),
            'category' => $category,
            'u_height' => 1,
            'is_full_depth' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * A slug derived from $nameBase that is free in $table; suffixed
     * -2, -3, ... when the base is taken.
     */
    private function uniqueSlug(string $table, string $nameBase): string
    {
        $base = Str::slug($nameBase);
        $base = $base === '' ? 'entry' : $base;

        $candidate = $base;
        $suffix = 2;

        while (DB::table($table)->where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Server components, seeded as `inventory_items` attached directly to
     * their device asset via `device_id`.
     *
     * Device ids are resolved with a single `asset_tag` lookup rather than
     * threading the ids returned by `seedAssets()` through, so the config-
     * count-dependent filler ids stay out of the picture.
     */
    private function seedItems(): void
    {
        $deviceIds = DB::table('devices')
            ->whereIn('asset_tag', [
                self::TAG_PREFIX.'-SRV-0001',
                self::TAG_PREFIX.'-SRV-0002',
            ])
            ->pluck('id', 'asset_tag');

        foreach ($this->itemDefinitions() as $definition) {
            $deviceTag = $definition['device_tag'];
            unset($definition['device_tag']);

            $deviceId = $deviceIds[$deviceTag] ?? null;

            if ($deviceId === null) {
                continue;
            }

            $definition['device_id'] = $deviceId;

            $this->seedRowOnce('inventory_items', $definition);
        }
    }

    /**
     * The curated demo component fleet: drives, an uplink NIC and a DIMM,
     * expressed with a `device_tag` so ids never have to be hard-coded.
     *
     * @return list<array<string, mixed>>
     */
    private function itemDefinitions(): array
    {
        return [
            [
                'device_tag' => self::TAG_PREFIX.'-SRV-0001',
                'name' => 'PM9A3 7.68TB U.2',
                'sku' => 'Samsung-PM9A3-7.68',
                'part_number' => 'PM9A3-7.68TB',
                'serial_number' => 'SN-SAMSUNG-PM9A3-000007',
                'asset_tag' => self::TAG_PREFIX.'-STO-0001',
                'label' => 'NVMe storage tier',
                'status' => 'assigned',
                'notes' => 'Demo: 7.68TB U.2 drive installed in the primary host.',
                'discovered' => false,
            ],
            [
                'device_tag' => self::TAG_PREFIX.'-SRV-0002',
                'name' => 'Exos X18 18TB',
                'sku' => 'Seagate-ExosX18-18',
                'part_number' => 'Exos X18 18TB',
                'serial_number' => 'SN-SEAGATE-EXOSX18-000008',
                'asset_tag' => self::TAG_PREFIX.'-STO-0002',
                'label' => 'Backup storage tier',
                'status' => 'assigned',
                'notes' => 'Demo: 18TB SATA drive installed in the secondary host.',
                'discovered' => false,
            ],
            [
                'device_tag' => self::TAG_PREFIX.'-SRV-0001',
                'name' => 'X710-DA2 10GbE',
                'sku' => 'Intel-X710-DA2',
                'part_number' => 'X710-DA2',
                'serial_number' => 'SN-INTEL-X710-000009',
                'asset_tag' => self::TAG_PREFIX.'-NIC-0001',
                'label' => '10GbE uplink card',
                'status' => 'assigned',
                'notes' => 'Dual-port 10GbE uplink card.',
                'discovered' => false,
            ],
            [
                'device_tag' => self::TAG_PREFIX.'-SRV-0001',
                'name' => 'MTA36ASF4G72PZ 32GB DDR4-3200',
                'sku' => 'Micron-MTA36ASF4G72PZ',
                'part_number' => 'MTA36ASF4G72PZ',
                'serial_number' => 'SN-MICRON-32GDDR4-000010',
                'asset_tag' => self::TAG_PREFIX.'-RAM-0001',
                'label' => 'Registered ECC DIMM',
                'status' => 'assigned',
                'notes' => 'Registered ECC DIMM.',
                'discovered' => false,
            ],
        ];
    }

    /**
     * The curated demo fleet: 2 servers, 2 switches, an edge router and a
     * UPS feed. Server components are seeded as `inventory_items` by
     * `seedItems()`, not as assets.
     *
     * @return list<array<string, mixed>>
     */
    private function assetDefinitions(): array
    {
        return [
            [
                'asset_tag' => self::TAG_PREFIX.'-SRV-0001',
                'serial_number' => 'SN-DELL-R650-000001',
                'category' => 'server',
                'manufacturer' => 'Dell',
                'model' => 'PowerEdge R650',
                'vendor' => 'Dell Technologies',
                'purchase_date' => '2024-02-14',
                'purchase_cost' => 489000.00,
                'warranty_expiry' => '2027-02-14',
                'rack_u_position' => 12,
                'status' => 'installed',
                'notes' => 'Demo primary hypervisor host.',
            ],
            [
                'asset_tag' => self::TAG_PREFIX.'-SRV-0002',
                'serial_number' => 'SN-HPE-DL380-000002',
                'category' => 'server',
                'manufacturer' => 'HPE',
                'model' => 'ProLiant DL380 Gen10',
                'vendor' => 'Hewlett Packard Enterprise',
                'purchase_date' => '2024-05-02',
                'purchase_cost' => 421500.00,
                'warranty_expiry' => '2027-05-02',
                'rack_u_position' => 14,
                'status' => 'installed',
                'notes' => 'Demo secondary hypervisor host.',
            ],
            [
                'asset_tag' => self::TAG_PREFIX.'-SWT-0001',
                'serial_number' => 'SN-JNPR-EX4300-000003',
                'category' => 'switch',
                'manufacturer' => 'Juniper',
                'model' => 'EX4300-48T',
                'vendor' => 'Juniper Networks',
                'purchase_date' => '2023-11-20',
                'purchase_cost' => 315000.00,
                'warranty_expiry' => '2026-11-20',
                'rack_u_position' => 40,
                'status' => 'installed',
                'notes' => 'Demo top-of-rack access switch.',
            ],
            [
                'asset_tag' => self::TAG_PREFIX.'-SWT-0002',
                'serial_number' => 'SN-ARISTA-7050X-000004',
                'category' => 'switch',
                'manufacturer' => 'Arista',
                'model' => '7050SX3-48YC8',
                'vendor' => 'Arista Networks',
                'purchase_date' => '2024-01-09',
                'purchase_cost' => 528000.00,
                'warranty_expiry' => '2027-01-09',
                'rack_u_position' => 41,
                'status' => 'in_stock',
                'notes' => 'Demo spare aggregation switch, not yet cabled.',
            ],
            [
                // Edge router: no `router` category -> other_hardware, role recorded in notes.
                'asset_tag' => self::TAG_PREFIX.'-RTR-0001',
                'serial_number' => 'SN-MIKROTIK-CCR2004-000005',
                'category' => 'other_hardware',
                'manufacturer' => 'MikroTik',
                'model' => 'CCR2004-16G-2S+',
                'vendor' => 'MikroTik',
                'purchase_date' => '2023-09-01',
                'purchase_cost' => 62500.00,
                'warranty_expiry' => '2026-09-01',
                'rack_u_position' => 42,
                'status' => 'installed',
                'notes' => 'Role: edge router (no `router` category; classified as other_hardware).',
            ],
            [
                // Rack UPS / power feed: no `ups` category -> pdu, role recorded in notes.
                'asset_tag' => self::TAG_PREFIX.'-UPS-0001',
                'serial_number' => 'SN-APC-SMT3000-000006',
                'category' => 'pdu',
                'manufacturer' => 'APC',
                'model' => 'Smart-UPS SMT3000RMI2U',
                'vendor' => 'Schneider Electric',
                'purchase_date' => '2023-07-18',
                'purchase_cost' => 148000.00,
                'warranty_expiry' => '2026-07-18',
                'rack_u_position' => 1,
                'status' => 'installed',
                'notes' => 'Role: rack UPS / power feed (classified as pdu).',
            ],
        ];
    }

    /**
     * Deterministic filler used only when `ROWS['devices']` exceeds
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
            'category' => 'other_hardware',
            'manufacturer' => 'Generic',
            'model' => 'Demo Filler Unit '.$n,
            'vendor' => 'Demo Vendor',
            'status' => 'in_stock',
            'notes' => 'Auto-generated to satisfy DummyDataConfig::ROWS.',
        ];
    }

    private function countOf(string $table): int
    {
        return (int) DB::table($table)->count();
    }
}
