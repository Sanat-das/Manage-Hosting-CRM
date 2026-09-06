<?php

declare(strict_types=1);

namespace Modules\Cpanel\Services;

use App\Contracts\Module\PanelException;
use App\Models\Server;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Thin wrapper over WHM API 1 (the JSON endpoint on port 2087).
 *
 * Authentication is an API token issued in WHM, sent as
 * `Authorization: whm <user>:<token>` - not basic auth, and not the account
 * password. Credentials come off the Server row (`api_username` / `api_key`);
 * the module never holds them.
 *
 * WHM returns HTTP 200 for application-level failures, so the HTTP status is
 * almost never the answer: success is `metadata.result === 1`, and the reason
 * for a failure is `metadata.reason`. Older/other commands nest the same pair
 * under `result[0]`, which is handled here so callers see one shape.
 */
class WhmClient
{
    /** WHM's API port. Used when the server row has no explicit api_url. */
    private const DEFAULT_PORT = 2087;

    public function __construct(
        private readonly Server $server,
        private readonly bool $verifyTls = true,
        private readonly int $timeout = 30,
    ) {}

    /**
     * Whether the server carries enough detail to be called at all.
     */
    public static function isConfigured(?Server $server): bool
    {
        return $server !== null
            && trim((string) $server->api_username) !== ''
            && trim((string) $server->api_key) !== ''
            && (trim((string) $server->api_url) !== '' || trim((string) $server->ip_address) !== '');
    }

    /**
     * Call a WHM function and return its `data` payload.
     *
     * @param  array<string, scalar|null>  $params
     * @return array<string, mixed>
     *
     * @throws PanelException on transport failure or a WHM-reported error
     */
    public function call(string $function, array $params = []): array
    {
        $url = $this->baseUrl().'/json-api/'.$function;

        try {
            $response = Http::withHeaders([
                'Authorization' => 'whm '.trim((string) $this->server->api_username).':'.trim((string) $this->server->api_key),
            ])
                ->withOptions(['verify' => $this->verifyTls])
                ->timeout($this->timeout)
                ->get($url, $params + ['api.version' => 1]);
        } catch (Throwable $e) {
            // Connection refused / DNS / TLS / timeout - never leak the token.
            throw new PanelException(sprintf(
                'Could not reach WHM at %s: %s',
                $this->baseUrl(),
                $e->getMessage(),
            ), previous: $e);
        }

        if ($response->failed()) {
            throw new PanelException(sprintf(
                'WHM %s returned HTTP %d',
                $function,
                $response->status(),
            ));
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new PanelException("WHM {$function} returned a non-JSON response");
        }

        $metadata = $this->metadata($body);

        if ((int) ($metadata['result'] ?? 0) !== 1) {
            throw new PanelException(sprintf(
                'WHM %s failed: %s',
                $function,
                $metadata['reason'] ?? 'no reason given',
            ));
        }

        $data = $body['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    /**
     * The result/reason pair, from either shape WHM uses.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function metadata(array $body): array
    {
        if (isset($body['metadata']) && is_array($body['metadata'])) {
            return $body['metadata'];
        }

        // createacct and friends historically answer with result[0].
        if (isset($body['result'][0]) && is_array($body['result'][0])) {
            return $body['result'][0];
        }

        return [];
    }

    /**
     * Explicit api_url wins; otherwise https://<ip>:2087. A configured URL is
     * trusted as-is apart from a trailing slash, so an estate behind a proxy on
     * a non-standard path keeps working.
     */
    private function baseUrl(): string
    {
        $configured = trim((string) $this->server->api_url);

        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return 'https://'.trim((string) $this->server->ip_address).':'.self::DEFAULT_PORT;
    }
}
