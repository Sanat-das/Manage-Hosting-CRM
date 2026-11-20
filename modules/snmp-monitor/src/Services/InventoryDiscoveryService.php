<?php

declare(strict_types=1);

namespace Modules\SnmpMonitor\Services;

use App\Models\Device;
use App\Models\DeviceType;
use App\Models\Manufacturer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\SnmpMonitor\Models\SnmpTarget;

/**
 * Turns a successful poll's SNMP identity into a core devices row and
 * links it back to the snmp_targets row. Called from PollHostBatch on the same
 * success path as the IPAM liveness stamp, behind its own try/catch: a failure
 * here MUST never break or fail a poll that already answered.
 *
 * Gated by the module config toggle `auto_inventory` (default enabled). The
 * toggle is all-or-nothing: when off, neither the target's identity columns nor
 * the core device row are touched.
 *
 * Reuse over duplication: a target that already links an asset keeps it; a
 * target whose device was discovered before (same derived asset_tag) reuses
 * that asset. Human-edited fields are never overwritten — only a NULL
 * manufacturer is backfilled, and status/notes are written on creation only.
 *
 * Newly created devices must not end up without a catalog type (NetBox
 * requires a device type), so creation resolves-or-creates the manufacturer
 * (enterprise match, else 'Unknown') and a placeholder device type per
 * category ('Discovered Server' / 'Discovered Switch' / 'Discovered PDU' /
 * 'Discovered Device'), linking the device via device_type_id.
 */
final class InventoryDiscoveryService
{
    /** Note written once, on creation; never rewritten on later polls. */
    private const DISCOVERY_NOTE = 'Discovered via SNMP poll.';

    /**
     * sysObjectID enterprise prefixes that identify a network appliance.
     * Longest-prefix wins, so a more specific registration always beats a
     * broader one. Everything else falls back to a server/other heuristic.
     *
     * @var array<string, array{0: string, 1: string}> [category, manufacturer]
     */
    private const ENTERPRISE_MAP = [
        '1.3.6.1.4.1.9' => ['switch', 'Cisco'],
        '1.3.6.1.4.1.11' => ['switch', 'HP'],
        '1.3.6.1.4.1.318' => ['switch', 'APC'],
        '1.3.6.1.4.1.2636' => ['switch', 'Juniper'],
        '1.3.6.1.4.1.4526' => ['switch', 'Netgear'],
        '1.3.6.1.4.1.14988' => ['switch', 'MikroTik'],
        '1.3.6.1.4.1.30065' => ['switch', 'Arista'],
    ];

    /**
     * Persist the device identity on the target and link its device.
     *
     * @param  array<string, mixed>  $payload  SnmpCollector::collect() output
     * @param  array<string, mixed>  $config  decrypted snmp-monitor product config
     */
    public function discover(SnmpTarget $target, array $payload, array $config): void
    {
        if (! (bool) ($config['auto_inventory'] ?? true)) {
            return;
        }

        $sysName = $this->cleanString($payload['hostname'] ?? null);
        $sysDescr = $this->cleanString($payload['os'] ?? null);
        $sysObjectId = $this->cleanString($payload['sys_object_id'] ?? null);

        $asset = $this->resolveAsset($target, $sysName, $sysObjectId);

        $target->forceFill([
            'sys_name' => $sysName,
            'sys_descr' => $sysDescr,
            'sys_object_id' => $sysObjectId,
            'last_discovered_at' => Carbon::now(),
            'device_id' => $asset->id,
        ])->save();
    }

    /**
     * Resolve the linked asset, reusing rather than duplicating: an existing
     * target link first, then the derived asset_tag, then a fresh row.
     */
    private function resolveAsset(SnmpTarget $target, ?string $sysName, ?string $sysObjectId): Device
    {
        $linkedId = $target->device_id;

        if ($linkedId !== null) {
            $linked = Device::query()->find($linkedId);

            if ($linked !== null) {
                return $this->backfillManufacturer($linked, $sysObjectId);
            }
        }

        $tag = $this->assetTagFor($sysName, (string) $target->host, (int) $target->id);
        $existing = Device::query()->where('asset_tag', $tag)->first();

        if ($existing !== null) {
            return $this->backfillManufacturer($existing, $sysObjectId);
        }

        return $this->backfillManufacturer($this->createAsset($target, $tag, $sysObjectId), $sysObjectId);
    }

    /**
     * Create the asset with the classified category and manufacturer, linked
     * to a (resolved or created) placeholder device type. status uses the
     * column default (in_stock); notes is the one-time discovery marker.
     */
    private function createAsset(SnmpTarget $target, string $tag, ?string $sysObjectId): Device
    {
        $match = $this->matchEnterprise($sysObjectId);

        $category = $match !== null
            ? $match[0]
            : (in_array($target->target_os, [SnmpTarget::OS_LINUX, SnmpTarget::OS_WINDOWS], true)
                ? 'server'
                : 'other_hardware');

        $manufacturerName = $match !== null ? $match[1] : 'Unknown';
        $manufacturerId = $this->resolveManufacturerId($manufacturerName);
        $deviceTypeId = $this->resolveDeviceTypeId($manufacturerId, $category);

        return Device::create([
            'asset_tag' => $tag,
            'device_type_id' => $deviceTypeId,
            'manufacturer' => $match !== null ? $match[1] : null,
            'notes' => self::DISCOVERY_NOTE,
        ]);
    }

    /**
     * Resolve the manufacturer by unique name, creating it when absent.
     */
    private function resolveManufacturerId(string $name): int
    {
        $existing = Manufacturer::query()->where('name', $name)->first(['id']);

        if ($existing !== null) {
            return (int) $existing->id;
        }

        $slug = Str::slug($name);
        $slug = $slug === '' ? 'manufacturer' : $slug;
        $candidate = $slug;
        $suffix = 2;

        while (Manufacturer::query()->where('slug', $candidate)->exists()) {
            $candidate = $slug.'-'.$suffix;
            $suffix++;
        }

        return (int) Manufacturer::query()->create(['name' => $name, 'slug' => $candidate])->id;
    }

    /**
     * Resolve a placeholder device type for a discovery class, creating it
     * when absent. The model is deliberately generic — SNMP gives an
     * identity class, not a part number — and stable so re-discovery reuses
     * the same type instead of minting one per poll.
     */
    private function resolveDeviceTypeId(int $manufacturerId, string $category): int
    {
        $model = match ($category) {
            'server' => 'Discovered Server',
            'switch' => 'Discovered Switch',
            'pdu' => 'Discovered PDU',
            default => 'Discovered Device',
        };

        $existing = DeviceType::query()
            ->where('manufacturer_id', $manufacturerId)
            ->where('model', $model)
            ->first(['id']);

        if ($existing !== null) {
            return (int) $existing->id;
        }

        $manufacturerName = (string) Manufacturer::query()->whereKey($manufacturerId)->value('name');
        $base = Str::slug($manufacturerName.' '.$model);
        $base = $base === '' ? 'discovered-device' : $base;
        $candidate = $base;
        $suffix = 2;

        while (DeviceType::query()->where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return (int) DeviceType::query()->create([
            'manufacturer_id' => $manufacturerId,
            'model' => $model,
            'slug' => $candidate,
            'category' => $category,
            'u_height' => 1,
            'is_full_depth' => true,
        ])->id;
    }

    /**
     * Backfill the manufacturer from the enterprise map ONLY when the column
     * is empty. A non-null human value and every other field (model, status,
     * notes, tag, type) are left untouched.
     */
    private function backfillManufacturer(Device $asset, ?string $sysObjectId): Device
    {
        $match = $this->matchEnterprise($sysObjectId);

        if ($match === null) {
            return $asset;
        }

        if ($asset->manufacturer !== null && $asset->manufacturer !== '') {
            return $asset;
        }

        $asset->manufacturer = $match[1];
        $asset->save();

        return $asset;
    }

    /**
     * Match a sysObjectID against the enterprise map. Returns the longest
     * matching prefix's [category, manufacturer], or null when none match.
     *
     * @return array{0: string, 1: string}|null
     */
    private function matchEnterprise(?string $sysObjectId): ?array
    {
        if ($sysObjectId === null || $sysObjectId === '') {
            return null;
        }

        $best = null;

        foreach (self::ENTERPRISE_MAP as $prefix => $match) {
            if ($sysObjectId !== $prefix && ! str_starts_with($sysObjectId, $prefix.'.')) {
                continue;
            }

            if ($best === null || strlen($prefix) > strlen($best)) {
                $best = $prefix;
            }
        }

        return $best !== null ? self::ENTERPRISE_MAP[$best] : null;
    }

    /**
     * Deterministic asset tag: SNMP-<sanitized sysName, else host>. Collisions
     * among live rows are resolved by appending the suffix: -2, -3, ... A
     * soft-deleted row does not reserve its tag, so it is reusable.
     */
    private function assetTagFor(?string $sysName, string $host, int $targetId): string
    {
        $segment = $this->sanitizeTagSegment((string) $sysName);

        if ($segment === '') {
            $segment = $this->sanitizeTagSegment($host);
        }

        if ($segment === '') {
            $segment = 'TARGET'.$targetId;
        }

        $base = 'SNMP-'.$segment;
        $candidate = $base;
        $suffix = 2;

        while (Device::query()->where('asset_tag', $candidate)->exists()) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Uppercase to [A-Z0-9_-], collapsing any other run to a single '-',
     * trimming separators and truncating to a sane length.
     */
    private function sanitizeTagSegment(string $value): string
    {
        $collapsed = preg_replace('/[^A-Z0-9_-]+/', '-', strtoupper(trim($value))) ?? '';
        $trimmed = trim($collapsed, '-_');

        if (strlen($trimmed) > 60) {
            $trimmed = rtrim(substr($trimmed, 0, 60), '-_');
        }

        return $trimmed;
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
