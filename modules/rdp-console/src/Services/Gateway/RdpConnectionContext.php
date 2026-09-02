<?php

declare(strict_types=1);

namespace Modules\RdpConsole\Services\Gateway;

/**
 * Everything a gateway needs to open one RDP session. Built by the token
 * endpoint from the account's decrypted RdpConsoleConfig; never stored.
 *
 * `expiresAt` is an optional unix timestamp overriding the default 90 second
 * token TTL — the expiry is embedded in every minted token and enforced by
 * the sidecar's processConnectionSettings hook.
 */
final class RdpConnectionContext
{
    public function __construct(
        public readonly string $hostname,
        public readonly int $port,
        public readonly string $username,
        public readonly string $password,
        public readonly ?string $domain = null,
        public readonly string $security = 'nla',
        public readonly int $adminUserId = 0,
        public readonly ?int $accountId = null,
        public readonly ?int $expiresAt = null,
    ) {}
}
