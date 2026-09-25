<?php

declare(strict_types=1);

namespace Modules\RdpConsole\Services\Gateway;

use InvalidArgumentException;

/**
 * Everything a gateway needs to open one RDP session. Built by the token
 * endpoint from the account's decrypted RdpConsoleConfig; never stored.
 *
 * `expiresAt` is an optional unix timestamp overriding the default 90 second
 * token TTL — the expiry is embedded in every minted token and enforced by
 * the sidecar's processConnectionSettings hook.
 *
 * `mode` selects the target kind (guest RDP service vs Hyper-V VMConnect) and
 * is deliberately an explicit value object input: no caller can smuggle a
 * different security mode, port or preconnection blob through request data.
 * VMConnect contexts are built with the hyperV() factory below so the port,
 * security mode and VM GUID always travel together.
 */
final class RdpConnectionContext
{
    /**
     * Hyper-V's built-in RDP server (vmrdp) listens here. guacd would default
     * to it for security=vmconnect, but the value is pinned in the factory so
     * the settings array is explicit and testable.
     */
    public const VMCONNECT_PORT = 2179;

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
        public readonly RdpConnectionMode $mode = RdpConnectionMode::GuestRdp,
        public readonly ?string $preconnectionBlob = null,
    ) {}

    /**
     * A Hyper-V VMConnect context: the Hyper-V HOST's address and administrator
     * credentials plus the target VM's GUID. This is the only supported way to
     * build one, so the port (2179), the security mode ("vmconnect") and the
     * preconnection BLOB cannot drift apart at a call site.
     *
     * @throws InvalidArgumentException when the VM GUID is missing — fail closed
     *                                  rather than mint a token for an unknown VM.
     */
    public static function hyperV(
        string $hostname,
        string $vmGuid,
        string $username,
        string $password,
        int $adminUserId = 0,
        ?int $accountId = null,
        ?int $expiresAt = null,
    ): self {
        $vmGuid = trim($vmGuid);

        if ($vmGuid === '') {
            throw new InvalidArgumentException('A VMConnect context requires the target VM GUID.');
        }

        return new self(
            hostname: $hostname,
            port: self::VMCONNECT_PORT,
            username: $username,
            password: $password,
            security: 'vmconnect',
            adminUserId: $adminUserId,
            accountId: $accountId,
            expiresAt: $expiresAt,
            mode: RdpConnectionMode::HyperVVmConnect,
            preconnectionBlob: $vmGuid,
        );
    }
}
