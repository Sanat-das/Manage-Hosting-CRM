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
     * @throws \Modules\RdpConsole\Exceptions\GatewayNotConfiguredException
     *         when the shared secret is missing or too short — fail closed,
     *         never default the secret
     * @throws \RuntimeException when minting fails for any other reason
     *                           (encryption / serialization failure)
     */
    public function mint(RdpConnectionContext $context): string;

    /**
     * Whether the gateway has a usable shared secret and can mint at all.
     * The console pages use this to render an honest "not configured" state
     * instead of offering a Connect button that cannot work; the endpoints
     * still fail closed through mint() regardless.
     */
    public function isConfigured(): bool;

    /**
     * The websocket URL of the gateway sidecar (guacamole-lite), as
     * configured under rdp-console.ws_url.
     */
    public function wsUrl(): string;
}
