<?php

declare(strict_types=1);

namespace Modules\RdpConsole\Exceptions;

use RuntimeException;

/**
 * Thrown by the gateway driver when no usable shared secret is configured
 * (missing, blank, or shorter than 16 characters).
 *
 * This is deliberately a DISTINCT type rather than a bare RuntimeException:
 * "the deployment is not configured" is an operator-fixable 503 (the console
 * answers with OPERATOR_MESSAGE and the pages render a not-configured state),
 * while any other mint failure is an unexpected 500. Both still fail closed —
 * there is no default or generated secret, ever.
 */
final class GatewayNotConfiguredException extends RuntimeException
{
    /**
     * The operator-facing message shown by the console pages and returned by
     * the token endpoints. Fixed and free of exception detail: it names the
     * setting to fix and nothing else (no exception message, class, file path
     * or config value).
     */
    public const OPERATOR_MESSAGE = 'The console gateway is not configured. Set GUACAMOLE_SECRET (16+ characters) on the server and restart, then reload this page.';
}
