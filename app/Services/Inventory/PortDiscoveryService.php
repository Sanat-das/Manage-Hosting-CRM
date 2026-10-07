<?php

namespace App\Services\Inventory;

use App\Models\DevicePort;
use App\Models\InventoryAsset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Imports SNMP interface data into a linked inventory asset's device_ports.
 *
 * Called from the polling pipeline (PollHostBatch) when the module config
 * toggle `auto_ports` is on, and from the `snmp:sync-ports` reconcile command.
 * The import is idempotent: a re-poll updates the rows it created and never
 * duplicates them, matching by ifIndex, then ifDescr, then port name.
 *
 * Human metadata is never destroyed: a matched manual port only receives SNMP
 * telemetry backfills, an interface absent from the payload is marked stale
 * rather than deleted, and one bad row is isolated so it cannot abort a
 * device's whole sync.
 */
final class PortDiscoveryService
{
    /** IF-MIB ifType for a software loopback interface. */
    private const IF_TYPE_LOOPBACK = 24;

    /** ifSpeed sentinel for "unknown" (2^32 - 1). */
    private const IF_SPEED_UNKNOWN = 4294967295;

    /**
     * Sync one asset's ports against a collector payload's interface list.
     *
     * @param  array<int, array<string, mixed>>  $interfaces  SnmpCollector payload['interfaces']
     * @return array{created: int, updated: int, skipped: int, stale: int}
     */
    public function syncForAsset(InventoryAsset $asset, array $interfaces, bool $dryRun = false): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'stale' => 0];
        $now = Carbon::now();
        $presentIndices = [];

        foreach ($interfaces as $interface) {
            if (! is_array($interface)) {
                $counts['skipped']++;

                continue;
            }

            $index = $this->interfaceIndex($interface);

            if ($index !== null) {
                $presentIndices[$index] = true;
            }

            try {
                $counts[$this->syncInterface($asset, $interface, $now, $dryRun)]++;
            } catch (Throwable $e) {
                // One malformed or conflicting row must never abort the sync.
                $counts['skipped']++;

                Log::warning('SNMP port import failed for one interface.', [
                    'asset_id' => $asset->id,
                    'if_index' => $index,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $counts['stale'] = $this->markStale($asset, array_keys($presentIndices), $dryRun);

        return $counts;
    }

    /**
     * Apply one interface: match an existing port through the dedupe cascade,
     * otherwise create a new snmp-sourced port.
     *
     * @param  array<string, mixed>  $interface
     * @return 'created'|'updated'|'skipped'
     */
    private function syncInterface(InventoryAsset $asset, array $interface, Carbon $now, bool $dryRun): string
    {
        $index = $this->interfaceIndex($interface);

        if ($index === null) {
            return 'skipped';
        }

        if ($this->intOrNull($interface['type'] ?? null) === self::IF_TYPE_LOOPBACK) {
            return 'skipped';
        }

        $name = $this->cleanString($interface['name'] ?? null);
        $descr = $this->cleanString($interface['descr'] ?? null);

        if ($name === null && $descr === null) {
            return 'skipped';
        }

        $descrKey = $descr ?? $name;
        $port = $this->findMatch($asset, $index, $descrKey, $name);

        if ($port !== null) {
            return $this->applyTelemetry($port, $interface, $index, $descrKey, $now, $dryRun);
        }

        return $this->createPort($asset, $interface, $index, $descrKey, $name ?? $descr, $now, $dryRun);
    }

    /**
     * Dedupe cascade: (asset_id, snmp_if_index) → (asset_id, snmp_if_descr)
     * → (asset_id, name).
     */
    private function findMatch(InventoryAsset $asset, int $index, ?string $descrKey, ?string $name): ?DevicePort
    {
        $byIndex = DevicePort::query()
            ->where('inventory_asset_id', $asset->id)
            ->where('snmp_if_index', $index)
            ->first();

        if ($byIndex !== null) {
            return $byIndex;
        }

        if ($descrKey !== null) {
            $byDescr = DevicePort::query()
                ->where('inventory_asset_id', $asset->id)
                ->where('snmp_if_descr', $descrKey)
                ->first();

            if ($byDescr !== null) {
                return $byDescr;
            }
        }

        if ($name !== null) {
            return DevicePort::query()
                ->where('inventory_asset_id', $asset->id)
                ->where('name', $name)
                ->first();
        }

        return null;
    }

    /**
     * Update a matched port. A source=snmp row receives the full mapped
     * telemetry and always re-heals its ifIndex/ifDescr linkage (so a
     * renumbered interface cannot duplicate). A source=manual row is never
     * converted: only SNMP telemetry columns are backfilled.
     *
     * @param  array<string, mixed>  $interface
     */
    private function applyTelemetry(
        DevicePort $port,
        array $interface,
        int $index,
        ?string $descrKey,
        Carbon $now,
        bool $dryRun,
    ): string {
        $status = $this->mapStatus($interface);

        if ($port->source === 'snmp') {
            $attributes = [
                'snmp_if_index' => $index,
                'snmp_if_descr' => $descrKey,
                'last_synced_at' => $now,
                'port_type' => $this->mapPortType($interface['type'] ?? null),
                'speed_bps' => $this->mapSpeed($interface['speed'] ?? null),
                'status' => $status,
                'mac_address' => $this->normalizeMac($interface['physAddress'] ?? null),
            ];
        } else {
            $attributes = [
                'snmp_if_descr' => $descrKey,
                'status' => $status,
                'last_synced_at' => $now,
            ];

            if ($port->snmp_if_index === null) {
                $attributes['snmp_if_index'] = $index;
            }

            if ($port->mac_address === null || $port->mac_address === '') {
                $mac = $this->normalizeMac($interface['physAddress'] ?? null);

                if ($mac !== null) {
                    $attributes['mac_address'] = $mac;
                }
            }
        }

        if (! $dryRun) {
            $port->forceFill($attributes)->save();
        }

        return 'updated';
    }

    /**
     * Create a new snmp-sourced port. The display name is ifName ?: ifDescr;
     * a collision with an existing name on the asset gets an ` (if{index})`
     * suffix so the unique (asset_id, name) index is never violated.
     *
     * @param  array<string, mixed>  $interface
     */
    private function createPort(
        InventoryAsset $asset,
        array $interface,
        int $index,
        ?string $descrKey,
        string $displayName,
        Carbon $now,
        bool $dryRun,
    ): string {
        $name = $displayName;

        if (DevicePort::query()->where('inventory_asset_id', $asset->id)->where('name', $name)->exists()) {
            $name = $displayName.' (if'.$index.')';
        }

        if (! $dryRun) {
            DevicePort::create([
                'inventory_asset_id' => $asset->id,
                'name' => $name,
                'port_type' => $this->mapPortType($interface['type'] ?? null),
                'speed_bps' => $this->mapSpeed($interface['speed'] ?? null),
                'status' => $this->mapStatus($interface),
                'mac_address' => $this->normalizeMac($interface['physAddress'] ?? null),
                'source' => 'snmp',
                'snmp_if_index' => $index,
                'snmp_if_descr' => $descrKey,
                'last_synced_at' => $now,
            ]);
        }

        return 'created';
    }

    /**
     * Mark existing snmp-sourced ports whose interface is absent from the
     * payload as `unknown` (never delete). The human last_synced_at is left
     * untouched — the row is stale, not freshly observed.
     *
     * @param  list<int>  $presentIndices
     */
    private function markStale(InventoryAsset $asset, array $presentIndices, bool $dryRun): int
    {
        $query = DevicePort::query()
            ->where('inventory_asset_id', $asset->id)
            ->where('source', 'snmp')
            ->whereNotNull('snmp_if_index');

        if ($presentIndices !== []) {
            $query->whereNotIn('snmp_if_index', $presentIndices);
        }

        $stale = $query->get();

        if (! $dryRun && $stale->isNotEmpty()) {
            DevicePort::query()->whereIn('id', $stale->modelKeys())->update(['status' => 'unknown']);
        }

        return $stale->count();
    }

    /**
     * @param  array<string, mixed>  $interface
     */
    private function interfaceIndex(array $interface): ?int
    {
        return $this->intOrNull($interface['index'] ?? null);
    }

    /**
     * Map an IF-MIB ifType onto DevicePort::PORT_TYPES.
     */
    private function mapPortType(mixed $type): string
    {
        return match ($this->intOrNull($type)) {
            6 => 'ethernet',
            161 => 'lag',
            135, 136 => 'vlan',
            24 => 'loopback',
            1 => 'other',
            default => 'other',
        };
    }

    /**
     * ifSpeed in bits/s; the 4294967295 "unknown" sentinel maps to null.
     */
    private function mapSpeed(mixed $speed): ?int
    {
        $speed = $this->intOrNull($speed);

        if ($speed === null || $speed === self::IF_SPEED_UNKNOWN) {
            return null;
        }

        return $speed;
    }

    /**
     * adminStatus 2 → disabled; otherwise operStatus 1→up, 2/7→down,
     * 6→disabled, anything else → unknown.
     *
     * @param  array<string, mixed>  $interface
     */
    private function mapStatus(array $interface): string
    {
        if ($this->intOrNull($interface['adminStatus'] ?? null) === 2) {
            return 'disabled';
        }

        $oper = $this->intOrNull($interface['operStatus'] ?? $interface['oper_status'] ?? null);

        return match ($oper) {
            1 => 'up',
            2, 7 => 'down',
            6 => 'disabled',
            default => 'unknown',
        };
    }

    /**
     * Normalize a MAC to UPPER colon-separated hex, or null when empty.
     */
    private function normalizeMac(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $hex = preg_replace('/[^0-9A-Fa-f]/', '', $raw) ?? '';

        if ($hex === '') {
            return null;
        }

        return implode(':', str_split(strtoupper($hex), 2));
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
