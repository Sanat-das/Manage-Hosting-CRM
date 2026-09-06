<?php

declare(strict_types=1);

namespace Modules\Plesk\Services;

use App\Contracts\Module\PanelException;
use App\Models\Server;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Plesk REST API v2 client (port 8443).
 *
 * Auth is `X-API-Key` when the server's `api_key` is a Plesk API key; when
 * `api_username` is also set the request falls back to HTTP basic, which is
 * what a Plesk admin login uses. Both are documented; which one an estate uses
 * depends on how the token was issued, so both are supported rather than
 * forcing one.
 *
 * REST v2 covers client and subscription creation directly. It does NOT expose
 * subscription suspend/unsuspend/removal as first-class endpoints, so those go
 * through the documented CLI gateway (`POST /api/v2/cli/{utility}/call`), which
 * runs the same `subscription` utility the panel uses internally. That gateway
 * returns its own `code`/`stdout`/`stderr` envelope rather than an HTTP error,
 * so a non-zero `code` has to be checked explicitly.
 */
class PleskClient
{
    private const DEFAULT_PORT = 8443;

    public function __construct(
        private readonly Server $server,
        private readonly bool $verifyTls = true,
        private readonly int $timeout = 30,
    ) {}

    public static function isConfigured(?Server $server): bool
    {
        return $server !== null
            && trim((string) $server->api_key) !== ''
            && (trim((string) $server->api_url) !== '' || trim((string) $server->ip_address) !== '');
    }

    /**
     * POST a REST v2 resource and return the decoded body.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws PanelException
     */
    public function post(string $path, array $payload): array
    {
        return $this->send('post', $path, $payload);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PanelException
     */
    public function get(string $path): array
    {
        return $this->send('get', $path, null);
    }

    /**
     * Run a Plesk CLI utility through the REST gateway.
     *
     * @param  list<string>  $params
     *
     * @throws PanelException
     */
    public function cli(string $utility, array $params): void
    {
        $result = $this->send('post', '/cli/'.$utility.'/call', ['params' => array_values($params)]);

        // The gateway answers HTTP 200 even when the utility fails; the real
        // outcome is the exit code.
        if ((int) ($result['code'] ?? 0) !== 0) {
            throw new PanelException(sprintf(
                'Plesk CLI %s failed: %s',
                $utility,
                trim((string) ($result['stderr'] ?? $result['stdout'] ?? 'no output')) ?: 'no output',
            ));
        }
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     *
     * @throws PanelException
     */
    private function send(string $method, string $path, ?array $payload): array
    {
        $url = $this->baseUrl().'/api/v2'.$path;

        try {
            $response = $payload === null
                ? $this->request()->get($url)
                : $this->request()->{$method}($url, $payload);
        } catch (Throwable $e) {
            throw new PanelException(sprintf(
                'Could not reach Plesk at %s: %s',
                $this->baseUrl(),
                $e->getMessage(),
            ), previous: $e);
        }

        $body = $response->json();

        if ($response->failed()) {
            // Plesk reports errors as {code, message} with a 4xx/5xx status.
            $message = is_array($body)
                ? (string) ($body['message'] ?? $body['error'] ?? $response->status())
                : (string) $response->status();

            throw new PanelException("Plesk {$path} failed: {$message}");
        }

        return is_array($body) ? $body : [];
    }

    private function request(): PendingRequest
    {
        $key = trim((string) $this->server->api_key);
        $user = trim((string) $this->server->api_username);

        $request = Http::withOptions(['verify' => $this->verifyTls])
            ->timeout($this->timeout)
            ->acceptJson();

        // A username alongside the secret means it is an admin password, which
        // Plesk expects as basic auth; on its own the secret is an API key.
        return $user !== ''
            ? $request->withBasicAuth($user, $key)
            : $request->withHeaders(['X-API-Key' => $key]);
    }

    private function baseUrl(): string
    {
        $configured = trim((string) $this->server->api_url);

        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return 'https://'.trim((string) $this->server->ip_address).':'.self::DEFAULT_PORT;
    }
}
