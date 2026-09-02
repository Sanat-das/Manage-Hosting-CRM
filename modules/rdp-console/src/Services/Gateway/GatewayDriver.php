<?php

declare(strict_types=1);

namespace Modules\RdpConsole\Services\Gateway;

/**
 * Contract for the browser-side RDP gateway: mint a short-lived connection
 * token for one session and report the websocket endpoint of the sidecar
 * that will accept it.
 */
interface GatewayDriver
{
    /**
     * Mint an encrypted, TTL'd token authorizing exactly one RDP connection.
     *
     * @throws \RuntimeException when the gateway is not usable (missing or
     *                           too-short shared secret, encryption failure).
     */
    public function mint(RdpConnectionContext $context): string;

    /**
     * The websocket URL of the gateway sidecar (guacamole-lite), as
     * configured under rdp-console.ws_url.
     */
    public function wsUrl(): string;
}
