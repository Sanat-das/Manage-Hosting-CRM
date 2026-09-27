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

    /**
     * Rehydrate from the toArray() shape.
     *
     * This is the ONLY payload allowed to sit in the cache: cache stores run
     * with `cache.serializable_classes` = false (gadget-chain hardening), so a
     * serialized DTO comes back from any serializing store (database, file,
     * redis) as __PHP_Incomplete_Class and blows up the declared return type.
     *
     * @param  array<string, mixed>  $data  the toArray() shape
     */
    public static function fromArray(array $data): self
    {
        return new self(
            hostname: (string) ($data['hostname'] ?? ''),
            version: (string) ($data['version'] ?? ''),
            ipAddress: (string) ($data['ipAddress'] ?? ''),
            totalAccounts: is_numeric($data['totalAccounts'] ?? null) ? (int) $data['totalAccounts'] : 0,
            latencyMs: is_numeric($data['latencyMs'] ?? null) ? (int) $data['latencyMs'] : 0,
            meta: is_array($data['meta'] ?? null) ? $data['meta'] : [],
        );
    }
}
