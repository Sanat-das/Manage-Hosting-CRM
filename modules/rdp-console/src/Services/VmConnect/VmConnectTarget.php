<?php

declare(strict_types=1);

namespace Modules\RdpConsole\Services\VmConnect;

/**
 * The resolved target of one Hyper-V VMConnect session. Holds credential
 * material, so it must never be rendered into a view or serialized into a
 * response — only fed into RdpConnectionContext::hyperV().
 */
final class VmConnectTarget
{
    public function __construct(
        public readonly string $hostname,
        public readonly string $vmGuid,
        public readonly string $username,
        public readonly string $password,
    ) {}
}
