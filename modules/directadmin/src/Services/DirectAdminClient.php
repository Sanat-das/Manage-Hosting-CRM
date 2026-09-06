<?php

declare(strict_types=1);

namespace Modules\DirectAdmin\Services;

use App\Contracts\Module\PanelException;
use App\Models\Server;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * DirectAdmin CMD_API client (port 2222).
 *
 * Two things make this unlike the other panels:
 *
 * 1. Responses are **URL-encoded query strings**, not JSON —
 *    `error=1&text=Cannot%20Create%20User&details=...`. So the body is parsed
 *    with parse_str() and success is `error=0`. A JSON decode would silently
 *    yield null and every call would look like a transport failure.
 * 2. `error=0` is also what a *login page* returns when credentials are wrong,
 *    because DirectAdmin answers 200 with an HTML login form. A body that does
 *    not parse into an `error` key at all is therefore treated as a failure
 *    rather than a success.
 *
 * Auth is HTTP basic with an admin username and a **login key** (Login Keys in
 * DirectAdmin), not the account password.
 */
class DirectAdminClient
{
    private const DEFAULT_PORT = 2222;

    public function __construct(
        private readonly Server $server,
        private readonly bool $verifyTls = true,
        private readonly int $timeout = 30,
    ) {}

    public static function isConfigured(?Server $server): bool
    {
        return $server !== null
            && trim((string) $server->api_username) !== ''
            && trim((string) $server->api_key) !== ''
            && (trim((string) $server->api_url) !== '' || trim((string) $server->ip_address) !== '');
    }

    /**
     * Call a CMD_API_* command.
     *
     * @param  array<string, scalar|null>  $params
     * @return array<string, mixed> the parsed response
     *
     * @throws PanelException
     */
    public function call(string $command, array $params = []): array
    {
        $url = $this->baseUrl().'/'.$command;

        try {
            $response = Http::withBasicAuth(
                trim((string) $this->server->api_username),
                trim((string) $this->server->api_key),
            )
                ->withOptions(['verify' => $this->verifyTls])
                ->timeout($this->timeout)
                ->asForm()
                ->post($url, $params);
        } catch (Throwable $e) {
            throw new PanelException(sprintf(
                'Could not reach DirectAdmin at %s: %s',
                $this->baseUrl(),
                $e->getMessage(),
            ), previous: $e);
        }

        if ($response->failed()) {
            throw new PanelException(sprintf('DirectAdmin %s returned HTTP %d', $command, $response->status()));
        }

        parse_str($response->body(), $parsed);

        if (! array_key_exists('error', $parsed)) {
            // Almost always the HTML login form: valid HTTP, useless body.
            throw new PanelException(
                "DirectAdmin {$command} returned an unexpected response - check the server's api_username and login key."
            );
        }

        if ((string) $parsed['error'] !== '0') {
            throw new PanelException(sprintf(
                'DirectAdmin %s failed: %s',
                $command,
                $this->reason($parsed),
            ));
        }

        return $parsed;
    }

    /**
     * DirectAdmin splits a failure across `text` (headline) and `details`
     * (which may carry HTML line breaks); join whatever is present.
     *
     * @param  array<string, mixed>  $parsed
     */
    private function reason(array $parsed): string
    {
        $parts = array_filter([
            isset($parsed['text']) ? trim(strip_tags((string) $parsed['text'])) : null,
            isset($parsed['details']) ? trim(strip_tags(str_replace('<br>', ' ', (string) $parsed['details']))) : null,
        ]);

        return $parts === [] ? 'no reason given' : implode(' - ', $parts);
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
