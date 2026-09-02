<?php

namespace App\Services;

use App\Models\IpAddress;
use App\Models\IpSubnet;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SubnetIpGeneratorService
{
    /**
     * Generate IP addresses for the given subnet.
     *
     * @param  array{exclude_network_broadcast?:bool, exclude_gateway?:bool, type?:string}  $options
     * @return array{created:int, skipped:int, total:int}
     */
    public function generate(IpSubnet $subnet, array $options = []): array
    {
        $cidr = trim((string) $subnet->subnet_cidr);
        $excludeNetworkBroadcast = $options['exclude_network_broadcast'] ?? true;
        $excludeGateway = $options['exclude_gateway'] ?? true;
        $type = $options['type'] ?? 'available';

        if (! str_contains($cidr, '/')) {
            throw new InvalidArgumentException("Invalid CIDR: {$cidr}");
        }

        [$baseIp, $prefixStr] = explode('/', $cidr, 2);
        $prefix = (int) $prefixStr;

        // IPv4 only for bulk generation — IPv6 ranges are astronomically large.
        if (filter_var($baseIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $this->generateIpv4($subnet, $baseIp, $prefix, $excludeNetworkBroadcast, $excludeGateway, $type);
        }

        if (filter_var($baseIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            throw new InvalidArgumentException('Bulk generation for IPv6 is not supported — add addresses manually or use a smaller IPv4 subnet.');
        }

        throw new InvalidArgumentException("Invalid base IP in CIDR: {$cidr}");
    }

    /**
     * @return array{created:int, skipped:int, total:int}
     */
    private function generateIpv4(IpSubnet $subnet, string $baseIp, int $prefix, bool $excludeNetworkBroadcast, bool $excludeGateway, string $type): array
    {
        if ($prefix < 16 || $prefix > 32) {
            throw new InvalidArgumentException('Prefix must be between /16 and /32 for bulk generation (larger subnets would create too many rows). Use a smaller subnet or add IPs manually.');
        }

        $networkLong = ip2long($baseIp);
        if ($networkLong === false) {
            throw new InvalidArgumentException("Invalid IPv4 address: {$baseIp}");
        }

        // Unsigned handling.
        $networkLong = (int) sprintf('%u', $networkLong);
        $mask = $prefix === 0 ? 0 : (~0 << (32 - $prefix)) & 0xFFFFFFFF;
        $network = $networkLong & $mask;
        $broadcast = $network | (~$mask & 0xFFFFFFFF);

        // Determine host range.
        if ($prefix === 32) {
            $first = $network;
            $last = $network;
        } elseif ($prefix === 31) {
            // RFC 3021 — both addresses usable, no network/broadcast.
            $first = $network;
            $last = $broadcast;
        } else {
            $first = $excludeNetworkBroadcast ? $network + 1 : $network;
            $last = $excludeNetworkBroadcast ? $broadcast - 1 : $broadcast;
        }

        $gatewayLong = null;
        if ($excludeGateway && $subnet->gateway) {
            $g = ip2long($subnet->gateway);
            if ($g !== false) {
                $gatewayLong = (int) sprintf('%u', $g);
            }
        }

        $total = ($last >= $first) ? ($last - $first + 1) : 0;

        if ($total === 0) {
            return ['created' => 0, 'skipped' => 0, 'total' => 0];
        }

        // Guard: never generate more than 4096 in one call (covers /20 = 4094 usable).
        if ($total > 4096) {
            throw new InvalidArgumentException("Subnet {$subnet->subnet_cidr} would generate {$total} addresses — limit is 4096 per batch. Use a smaller subnet (e.g. /22 or smaller).");
        }

        // Fetch existing IPs for this subnet to skip duplicates.
        $existing = IpAddress::where('subnet_id', $subnet->id)->pluck('ip_address')->flip()->toArray();
        $existingLongs = [];
        foreach (array_keys($existing) as $ip) {
            $l = ip2long($ip);
            if ($l !== false) {
                $existingLongs[(int) sprintf('%u', $l)] = true;
            }
        }

        $rows = [];
        $skipped = 0;
        $now = now();

        for ($long = $first; $long <= $last; $long++) {
            if ($gatewayLong !== null && $long === $gatewayLong) {
                $skipped++;
                continue;
            }
            if (isset($existingLongs[$long])) {
                $skipped++;
                continue;
            }
            $rows[] = [
                'subnet_id' => $subnet->id,
                'ip_address' => long2ip($long),
                'ip_version' => '4',
                'type' => $type,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            // Chunk insert every 500 to stay within packet limits.
            if (count($rows) === 500) {
                $this->bulkInsert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            $this->bulkInsert($rows);
        }

        $created = $total - $skipped;

        // Keep subnet counters in sync.
        $actualTotal = IpAddress::where('subnet_id', $subnet->id)->count();
        $subnet->update([
            'total_addresses' => $actualTotal,
        ]);

        return ['created' => $created, 'skipped' => $skipped, 'total' => $total];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function bulkInsert(array $rows): void
    {
        // Use insertOrIgnore to survive race/duplicate unique constraint.
        DB::table('ip_addresses')->insertOrIgnore($rows);
    }
}
