<?php

declare(strict_types=1);

namespace App\Modules\Virtualizor\Services;

use App\Contracts\Integrations\PanelException;
use App\Models\Server;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Virtualizor admin API client (port 4085).
 *
 * Unlike the control panels, Virtualizor takes the action as an `act` query
 * parameter and the credentials as `apikey` / `apipass` alongside it, with
 * `api=json` for a JSON response.
 *
 * Its error reporting is the awkward part: a failed call still answers HTTP 200
 * with `{"error": {...}}` or `{"error": "..."}` — sometimes a map of field
 * errors, sometimes a bare string — and a *successful* call simply omits the
 * key. `done` is likewise either a truthy scalar or a map depending on the
 * action. So success is "no error key", and errors are flattened to a readable
 * string rather than assumed to be one shape.
 */
class VirtualizorClient
{
    private const DEFAULT_PORT = 4085;

    public function __construct(
        private readonly Server $server,
        private readonly bool $verifyTls = true,
        private readonly int $timeout = 60,
    ) {}

    public static function isConfigured(?Server $server): bool
    {
        return $server !== null
            && trim((string) $server->api_username) !== ''
            && trim((string) $server->api_key) !== ''
            && (trim((string) $server->api_url) !== '' || trim((string) $server->ip_address) !== '');
    }

    /**
     * Call an admin API action.
     *
     * @param  array<string, scalar|null>  $params  query parameters (act-specific)
     * @param  array<string, scalar|null>  $post  POST body for create/edit actions
     * @return array<string, mixed>
     *
     * @throws PanelException
     */
    public function call(string $act, array $params = [], array $post = []): array
    {
        $query = $params + [
            'act' => $act,
            'api' => 'json',
            // api_username holds the API key id, api_key the pass - Virtualizor
            // issues them as a pair under Configuration > API Credentials.
            'apikey' => trim((string) $this->server->api_username),
            'apipass' => trim((string) $this->server->api_key),
        ];

        $url = $this->baseUrl().'/index.php?'.http_build_query($query);

        try {
            $response = Http::withOptions(['verify' => $this->verifyTls])
                ->timeout($this->timeout)
                ->asForm()
                ->post($url, $post);
        } catch (Throwable $e) {
            throw new PanelException(sprintf(
                'Could not reach Virtualizor at %s: %s',
                $this->baseUrl(),
                $e->getMessage(),
            ), previous: $e);
        }

        if ($response->failed()) {
            throw new PanelException(sprintf('Virtualizor %s returned HTTP %d', $act, $response->status()));
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new PanelException("Virtualizor {$act} returned a non-JSON response");
        }

        if (! empty($body['error'])) {
            throw new PanelException(sprintf('Virtualizor %s failed: %s', $act, $this->flattenError($body['error'])));
        }

        return $body;
    }

    /**
     * OS templates a VPS can be created from.
     *
     * `act=ostemplates` answers with several maps (`oses`, `oslist`,
     * `ostemplates`); `ostemplates` is the flat, current list. Normalised to
     * [{osid, name, type}], deduped by osid and sorted by name so the picker
     * is stable.
     *
     * @return list<array{osid: string, name: string, type: string}>
     *
     * @throws PanelException
     */
    public function listOsTemplates(): array
    {
        $body = $this->call('ostemplates');

        $raw = $body['ostemplates'] ?? $body['oses'] ?? [];

        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        $seen = [];

        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $rawId = $entry['osid'] ?? null;

            if (! is_numeric($rawId)) {
                continue;
            }

            $osid = (string) (int) $rawId;

            if ((int) $osid <= 0 || isset($seen[$osid])) {
                continue;
            }

            $seen[$osid] = true;

            $name = trim((string) ($entry['name'] ?? ''));
            $out[] = [
                'osid' => $osid,
                'name' => $name !== '' ? mb_substr($name, 0, 80) : $osid,
                'type' => mb_substr(trim((string) ($entry['type'] ?? '')), 0, 32),
            ];

            if (count($out) >= 200) {
                break;
            }
        }

        usort($out, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * Live power state for one VPS: 'running' | 'stopped', or null when the
     * panel does not list it (treated as gone by the caller).
     *
     * Transport/auth failures throw PanelException, so callers can fail closed
     * instead of reading an outage as a deleted machine.
     *
     * @throws PanelException
     */
    public function vpsState(int $vpsId): ?string
    {
        if ($vpsId <= 0) {
            return null;
        }

        $body = $this->call('vs', ['vs_status' => (string) $vpsId]);

        // Documented shape: { "123": {status: 1, ...} }; tolerate a nested
        // `status` wrapper because panels differ by version.
        $rows = $body['status'] ?? $body;

        if (! is_array($rows)) {
            return null;
        }

        $row = $rows[(string) $vpsId] ?? $rows[$vpsId] ?? null;

        if ($row === null) {
            return null;
        }

        if (is_array($row)) {
            $row = $row['status'] ?? null;
        }

        return (string) $row === '1' ? 'running' : 'stopped';
    }

    /**
     * Restart a VPS — the `restart` signal on the `vs` action, mirroring the
     * existing suspend/unsuspend/delete verbs.
     *
     * @throws PanelException
     */
    public function restartVps(int $vpsId): void
    {
        $this->call('vs', ['restart' => (string) $vpsId]);
    }

    /**
     * Virtualizor returns errors as a string, a list, or a field => message
     * map depending on the action. Flatten all three to one line.
     */
    private function flattenError(mixed $error): string
    {
        if (is_string($error)) {
            return $error;
        }

        if (is_array($error)) {
            $parts = [];

            array_walk_recursive($error, static function ($value) use (&$parts): void {
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $parts[] = trim((string) $value);
                }
            });

            if ($parts !== []) {
                return implode('; ', array_unique($parts));
            }
        }

        return 'no reason given';
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
