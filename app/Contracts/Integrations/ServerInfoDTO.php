<?php

declare(strict_types=1);

namespace App\Contracts\Integrations;

/**
 * Essential live info fetched from a remote server/host.
 *
 * Populated by TestableServerModule::getServerInfo() and persisted to
 * servers.connection_meta. Fields are deliberately generic so panel and
 * compute modules can share the same shape — panel-specific data lives in
 * `meta`. The `toArray()` shape is what the admin show view renders as
 * metric cards and tables.
 */
final readonly class ServerInfoDTO
{
    /**
     * @param  array<string, mixed>  $meta  provider-specific details (packages, IPs, VM counts, etc.)
     */
    public function __construct(
        public string $hostname = '',
        public string $version = '',
        public string $ipAddress = '',
        public int $totalAccounts = 0,
        public int $latencyMs = 0,
        public array $meta = [],
    ) {}

    /**
     * @return array{hostname: string, version: string, ipAddress: string, totalAccounts: int, latencyMs: int, meta: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'hostname' => $this->hostname,
            'version' => $this->version,
            'ipAddress' => $this->ipAddress,
            'totalAccounts' => $this->totalAccounts,
            'latencyMs' => $this->latencyMs,
            'meta' => $this->meta,
        ];
    }
}
