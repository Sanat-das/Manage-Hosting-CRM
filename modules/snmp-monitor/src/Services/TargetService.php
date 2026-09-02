<?php

declare(strict_types=1);

namespace Modules\SnmpMonitor\Services;

use App\Models\HostingAccount;
use App\Services\Modules\ModuleManager;
use Modules\SnmpMonitor\Models\SnmpTarget;

/**
 * Ensures the single snmp_targets row per hosting account and resolves its
 * effective SNMP host. The fallback chain replicates the retired
 * ssh-console/rdp-console SnapshotService::resolveHost implementations
 * exactly: explicit stored host first, then the IPAM lease on a public
 * subnet, then a legacy type=public lease, then any lease.
 */
final class TargetService
{
    public const SOURCE_TARGET = 'target';

    public const SOURCE_IPAM_PUBLIC_SUBNET = 'ipam-public-subnet';

    public const SOURCE_LEGACY_TYPE_PUBLIC = 'legacy-type-public';

    public const SOURCE_IPAM_ANY = 'ipam-any';

    /** Per-product override for which subnet type to prefer. */
    public const IP_SOURCE_AUTO = 'auto';
    public const IP_SOURCE_PUBLIC = 'public';
    public const IP_SOURCE_PRIVATE = 'private';
    public const IP_SOURCE_ANY = 'any';

    /**
     * Effective SNMP host for the account plus which resolution step
     * produced it. Returns null when no explicit host is stored and the
     * account holds no usable IP lease — callers must treat that as "no
     * pollable address", never store credentials to compensate.
     *
     * @param string|null $ipSource Per-product override from snmp_ip_source (auto|public|private|any)
     * @return array{host: string, source: string}|null
     */
    public function resolveForAccount(HostingAccount $account, ?string $ipSource = null): ?array
    {
        // Explicit host stored on the target row wins over IPAM. The query
        // is guarded like the original SnapshotService so a missing module
        // table degrades to auto-resolution instead of fataling.
        $storedHost = null;

        try {
            $storedHost = SnmpTarget::query()
                ->where('hosting_account_id', $account->id)
                ->value('host');
        } catch (\Throwable) {
            $storedHost = null;
        }

        $host = trim((string) ($storedHost ?? ''));
        $ipSource = $ipSource ?? self::IP_SOURCE_AUTO;

        // For per-product IP source overrides (public/private/any), an
        // auto-resolved host (one that matches an IPAM lease) may be moved
        // to the requested subnet type. A truly custom host (not in IPAM)
        // is always preserved.
        if ($host !== '' && $ipSource !== self::IP_SOURCE_AUTO) {
            $account->load('ipAddresses.subnet');
            $ips = $account->ipAddresses;
            $isAutoResolved = $ips->pluck('ip_address')->map(fn ($ip) => trim((string) $ip))->contains($host);
            if ($isAutoResolved) {
                // Fall through to IPAM selection below — product config drives the host
                $host = '';
            } else {
                return ['host' => $host, 'source' => self::SOURCE_TARGET];
            }
        } elseif ($host !== '') {
            return ['host' => $host, 'source' => self::SOURCE_TARGET];
        }

        // load() rather than loadMissing(): ensureForAccount may run twice
        // on the same instance (e.g. create, then auto-fill once a lease
        // appears) and a cached empty relation would hide new IPAM leases.
        $account->load('ipAddresses.subnet');

        $ips = $account->ipAddresses;

        // Deterministic priority: public > private > storage > management > dmz > other.
        // Sorting makes the fallback `any` pick the best alternative when no
        // public subnet exists (live DB had 0 public subnets), instead of
        // arbitrary insertion order.
        $priority = ['public' => 0, 'private' => 1, 'storage' => 2, 'management' => 3, 'dmz' => 4];
        $sorted = $ips->sortBy(fn ($ip) => $priority[$ip->subnet?->network_type ?? 'other'] ?? 99)->values();

        // Per-product override: public / private / any bypass the auto chain.
        if (in_array($ipSource, [self::IP_SOURCE_PUBLIC, self::IP_SOURCE_PRIVATE, self::IP_SOURCE_ANY], true)) {
            if ($ipSource === self::IP_SOURCE_ANY) {
                $candidate = $sorted->first();
                $source = self::SOURCE_IPAM_ANY;
            } else {
                $candidate = $sorted->first(fn ($ip) => ($ip->subnet?->network_type ?? null) === $ipSource);
                $source = $ipSource === self::IP_SOURCE_PUBLIC ? self::SOURCE_IPAM_PUBLIC_SUBNET : self::SOURCE_IPAM_ANY;
                // Fall back to any if the requested type has no lease — never return null when a lease exists.
                if ($candidate === null) {
                    $candidate = $sorted->first();
                    $source = self::SOURCE_IPAM_ANY;
                }
            }
            $ipAddress = trim((string) ($candidate?->ip_address ?? ''));
            return $ipAddress !== '' ? ['host' => $ipAddress, 'source' => $source] : null;
        }

        // Auto: Prefer a public subnet lease, then legacy type=public, then any lease.
        // IpAddress.type encodes assigned/available (not public/private), so
        // legacy type=public rows are only honoured after subnet evaluation.
        $candidate = $sorted->first(function ($ip) {
            return $ip->subnet !== null && $ip->subnet->network_type === 'public';
        });
        $source = self::SOURCE_IPAM_PUBLIC_SUBNET;

        if ($candidate === null) {
            $candidate = $sorted->firstWhere('type', 'public');
            $source = self::SOURCE_LEGACY_TYPE_PUBLIC;
        }

        if ($candidate === null) {
            $candidate = $sorted->first();
            $source = self::SOURCE_IPAM_ANY;
        }

        $ipAddress = trim((string) ($candidate?->ip_address ?? ''));

        return $ipAddress !== '' ? ['host' => $ipAddress, 'source' => $source] : null;
    }

    /**
     * Effective SNMP OS for the account: an explicit per-product config
     * override wins (validated against the supported set), otherwise the
     * product's enabled remote-console module decides — rdp-console →
     * windows, ssh-console → linux. Products linked to neither default to
     * linux.
     *
     * @param  array<string, mixed>  $config  decrypted snmp-monitor product config
     */
    public static function osFor(HostingAccount $account, array $config): string
    {
        if (in_array($config['target_os'] ?? null, [SnmpTarget::OS_LINUX, SnmpTarget::OS_WINDOWS], true)) {
            return (string) $config['target_os'];
        }

        foreach ([['rdp-console', SnmpTarget::OS_WINDOWS], ['ssh-console', SnmpTarget::OS_LINUX]] as [$slug, $os]) {
            $module = app(ModuleManager::class)->find($slug);

            if ($module !== null && $account->product?->moduleLinks->firstWhere('module_id', $module->id)?->enabled) {
                return $os;
            }
        }

        return SnmpTarget::OS_LINUX;
    }

    /**
     * Return the account's target, creating it on first use. The stored host
     * always comes from resolveForAccount(): an explicit host round-trips
     * unchanged, a null host auto-fills once IPAM yields a lease, and an
     * account with zero leases stores host=null instead of throwing.
     *
     * @throws \InvalidArgumentException when $os is not a supported target OS
     */
    public function ensureForAccount(HostingAccount $account, string $os, ?string $ipSource = null): SnmpTarget
    {
        if (! in_array($os, [SnmpTarget::OS_LINUX, SnmpTarget::OS_WINDOWS], true)) {
            throw new \InvalidArgumentException("Unsupported SNMP target OS [{$os}].");
        }

        $resolved = $this->resolveForAccount($account, $ipSource);

        $target = SnmpTarget::query()->updateOrCreate(
            ['hosting_account_id' => $account->id],
            [
                'target_os' => $os,
                'host' => $resolved['host'] ?? null,
            ],
        );

        // Re-read so DB defaults (enabled, port, status, consecutive_failures)
        // are visible on the returned model instead of null in-memory attrs.

        return $target->refresh();
    }
}
