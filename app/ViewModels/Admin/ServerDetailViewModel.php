<?php

declare(strict_types=1);

namespace App\ViewModels\Admin;

use App\Models\Server;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Pre-parsed view data for the admin server detail page.
 *
 * Ports ALL inline @php parsing from resources/views/admin/servers/show.blade.php
 * (header block, metric-cards block, Hyper-V transport/RAM/storage block) into
 * typed properties so the blade stays presentation-only. The controller passes
 * ServerDetailViewModel::fromServer($server) as $vm in a later step.
 *
 * Hyper-V host fields live nested under connection_meta.meta
 * (ServerInfoDTO::toArray shape); nested level wins with fallback to the top
 * level for legacy flat rows. RAM/storage units from fetchInfo are BYTES.
 *
 * CANONICAL KEY TABLE (todo 6 frozen): nested meta.* camelCase wins — hostname,
 * hypervVersion, hostOS, osBuild, logicalCpu, cpuLoadPercent, ramTotal, ramFree,
 * uptime, bootTime, storageFree, storageTotal, storageUsed,
 * volumes[{name,total,used,free}], switchDetails[{name,type}], switches[string],
 * vmCounts{running,stopped,saved,total}, host, port, use_ssl, verify_tls,
 * latencyMs. Legacy snake/camel/case variants fall back case-insensitively.
 * latencyMs is legacy INPUT ONLY, canonical output is latency.
 * Derived ramUsed/ramPct/storageUsed/storagePct never read from persisted keys.
 * isStale rule owned by todo 7 — fresh fetch failed OR checked_at older than 60s
 * Hyper-V / updated_at age for panels; here we expose prop with sensible default
 * (Hyper-V 60s, panels 15min) without controller fetch.
 */
final readonly class ServerDetailViewModel
{
    /**
     * @param  array<string, mixed>  $meta  servers.connection_meta (flat or ServerInfoDTO::toArray shape)
     * @param  array<string, mixed>  $hv  unwrapped $meta['meta'] level, or [] for legacy flat rows
     * @param  array<string, mixed>|null  $vmCounts
     * @param  array<int, array{name: string, type: string|null}>  $switchList
     * @param  array<string, mixed>|null  $volumes
     */
    public function __construct(
        public string $displayType,
        public string $connStatus,
        public string $connBadgeTheme,
        public string $connDot,
        public array $meta,
        public array $hv,
        public bool $isConnected,
        public bool $isPanel,
        public bool $isHyperv,
        public mixed $hostname,
        public string $version,
        public mixed $latency,
        public mixed $hostOS,
        public mixed $hypervVersion,
        public mixed $logicalCpu,
        public mixed $ramTotal,
        public mixed $ramFree,
        public mixed $ramUsed,
        public mixed $ramPct,
        public ?array $vmCounts,
        public bool $hasVmCounts,
        public array $switchList,
        public int $switchCount,
        public mixed $storageFree,
        public mixed $storageTotal,
        public mixed $storageUsed,
        public mixed $storagePct,
        public ?array $volumes,
        public ?string $uptimeDisplay,
        public ?string $bootTime,
        public mixed $cpuLoadPercent,
        public mixed $osBuild,
        public mixed $transportHost,
        public mixed $transportPort,
        public mixed $metaUseSsl,
        public mixed $metaVerifyTls,
        public int $totalAccounts,
        public bool $isStale,
        // Canonical additions (todo 1)
        public array $switches = [],
        public mixed $remoteTotal = null,
        public mixed $localTotal = null,
        public bool $hasDrift = false,
        public mixed $snmpSource = null,
        public mixed $snmpCollectedAt = null,
        public mixed $snmpUptime = null,
        public mixed $snmpCpu = null,
        public mixed $snmpMem = null,
        public mixed $snmpDisks = null,
        // Census provenance for the drift badge (todo 11): raw checked_at string or null.
        public mixed $censusCheckedAt = null,
    ) {}

    public static function fromServer(Server $server): self
    {
        $displayType = $server->display_type ?: Str::title(str_replace(['_', '-'], ' ', (string) $server->server_type));
        $connStatus = $server->connection_status ?? 'untested';
        $connBadgeTheme = match ($connStatus) {
            'connected' => 'success',
            'failed' => 'danger',
            default => 'secondary',
        };
        $connDot = match ($connStatus) {
            'connected' => 'bg-success',
            'failed' => 'bg-danger',
            default => 'bg-secondary',
        };
        $meta = is_array($server->connection_meta) ? $server->connection_meta : [];
        $isConnected = $connStatus === 'connected';
        $isPanel = in_array($server->server_type, ['cpanel', 'plesk', 'directadmin', 'virtualizor'], true);
        $isHyperv = ($server->server_type ?? $server->panel_type) === 'hyperv';

        $hv = (isset($meta['meta']) && is_array($meta['meta'])) ? $meta['meta'] : [];

        $apiHost = null;
        if (is_string($server->api_url) && $server->api_url !== '' && str_contains($server->api_url, '://')) {
            $parsedHost = parse_url($server->api_url, PHP_URL_HOST);
            if (is_string($parsedHost) && $parsedHost !== '') {
                $apiHost = $parsedHost;
            }
        }

        // Hostname: nested wins, case-insensitive + api_url/ip fallback
        $hostname = self::hvGetAny($hv, $meta, ['hostname']);
        if ($hostname === null) {
            $hostname = $apiHost ?? $server->api_url ?? $server->ip_address ?? self::hvGetAny($hv, $meta, ['hostOS','host_os']) ?? null;
        }
        // Normalize empty string to null for fallback chain
        if (is_string($hostname) && trim($hostname) === '') {
            $hostname = $apiHost ?? $server->ip_address ?? null;
        }

        $versionRaw = self::hvGetAny($hv, $meta, ['version'])
            ?? self::hvGetAny($hv, $meta, ['hypervVersion','hyperv_version'])
            ?? self::hvGetAny($hv, $meta, ['hostOS','host_os'])
            ?? '';
        if (is_array($versionRaw)) {
            $versionRaw = $versionRaw['version'] ?? '';
        }
        $version = (string) ($versionRaw ?? '');

        // latencyMs legacy INPUT ONLY — never re-expose; output prop is latency
        $latency = self::hvGetAny($hv, $meta, ['latencyMs','latency_ms','latency']) ?? null;

        // Hyper-V specific (nested first, case-insensitive, snake/camel variants)
        $hostOS = self::hvGetAny($hv, $meta, ['hostOS','host_os']);
        $hypervVersion = self::hvGetAny($hv, $meta, ['hypervVersion','hyperv_version']);
        $logicalCpu = self::hvGetAny($hv, $meta, ['logicalCpu','logical_cpu','logicalcpu']);
        if ($logicalCpu !== null && ((int) $logicalCpu) <= 0) {
            $logicalCpu = null;
        }
        $ramTotal = self::hvGetAny($hv, $meta, ['ramTotal','ram_total']);
        $ramFree = self::hvGetAny($hv, $meta, ['ramFree','ram_free']);
        if ($ramTotal !== null && ((int) $ramTotal) <= 0) {
            $ramTotal = null;
        }
        if ($ramFree !== null && ((int) $ramFree) <= 0) {
            $ramFree = null;
        }

        $vmCountsRaw = self::hvGetAny($hv, $meta, ['vmCounts','vm_counts']);
        $vmCounts = is_array($vmCountsRaw) ? $vmCountsRaw : null;

        $switchDetails = self::hvGetAny($hv, $meta, ['switchDetails','switch_details']) ?? [];
        $switchesRaw = self::hvGetAny($hv, $meta, ['switches']) ?? [];
        $switches = is_array($switchesRaw) ? array_values($switchesRaw) : [];

        $storageFree = self::hvGetAny($hv, $meta, ['storageFree','storage_free']);
        $storageTotal = self::hvGetAny($hv, $meta, ['storageTotal','storage_total']);
        if ($storageTotal !== null && ((int) $storageTotal) <= 0) {
            $storageTotal = null;
        }
        if ($storageFree !== null && ((int) $storageFree) <= 0) {
            $storageFree = null;
        }
        // storageUsed is derived or explicit — never read persisted derived alias beyond canonical + snake
        $storageUsedRaw = self::hvGetAny($hv, $meta, ['storageUsed','storage_used']);

        $volumesRaw = self::hvGetAny($hv, $meta, ['volumes']);
        $volumes = is_array($volumesRaw) ? $volumesRaw : null;

        $uptime = self::hvGetAny($hv, $meta, ['uptime']);
        $bootTime = self::hvGetAny($hv, $meta, ['bootTime','boot_time']);
        if (is_string($bootTime) && $bootTime !== '') {
            try {
                $bootTime = Carbon::parse($bootTime)->format('Y-m-d H:i');
            } catch (\Throwable) {
                // Keep raw host string.
            }
        }
        if ($bootTime !== null && ! is_string($bootTime)) {
            $bootTime = (string) $bootTime;
        }

        $cpuLoadPercent = self::hvGetAny($hv, $meta, ['cpuLoadPercent','cpu_load_percent','cpuLoad','cpu_load']);
        $osBuild = self::hvGetAny($hv, $meta, ['osBuild','os_build']);

        // Transport — nested wins, snake/camel/case variants, then api_url, then default by useSsl
        $metaHost = self::hvGetAny($hv, $meta, ['host']) ?? $apiHost ?? null;
        $metaPortRaw = self::hvGetAny($hv, $meta, ['port']);
        $metaPort = is_numeric($metaPortRaw) ? (int) $metaPortRaw : null;
        $metaUseSsl = self::hvGetAny($hv, $meta, ['use_ssl','useSsl']);
        $metaVerifyTls = self::hvGetAny($hv, $meta, ['verify_tls','verifyTls']);
        // Normalize booleans: accept string "true"/"false", int 0/1
        if (is_string($metaUseSsl)) {
            $metaUseSsl = in_array(strtolower($metaUseSsl), ['1','true','yes'], true) ? true : (in_array(strtolower($metaUseSsl), ['0','false','no'], true) ? false : $metaUseSsl);
        }
        if (is_string($metaVerifyTls)) {
            $metaVerifyTls = in_array(strtolower($metaVerifyTls), ['1','true','yes'], true) ? true : (in_array(strtolower($metaVerifyTls), ['0','false','no'], true) ? false : $metaVerifyTls);
        }

        $hasVmCounts = is_array($vmCounts) && ($vmCounts !== []);

        // isStale: owned by todo 7 — sensible default here without controller fetch
        // Hyper-V: last_checked_at older than 60s; panels: updated_at older than 15min
        // Reference: fresh fetch failed OR checked_at older than 60s Hyper-V / updated_at age for panels
        if ($isHyperv) {
            $isStale = $server->last_checked_at ? $server->last_checked_at->diffInSeconds(now()) > 60 : false;
        } else {
            $ts = $server->updated_at ?? $server->last_checked_at;
            $isStale = $ts ? $ts->diffInMinutes(now()) > 15 : false;
        }

        // Census (todo 11): explicit meanings, never silent substitution.
        // Remote = connection_meta.totalAccounts (Hyper-V: meta.meta.vmCounts.total wins).
        // Local ledger = hosting_accounts.count. Provisioned VMs / scheduler load / cap
        // are controller counts in show() — the ViewModel owns remote/local/hasDrift only.
        $localTotal = 0;
        try {
            if ($server->relationLoaded('hostingAccounts')) {
                $rel = $server->getRelation('hostingAccounts');
                // show() overwrites the relation with a paginator (one page) — total() is the ledger count.
                $localTotal = $rel instanceof \Illuminate\Contracts\Pagination\Paginator ? $rel->total() : $rel->count();
            } else {
                // Fallback when relation not loaded: try count() without extra query if possible
                $localTotal = $server->hostingAccounts()->count();
            }
        } catch (\Throwable) {
            $localTotal = 0;
        }

        // remoteTotal: for Hyper-V prefer vmCounts.total, else flat totalAccounts; for panels use totalAccounts
        $remoteTotal = null;
        if ($isHyperv) {
            if (is_array($vmCounts) && array_key_exists('total', $vmCounts) && $vmCounts['total'] !== '' && $vmCounts['total'] !== null) {
                $remoteTotal = $vmCounts['total'];
            } elseif (is_array($vmCounts) && array_key_exists('Total', $vmCounts) && $vmCounts['Total'] !== '' && $vmCounts['Total'] !== null) {
                $remoteTotal = $vmCounts['Total'];
            } else {
                $remoteTotal = self::hvGetAny($hv, $meta, ['totalAccounts','total_accounts']);
            }
        } else {
            $remoteTotal = self::hvGetAny($hv, $meta, ['totalAccounts','total_accounts']);
        }
        // Coerce numeric remoteTotal, keep null when missing
        if ($remoteTotal !== null && $remoteTotal !== '' && is_numeric($remoteTotal)) {
            $remoteTotal = (int) $remoteTotal;
        } elseif ($remoteTotal !== null && ! is_numeric($remoteTotal)) {
            // Non-numeric remoteTotal treated as missing
            $remoteTotal = null;
        }

        // Plesk stub honesty: Plesk::getServerInfo() hardcodes totalAccounts 0 (it never
        // counts subscriptions), so a 0 from plesk is "no remote data", never "0 servers".
        if (($server->server_type ?? null) === 'plesk' && $remoteTotal === 0) {
            $remoteTotal = null;
        }

        $hasDrift = $remoteTotal !== null && $localTotal !== null && (int) $remoteTotal !== (int) $localTotal;

        // totalAccounts is the last known REMOTE reading only — never substituted with the
        // local ledger (todo 11 removed the silent fallback: missing remote renders —).
        $totalAccountsLegacy = self::hvGetAny($hv, $meta, ['totalAccounts','total_accounts']);
        $totalAccounts = $totalAccountsLegacy !== null && is_numeric($totalAccountsLegacy) ? (int) $totalAccountsLegacy : 0;

        // Provenance for the drift badge: "Remote differs from ledger — last poll {checked_at}".
        $provenance = is_array($meta['provenance'] ?? null) ? $meta['provenance'] : [];
        $censusCheckedAt = $provenance['checked_at'] ?? $meta['checked_at'] ?? null;
        if (! is_string($censusCheckedAt) || trim($censusCheckedAt) === '') {
            $censusCheckedAt = null;
        }

        // Hyper-V transport / RAM / storage / switches.
        $transportHost = $metaHost ?? $server->ip_address;
        $parsedPort = (is_string($server->api_url) && str_contains($server->api_url, '://')) ? parse_url($server->api_url, PHP_URL_PORT) : null;
        $parsedPort = is_numeric($parsedPort) ? (int) $parsedPort : null;
        // useSsl truthy => 5986 else 5985 fallback (matches ViewModel 158-172)
        $useSslTruthy = $metaUseSsl === true || $metaUseSsl === 1 || $metaUseSsl === '1';
        $transportPort = $metaPort ?? $parsedPort ?? ($useSslTruthy ? 5986 : 5985);

        // Derived — never read persisted derived keys (ramUsed/ramPct/storageUsed/Pct)
        $ramUsed = (is_numeric($ramTotal) && is_numeric($ramFree)) ? max(0, (int) $ramTotal - (int) $ramFree) : null;
        $ramPct = ($ramUsed !== null && is_numeric($ramTotal) && (int) $ramTotal > 0) ? min(100, round($ramUsed / (int) $ramTotal * 100)) : null;

        $switchList = self::buildSwitchList($switchDetails, $switches);
        $switchCount = count($switchList);

        $storageHasTotal = is_numeric($storageTotal) && (int) $storageTotal > 0;
        // Derived — never read persisted storageUsed/storagePct keys; always recompute
        $storageUsed = ($storageHasTotal && is_numeric($storageFree)) ? max(0, (int) $storageTotal - (int) $storageFree) : null;
        $storagePct = ($storageHasTotal && is_numeric($storageUsed)) ? min(100, round((int) $storageUsed / (int) $storageTotal * 100)) : null;

        $uptimeDisplay = self::fmtUptime(is_string($uptime) ? $uptime : null) ?? (is_string($uptime) ? $uptime : null);

        // SNMP bridge props (read-only bridge owned by todo 9) — defaults null here, never live dial
        $snmpSource = null;
        $snmpCollectedAt = null;
        $snmpUptime = null;
        $snmpCpu = null;
        $snmpMem = null;
        $snmpDisks = null;

        return new self(
            displayType: $displayType,
            connStatus: $connStatus,
            connBadgeTheme: $connBadgeTheme,
            connDot: $connDot,
            meta: $meta,
            hv: $hv,
            isConnected: $isConnected,
            isPanel: $isPanel,
            isHyperv: $isHyperv,
            hostname: $hostname,
            version: $version,
            latency: $latency,
            hostOS: $hostOS,
            hypervVersion: $hypervVersion,
            logicalCpu: $logicalCpu,
            ramTotal: $ramTotal,
            ramFree: $ramFree,
            ramUsed: $ramUsed,
            ramPct: $ramPct,
            vmCounts: $vmCounts,
            hasVmCounts: $hasVmCounts,
            switchList: $switchList,
            switchCount: $switchCount,
            storageFree: $storageFree,
            storageTotal: $storageTotal,
            storageUsed: $storageUsed,
            storagePct: $storagePct,
            volumes: $volumes,
            uptimeDisplay: $uptimeDisplay,
            bootTime: $bootTime,
            cpuLoadPercent: $cpuLoadPercent,
            osBuild: $osBuild,
            transportHost: $transportHost,
            transportPort: $transportPort,
            metaUseSsl: $metaUseSsl,
            metaVerifyTls: $metaVerifyTls,
            totalAccounts: $totalAccounts,
            isStale: $isStale,
            switches: $switches,
            remoteTotal: $remoteTotal,
            localTotal: $localTotal,
            hasDrift: $hasDrift,
            snmpSource: $snmpSource,
            snmpCollectedAt: $snmpCollectedAt,
            snmpUptime: $snmpUptime,
            snmpCpu: $snmpCpu,
            snmpMem: $snmpMem,
            snmpDisks: $snmpDisks,
            censusCheckedAt: $censusCheckedAt,
        );
    }

    public static function fmtBytes(mixed $bytes): string
    {
        if (! is_numeric($bytes) || $bytes < 0) {
            return is_scalar($bytes) ? (string) $bytes : '—';
        }

        $bytes = (float) $bytes;
        if ($bytes < 1024) {
            return round($bytes).' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB', 'PB'];
        $i = (int) floor(log($bytes, 1024)) - 1;
        $i = max(0, min(count($units) - 1, $i));

        return round($bytes / pow(1024, $i + 1), 1).' '.$units[$i];
    }

    public static function fmtUptime(?string $raw): ?string
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        // Host sends .NET TimeSpan "d.hh:mm:ss" e.g. "2.05:00:00"
        if (preg_match('/^(?:(\d+)\.)?(\d{1,2}):(\d{2})(?::(\d{2}))?/', trim($raw), $m) === 1) {
            $d = (int) ($m[1] ?? 0);
            $h = (int) $m[2];
            $min = (int) $m[3];
            if ($d > 0) {
                return "{$d}d {$h}h {$min}m";
            }
            if ($h > 0) {
                return "{$h}h {$min}m";
            }

            return "{$min}m";
        }

        return $raw;
    }

    /**
     * Nested-first lookup with case-insensitive + snake/camel variants.
     * Non-empty $hv value wins, then non-empty $meta value, else $default.
     * Candidates are tried via normalized comparison (lowercase, underscores stripped).
     */
    private static function hvGetAny(array $hv, array $meta, array $candidates, mixed $default = null): mixed
    {
        $found = self::findByCandidates($hv, $candidates);
        if ($found !== null) {
            return $found;
        }
        $found = self::findByCandidates($meta, $candidates);
        if ($found !== null) {
            return $found;
        }

        return $default;
    }

    /**
     * Legacy exact-key nested-first lookup (kept for backward compat).
     */
    private static function hvGet(array $hv, array $meta, string $key, mixed $default = null): mixed
    {
        return self::hvGetAny($hv, $meta, [$key], $default);
    }

    private static function findByCandidates(array $arr, array $candidates): mixed
    {
        if ($arr === [] || $candidates === []) {
            return null;
        }
        // Build normalized map: normalized => original value (first occurrence wins)
        $normMap = [];
        foreach ($arr as $k => $v) {
            if (! is_string($k)) {
                continue;
            }
            $norm = self::normalizeKey($k);
            // Keep first non-empty value for each normalized key
            if (! array_key_exists($norm, $normMap)) {
                $normMap[$norm] = $v;
            }
        }
        foreach ($candidates as $cand) {
            $normCand = self::normalizeKey($cand);
            if (array_key_exists($normCand, $normMap)) {
                $val = $normMap[$normCand];
                if ($val !== '' && $val !== null) {
                    return $val;
                }
            }
        }

        return null;
    }

    private static function normalizeKey(string $key): string
    {
        return strtolower(str_replace('_', '', $key));
    }

    /**
     * Backward-compat: switches may be string names (old rows) or
     * switchDetails[{name,type}] (new rows). Both branches trim, skip blanks,
     * and accept Name/name + SwitchType/type variants.
     *
     * @return array<int, array{name: string, type: string|null}>
     */
    private static function buildSwitchList(mixed $switchDetails, mixed $switches): array
    {
        $switchList = [];

        if (is_array($switchDetails) && $switchDetails !== []) {
            foreach ($switchDetails as $sd) {
                if (is_array($sd)) {
                    $sdName = trim((string) ($sd['name'] ?? $sd['Name'] ?? ''));
                    if ($sdName === '') {
                        continue;
                    }
                    $sdType = $sd['type'] ?? $sd['Type'] ?? $sd['SwitchType'] ?? $sd['switchType'] ?? null;
                    $switchList[] = ['name' => $sdName, 'type' => is_string($sdType) && trim($sdType) !== '' ? trim($sdType) : null];
                } elseif (is_string($sd)) {
                    $sdName = trim($sd);
                    if ($sdName === '') {
                        continue;
                    }
                    $switchList[] = ['name' => $sdName, 'type' => null];
                }
            }
        } elseif (is_array($switches)) {
            foreach (array_values($switches) as $sw) {
                if (is_string($sw)) {
                    $swName = trim($sw);
                    if ($swName === '') {
                        continue;
                    }
                    $switchList[] = ['name' => $swName, 'type' => null];
                } elseif (is_array($sw)) {
                    $swName = trim((string) ($sw['Name'] ?? $sw['name'] ?? ''));
                    if ($swName === '') {
                        continue;
                    }
                    $swType = $sw['SwitchType'] ?? $sw['switchType'] ?? $sw['type'] ?? $sw['Type'] ?? null;
                    $switchList[] = ['name' => $swName, 'type' => is_string($swType) && trim($swType) !== '' ? trim($swType) : null];
                }
            }
        }

        return $switchList;
    }
}
