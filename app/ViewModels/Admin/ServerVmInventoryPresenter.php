<?php

declare(strict_types=1);

namespace App\ViewModels\Admin;

use App\Models\Order;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Correlates provisioned PanelAccount VM rows with live Hyper-V host rows.
 *
 * Pure presenter (no DB, no HTTP, no Blade): the controller feeds it the
 * current page of PanelAccount models plus the cached `HyperVClient::listVms()`
 * payload, and it returns plain arrays shaped by the frozen output contract
 * the admin server views render against.
 *
 * Matching rule: `PanelAccount.external_id` (the Hyper-V VMId GUID written by
 * `HyperVClient::createVm()`) equals the live row `vmId`, compared trim +
 * case-insensitively. A blank id on EITHER side never matches.
 */
final class ServerVmInventoryPresenter
{
    /**
     * @param  array<int, mixed>  $panelRows  PanelAccount models (e.g. `$panelAccounts->items()`); array shapes tolerated.
     * @param  array<int, mixed>|null  $liveRows  `HyperVClient::listVms()` rows, or null when host data was not loaded.
     * @return array{rows: array<int, array<string, mixed>>, unmatchedLive: array<int, array<string, mixed>>, matchedCount: int, missingOnHostCount: int, missingUnexpectedCount: int, absentExpectedCount: int, liveTotal: int, liveUnavailable: bool}
     */
    public static function build(array $panelRows, ?array $liveRows): array
    {
        $liveUnavailable = $liveRows === null;
        $liveList = $liveUnavailable ? [] : array_values($liveRows);

        // Index live rows by normalized vmId. Blank ids are never indexed,
        // so they can never match. First row wins on duplicate ids.
        /** @var array<string, mixed> $liveById */
        $liveById = [];
        foreach ($liveList as $live) {
            $id = self::normalizeId(self::liveValue($live, ['vmId', 'vm_id']));
            if ($id === null || isset($liveById[$id])) {
                continue;
            }
            $liveById[$id] = $live;
        }

        $rows = [];
        /** @var array<string, true> $consumedIds */
        $consumedIds = [];
        $matchedCount = 0;

        foreach ($panelRows as $panel) {
            $externalId = self::panelExternalId($panel);
            $normalized = self::normalizeId($externalId);
            $live = ($normalized !== null && isset($liveById[$normalized])) ? $liveById[$normalized] : null;
            $hostMatch = $live !== null;
            if ($hostMatch) {
                $matchedCount++;
                $consumedIds[$normalized] = true;
            }
            $rows[] = self::panelRow($panel, $externalId, $live, $hostMatch);
        }

        $unmatchedLive = [];
        foreach ($liveList as $live) {
            $normalized = self::normalizeId(self::liveValue($live, ['vmId', 'vm_id']));
            if ($normalized !== null && isset($consumedIds[$normalized])) {
                continue;
            }
            $unmatchedLive[] = self::liveRow($live);
        }

        $missingUnexpectedCount = 0;
        $absentExpectedCount = 0;
        foreach ($rows as $row) {
            if (($row['hostPresence'] ?? null) === 'missing') {
                $missingUnexpectedCount++;
            } elseif (($row['hostPresence'] ?? null) === 'absent') {
                $absentExpectedCount++;
            }
        }

        return [
            'rows' => $rows,
            'unmatchedLive' => $unmatchedLive,
            'matchedCount' => $matchedCount,
            'missingOnHostCount' => count($rows) - $matchedCount,
            'missingUnexpectedCount' => $missingUnexpectedCount,
            'absentExpectedCount' => $absentExpectedCount,
            'liveTotal' => count($liveList),
            'liveUnavailable' => $liveUnavailable,
        ];
    }

    /**
     * Byte formatter — delegates to the canonical implementation (DRY).
     */
    public static function fmtBytes(mixed $bytes): string
    {
        return ServerDetailViewModel::fmtBytes($bytes);
    }

    /**
     * @param  array<int, mixed>  $panel
     * @return array<string, mixed>
     */
    private static function panelRow(mixed $panel, ?string $externalId, mixed $live, bool $hostMatch): array
    {
        $meta = self::panelMeta($panel);
        $inner = (isset($meta['meta']) && is_array($meta['meta'])) ? $meta['meta'] : [];

        // Production rows nest host values under meta.meta (HyperVClient::createVm);
        // read nested-first with a flat fallback.
        $vmName = self::firstNonBlank([
            $inner['vmName'] ?? null,
            $inner['name'] ?? null,
            $meta['vmName'] ?? null,
            $meta['name'] ?? null,
        ]);

        $serviceInstance = self::related($panel, 'serviceInstance')
            ?? (is_array($panel) ? ($panel['service_instance'] ?? null) : null);
        $customer = $serviceInstance !== null ? self::related($serviceInstance, 'customer') : null;
        $order = $serviceInstance !== null ? self::related($serviceInstance, 'order') : null;

        $liveState = $live !== null ? self::stringOrNull(self::liveValue($live, ['state'])) : null;

        $builtAt = self::panelBuiltAt($panel);

        $provisionStatus = self::stringOrNull(self::attr($panel, 'status'));
        [$hostPresence, $absentLabel] = self::hostPresence($hostMatch, $provisionStatus);

        return [
            'vmName' => $vmName,
            'username' => self::stringOrNull(self::attr($panel, 'username')),
            'externalId' => $externalId,
            'externalIdShort' => self::shortId($externalId),
            'customerName' => self::customerName($customer),
            'orderNumber' => self::orderField($order, ['order_number']),
            'orderStatus' => self::orderField($order, ['status']),
            'orderUrl' => self::orderUrl($order),
            'provisionStatus' => $provisionStatus,
            'liveState' => $liveState,
            'liveStateTheme' => self::stateTheme($liveState),
            'liveUptime' => $live !== null ? self::stringOrNull(self::liveValue($live, ['uptime'])) : null,
            'liveCpu' => $live !== null ? self::intOrNull(self::liveValue($live, ['cpuUsage', 'cpu_usage'])) : null,
            'liveVcpu' => $live !== null ? self::intOrNull(self::liveValue($live, ['processorCount', 'processor_count'])) : null,
            'liveMemoryAssigned' => $live !== null ? self::intOrNull(self::liveValue($live, ['memoryAssigned', 'memory_assigned'])) : null,
            'liveMemoryDemand' => $live !== null ? self::intOrNull(self::liveValue($live, ['memoryDemand', 'memory_demand'])) : null,
            'hostMatch' => $hostMatch,
            'hostPresence' => $hostPresence,
            'absentLabel' => $absentLabel,
            'builtAtHuman' => $builtAt?->diffForHumans(),
            'builtAtAbsolute' => $builtAt?->format('Y-m-d H:i'),
        ];
    }

    /**
     * Host-presence classification for one panel row.
     *
     * A live match always means 'matched'. Otherwise presence is expected
     * unless the provisioning status (case-insensitive, trimmed) is
     * 'terminated' or 'pending' — those rows are 'absent' with an
     * explanatory label. Every other status (active, suspended,
     * provisioning, null, unknown) is still an alarm: 'missing'.
     *
     * @return array{0: string, 1: string|null}
     */
    private static function hostPresence(bool $hostMatch, ?string $provisionStatus): array
    {
        if ($hostMatch) {
            return ['matched', null];
        }

        $normalized = strtolower(trim($provisionStatus ?? ''));

        if ($normalized === 'terminated') {
            return ['absent', 'removed'];
        }
        if ($normalized === 'pending') {
            return ['absent', 'not provisioned'];
        }

        return ['missing', null];
    }

    /**
     * @return array<string, mixed>
     */
    private static function liveRow(mixed $live): array
    {
        $vmId = self::stringOrNull(self::liveValue($live, ['vmId', 'vm_id']));
        $state = self::stringOrNull(self::liveValue($live, ['state']));

        return [
            'name' => self::stringOrNull(self::liveValue($live, ['name'])),
            'state' => $state,
            'stateTheme' => self::stateTheme($state),
            'uptime' => self::stringOrNull(self::liveValue($live, ['uptime'])),
            'cpuUsage' => self::intOrNull(self::liveValue($live, ['cpuUsage', 'cpu_usage'])),
            'processorCount' => self::intOrNull(self::liveValue($live, ['processorCount', 'processor_count'])),
            'memoryAssigned' => self::intOrNull(self::liveValue($live, ['memoryAssigned', 'memory_assigned'])),
            'memoryDemand' => self::intOrNull(self::liveValue($live, ['memoryDemand', 'memory_demand'])),
            'switchName' => self::stringOrNull(self::liveValue($live, ['switchName', 'switch_name'])),
            'vmId' => $vmId,
            'vmIdShort' => self::shortId($vmId),
        ];
    }

    /**
     * State → badge theme mapping, mirroring
     * resources/views/admin/servers/partials/_live-inventory.blade.php.
     */
    private static function stateTheme(?string $state): string
    {
        $lower = strtolower(trim($state ?? ''));

        if ($lower === 'running') {
            return 'success';
        }
        if ($lower === 'off' || $lower === 'stopped') {
            return 'secondary';
        }
        if ($lower === 'saved' || $lower === 'paused' || $lower === 'paused-critical') {
            return 'warning';
        }

        return 'info';
    }

    /**
     * First 8 chars + '…' + last 4 when longer than 16 chars, else the raw
     * id; '—' when blank.
     */
    private static function shortId(?string $id): string
    {
        if ($id === null || trim($id) === '') {
            return '—';
        }
        $raw = trim($id);

        return strlen($raw) > 16 ? substr($raw, 0, 8).'…'.substr($raw, -4) : $raw;
    }

    /**
     * Normalize a GUID for comparison: trim + lowercase. Blank (null, empty
     * or whitespace-only) on either side yields null and therefore never
     * matches.
     */
    private static function normalizeId(mixed $id): ?string
    {
        if ($id === null || is_array($id) || is_object($id)) {
            return null;
        }
        if (is_bool($id)) {
            return null;
        }
        $trimmed = trim((string) $id);

        return $trimmed === '' ? null : strtolower($trimmed);
    }

    private static function panelExternalId(mixed $panel): ?string
    {
        return self::stringOrNull(self::attr($panel, 'external_id'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function panelMeta(mixed $panel): array
    {
        $meta = self::attr($panel, 'meta');
        if (is_string($meta) && trim($meta) !== '') {
            $decoded = json_decode($meta, true);
            $meta = is_array($decoded) ? $decoded : [];
        }

        return is_array($meta) ? $meta : [];
    }

    private static function panelBuiltAt(mixed $panel): ?Carbon
    {
        return self::asCarbon(self::attr($panel, 'provisioned_at'))
            ?? self::asCarbon(self::attr($panel, 'created_at'));
    }

    private static function customerName(mixed $customer): ?string
    {
        if ($customer === null) {
            return null;
        }

        if (is_array($customer)) {
            $name = self::stringOrNull($customer['full_name'] ?? null);
            if ($name !== null) {
                return $name;
            }
            $user = $customer['user'] ?? null;

            return self::stringOrNull(is_array($user) ? ($user['email'] ?? null) : null);
        }

        // serviceInstance.customer.full_name ?? serviceInstance.customer.user.email
        $fullName = self::stringOrNull(self::safeGet($customer, 'full_name'));
        if ($fullName !== null) {
            return $fullName;
        }

        $user = self::related($customer, 'user');

        return self::stringOrNull(is_array($user) ? ($user['email'] ?? null) : self::safeGet($user, 'email'));
    }

    /**
     * @param  array<int, string>  $keys
     */
    private static function orderField(mixed $order, array $keys): ?string
    {
        if ($order === null) {
            return null;
        }

        foreach ($keys as $key) {
            $value = is_array($order) ? ($order[$key] ?? null) : self::safeGet($order, $key);
            $string = self::stringOrNull($value);
            if ($string !== null) {
                return $string;
            }
        }

        return null;
    }

    private static function orderUrl(mixed $order): ?string
    {
        if ($order === null) {
            return null;
        }

        try {
            if ($order instanceof Order) {
                return route('admin.orders.show', $order);
            }
            if (is_array($order) && isset($order['id']) && trim((string) $order['id']) !== '') {
                return route('admin.orders.show', $order['id']);
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Read a plain attribute from a model or an array row.
     */
    private static function attr(mixed $row, string $key): mixed
    {
        if (is_array($row)) {
            return $row[$key] ?? null;
        }
        if ($row instanceof Model) {
            return $row->getAttribute($key);
        }
        if (is_object($row)) {
            return self::safeGet($row, $key);
        }

        return null;
    }

    /**
     * Read a live-row value by candidate keys (camelCase first, snake_case
     * fallback, mirroring the _live-inventory partial).
     *
     * @param  array<int, string>  $keys
     */
    private static function liveValue(mixed $live, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (is_array($live) && array_key_exists($key, $live)) {
                $value = $live[$key];
                if ($value !== null && $value !== '') {
                    return $value;
                }
            } elseif (is_object($live)) {
                $value = self::safeGet($live, $key);
                if ($value !== null && $value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * Read a loaded relation (or array entry) without triggering lazy loads:
     * unloaded relations resolve to null so the presenter never queries.
     */
    private static function related(mixed $row, string $name): mixed
    {
        if (is_array($row)) {
            return $row[$name] ?? null;
        }
        if ($row instanceof Model) {
            return $row->relationLoaded($name) ? $row->getRelation($name) : null;
        }

        return null;
    }

    private static function safeGet(mixed $object, string $key): mixed
    {
        if (! is_object($object)) {
            return null;
        }

        try {
            return $object->{$key} ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, mixed>  $candidates
     */
    private static function firstNonBlank(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $string = self::stringOrNull($candidate);
            if ($string !== null) {
                return $string;
            }
        }

        return null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    private static function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value) || is_array($value) || is_object($value)) {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private static function asCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }
        if ($value instanceof CarbonInterface || $value instanceof DateTimeInterface) {
            return Carbon::instance($value);
        }
        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
