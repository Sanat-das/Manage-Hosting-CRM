<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Runtime Reverb client configuration.
 *
 * Why this exists: Vite inlines `import.meta.env.VITE_*` at BUILD time. The
 * committed bundle in `public/build/` is built once by us and shipped; the
 * updater never runs `npm run build`. A build-time key means every customer
 * would need to rebuild from source to get realtime — they cannot. This helper
 * resolves the key/host/port/scheme from Laravel config AT REQUEST TIME so the
 * JS can read it from the rendered page instead of from a build-time constant.
 *
 * Secret discipline: REVERB_APP_SECRET is never exposed here. Only the public
 * key, host, port and scheme reach the browser (same as a Pusher app key).
 */
final class ReverbConfig
{
    /**
     * Public Reverb config for the browser, or null when not configured.
     *
     * The array shape matches what the JS expects on `window.__REVERB__`:
     *   ['key' => string, 'host' => string, 'port' => mixed, 'scheme' => string]
     *
     * Raw values are returned (port may be int|string) so the JS can validate
     * and degrade to polling on malformed input rather than the server silently
     * papering over it. Only the presence of `key` decides "configured".
     *
     * @return array{key:string, host:string, port:mixed, scheme:string}|null
     */
    public static function forClient(): ?array
    {
        $key = config('broadcasting.connections.reverb.key');

        if (! is_string($key) || trim($key) === '') {
            return null;
        }

        $host = config('broadcasting.connections.reverb.options.host');
        $port = config('broadcasting.connections.reverb.options.port');
        $scheme = config('broadcasting.connections.reverb.options.scheme');

        return [
            'key' => trim($key),
            'host' => is_string($host) ? trim($host) : (is_scalar($host) ? trim((string) $host) : ''),
            'port' => $port,
            'scheme' => is_string($scheme) ? trim($scheme) : (is_scalar($scheme) ? trim((string) $scheme) : 'http'),
        ];
    }

    /**
     * CSP connect-src value that permits the Reverb WebSocket when configured.
     *
     * Returns "'self'" when not configured or when the configured host/port/
     * scheme fail strict validation (malformed input must NOT widen the policy).
     */
    public static function cspConnectSrc(): string
    {
        $base = "'self'";
        $cfg = self::forClient();

        if ($cfg === null) {
            return $base;
        }

        $host = $cfg['host'];
        $port = $cfg['port'];
        $scheme = is_string($cfg['scheme']) ? strtolower(trim($cfg['scheme'])) : '';

        if ($host === '') {
            return $base;
        }

        // Strict host check: alphanumeric, dot, hyphen, or a valid IP. This
        // prevents injection of CSP-breaking characters via config.
        $validHost = preg_match('/^[a-zA-Z0-9\.\-]+$/', $host) === 1 || filter_var($host, FILTER_VALIDATE_IP) !== false;

        if (! $validHost) {
            return $base;
        }

        if (! is_numeric($port)) {
            return $base;
        }

        $portInt = (int) $port;

        if ($portInt < 1 || $portInt > 65535) {
            return $base;
        }

        if (! in_array($scheme, ['http', 'https'], true)) {
            return $base;
        }

        return "'self' ws://{$host}:{$portInt} wss://{$host}:{$portInt}";
    }
}
