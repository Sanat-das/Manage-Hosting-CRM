<?php

declare(strict_types=1);

namespace App\Modules\Proxmox\Services;

use App\Contracts\Integrations\AbstractPanelModule;
use App\Contracts\Integrations\PanelException;
use App\Contracts\Integrations\ServerConnectionResult;
use App\Contracts\Integrations\ServerInfoDTO;
use App\Models\Server;
use App\Support\SecretRedactor;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Proxmox VE API client (PVE 7.x / 8.x / 9.x, `/api2/json`).
 *
 * Verified against a live PVE 9.1 cluster (3 nodes) for the read paths:
 * ticket auth, /version, /nodes, /cluster/status, /cluster/resources,
 * /cluster/nextid and the `listVms()` contract. The mutating paths
 * (clone/create/resize/destroy) are written against the documented API shape
 * and covered by faked tests — check the first provision on a staging node.
 *
 * Two authentication modes, selected per server via `connection_meta.auth_type`:
 *
 *  - **token** — sends `Authorization: PVEAPIToken=USER@REALM!TOKENID=SECRET`.
 *    Stateless, no CSRF, and PVE lets the token carry its own role with a
 *    privilege separator, so a leaked secret is scoped. This is what PVE
 *    recommends for automation.
 *  - **ticket** — `POST /access/ticket` with `user@realm` + password, then the
 *    returned `PVEAuthCookie` plus `CSRFPreventionToken` header. The ticket is
 *    cached per (server, username, password) so a page render does not
 *    re-authenticate for every call, and a 401 drops it and retries once.
 *
 * Three quirks of this API shape the code below:
 *
 *  - Every response wraps its payload in `{"data": ...}` — including errors,
 *    which arrive as a *different* envelope (`{"errors": ..., "message": ...}`)
 *    rather than an HTTP error alone. Both are unwrapped here.
 *  - The API is task-based: mutating calls answer with a UPID and return long
 *    before the work is done, so `waitForTask()` is what makes provisioning
 *    synchronous and truthful.
 *  - A VMID that already exists is rejected by PVE rather than reused, so the
 *    next free id must be computed from the cluster's actual usage.
 */
final class ProxmoxClient
{
    /** PVE's default API port. */
    public const DEFAULT_PORT = 8006;

    private const AUTH_TOKEN = 'token';

    private const AUTH_TICKET = 'ticket';

    /** Ticket cache TTL. PVE tickets last 2h by default; stay well inside it. */
    private const TICKET_CACHE_TTL = 1800;

    /**
     * How far `nextVmId()` will probe above a requested floor before giving up.
     * A bounded loop keeps a pathological cluster from spinning forever.
     */
    private const VMID_PROBE_LIMIT = 500;

    public function __construct(
        private readonly Server $server,
        private readonly int $timeout = 30,
    ) {}

    // ───────────────────────────── configuration ─────────────────────────────

    /**
     * Credential columns are resolved by field *name* rather than mode, so
     * `serverIsConfigured()` and the constructor always agree.
     *
     * Panel types funnel `api_key`/`api_password_encrypted` through one column
     * (ServerController::mapValidatedToAttributes), so the secret is whichever
     * of the two carries a value — never a hardcoded one.
     */
    public function authType(): string
    {
        return self::authTypeFor($this->server);
    }

    public static function authTypeFor(?Server $server): string
    {
        $meta = is_array($server?->connection_meta) ? $server->connection_meta : [];
        $type = strtolower(trim((string) ($meta['auth_type'] ?? '')));

        return $type === self::AUTH_TICKET ? self::AUTH_TICKET : self::AUTH_TOKEN;
    }

    public static function tokenId(?Server $server): string
    {
        return trim((string) ($server->api_username ?? ''));
    }

    public static function tokenSecret(?Server $server): string
    {
        return self::secret($server);
    }

    /**
     * Ticket-auth username.
     *
     * Falls back to the token-id column when the dedicated ticket field is
     * blank: both hold `user@realm`, and a form that filled one while auth was
     * set to the other is a near-miss rather than a misconfiguration. Without
     * this, the operator gets "needs a username" for a field they believe they
     * filled.
     */
    public static function ticketUsername(?Server $server): string
    {
        $meta = is_array($server?->connection_meta) ? $server->connection_meta : [];
        $explicit = trim((string) ($meta['ticket_username'] ?? ''));

        return $explicit !== '' ? $explicit : self::tokenId($server);
    }

    public static function ticketPassword(?Server $server): string
    {
        return self::secret($server);
    }

    public static function isConfigured(?Server $server): bool
    {
        if ($server === null || self::host($server) === '') {
            return false;
        }

        if (self::authTypeFor($server) === self::AUTH_TICKET) {
            return self::ticketUsername($server) !== '' && self::ticketPassword($server) !== '';
        }

        // The token secret has no dedicated column; a half-filled server must
        // not be reported as ready, so both the id and the secret are required.
        return self::tokenId($server) !== '' && self::tokenSecret($server) !== '';
    }

    /**
     * The secret lives in whichever column ServerController populated. Both are
     * encrypted casts (or plain), so a read can throw on a legacy plaintext
     * `api_password_encrypted` row — treat that as "no secret" rather than
     * letting it escape into the provisioning path.
     */
    private static function secret(?Server $server): string
    {
        if ($server === null) {
            return '';
        }

        try {
            $password = trim((string) ($server->api_password_encrypted ?? ''));
        } catch (Throwable) {
            $password = '';
        }

        if ($password !== '') {
            return $password;
        }

        return trim((string) ($server->api_key ?? ''));
    }

    /**
     * Split whatever address the server row carries into host + port.
     *
     * `api_url` is free text and operators routinely paste a full address:
     * `https://pve.example.com:8006`, a bare `10.0.0.5:8006`, or a trailing
     * slash. The port is tracked separately (`connection_meta.port`, default
     * 8006), so the host must be stripped of scheme, path AND port — using the
     * value verbatim produced `https://10.0.0.5:8006:8006/...` and a hard
     * "Invalid host" failure.
     *
     * @return array{host: string, port: int|null}
     */
    private static function splitHostPort(?Server $server): array
    {
        if ($server === null) {
            return ['host' => '', 'port' => null];
        }

        $raw = trim((string) ($server->api_url ?? ''));

        if ($raw === '') {
            $raw = trim((string) ($server->ip_address ?? ''));
        }

        if ($raw === '') {
            return ['host' => '', 'port' => null];
        }

        $host = $raw;
        $port = null;

        if (str_contains($raw, '://')) {
            $parsed = parse_url($raw);
            $host = (string) ($parsed['host'] ?? '');
            if (isset($parsed['port'])) {
                $port = (int) $parsed['port'];
            }
        } elseif (filter_var($raw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            // A bare IPv6 literal (`::1`) is all host. `::1:8006` is also a
            // valid IPv6 address, so it must never be split as host + port.
            $host = $raw;
        } elseif (preg_match('/^(.*):(\d+)$/', $raw, $m) === 1) {
            // host:port (also covers [::1]:8006).
            $host = $m[1];
            $port = (int) $m[2];
        }

        $host = trim($host, " \t\n\r\0\x0B/");

        // A bare IPv6 host must be bracketed before it is interpolated into a
        // URL; parse_url() already returns the bracketed form.
        if ($host !== '' && $host[0] !== '[' && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $host = '['.$host.']';
        }

        return ['host' => $host, 'port' => $port];
    }

    private static function host(?Server $server): string
    {
        return self::splitHostPort($server)['host'];
    }

    public function port(): int
    {
        $meta = is_array($this->server->connection_meta) ? $this->server->connection_meta : [];
        $port = (int) ($meta['port'] ?? 0);

        if ($port >= 1 && $port <= 65535) {
            return $port;
        }

        // Fall back to a port embedded in the address before assuming the default,
        // so `https://pve.example.com:8443` is honoured rather than rewritten.
        $fromAddress = self::splitHostPort($this->server)['port'];

        if ($fromAddress !== null && $fromAddress >= 1 && $fromAddress <= 65535) {
            return $fromAddress;
        }

        return self::DEFAULT_PORT;
    }

    public function verifyTls(): bool
    {
        $meta = is_array($this->server->connection_meta) ? $this->server->connection_meta : [];

        return ! array_key_exists('verify_tls', $meta) || (bool) $meta['verify_tls'];
    }

    /** `host` (+ port on anything but 8006) — display form, never a credential. */
    public function hostLabel(): string
    {
        $host = self::host($this->server);
        $port = $this->port();

        return $port === self::DEFAULT_PORT ? $host : sprintf('%s:%d', $host, $port);
    }

    // ─────────────────────────────── transport ───────────────────────────────

    /**
     * One PVE API call.
     *
     * @param  array<string, mixed>  $params  query/body parameters
     * @return mixed the unwrapped `data` payload
     *
     * @throws PanelException on transport, auth, HTTP or API error
     */
    public function call(string $method, string $path, array $params = []): mixed
    {
        if (self::host($this->server) === '') {
            throw new PanelException('Proxmox VE host is not set on this server (set api_url or ip_address).');
        }

        $response = $this->send($method, $path, $params);

        // A cached ticket can be revoked server-side at any time; drop it once
        // and retry rather than failing the operation on a stale credential.
        if ($response->status() === 401 && $this->authType() === self::AUTH_TICKET) {
            $this->forgetTicket();
            $response = $this->send($method, $path, $params);
        }

        return $this->unwrap($response, $path);
    }

    private function send(string $method, string $path, array $params): Response
    {
        $request = $this->request();

        try {
            $method = strtoupper($method);

            // PVE rejects ANY request body on DELETE ("Unexpected content for
            // method 'DELETE'", HTTP 501) — purge flags must travel in the
            // query string instead.
            if ($method === 'DELETE') {
                $url = $this->url($path);
                if ($params !== []) {
                    $url .= '?'.http_build_query($params);
                }

                return $request->delete($url);
            }

            return match ($method) {
                'GET' => $request->get($this->url($path), $params),
                // PVE's API server reads write bodies as form data. Under
                // Laravel's default JSON body format a parameter-less write
                // serialises as an empty JSON *array* (`[]`), which PVE 9.1
                // answers with HTTP 500 "Not a HASH reference" — every start
                // and reboot failed on a live cluster because of it. Form
                // encoding keeps the body empty instead.
                'PUT' => $params === []
                    ? $request->asForm()->put($this->url($path))
                    : $request->put($this->url($path), $params),
                default => $params === []
                    ? $request->asForm()->post($this->url($path))
                    : $request->post($this->url($path), $params),
            };
        } catch (Throwable $e) {
            throw new PanelException(sprintf(
                'Could not reach Proxmox VE at %s: %s',
                $this->hostLabel(),
                $this->sanitize($e->getMessage()),
            ), previous: $e);
        }
    }

    private function request(): PendingRequest
    {
        $request = Http::withOptions(['verify' => $this->verifyTls()])
            ->timeout($this->timeout)
            ->acceptJson();

        if ($this->authType() === self::AUTH_TICKET) {
            return $request
                ->withHeaders(['CSRFPreventionToken' => $this->ticket()['csrf']])
                ->withCookies(['PVEAuthCookie' => $this->ticket()['ticket']], parse_url($this->url('/'), PHP_URL_HOST) ?: '');
        }

        return $request->withHeaders([
            'Authorization' => sprintf(
                'PVEAPIToken=%s=%s',
                self::tokenId($this->server),
                self::tokenSecret($this->server),
            ),
        ]);
    }

    /**
     * Authenticate and cache the ticket.
     *
     * @return array{ticket: string, csrf: string}
     *
     * @throws PanelException
     */
    private function ticket(): array
    {
        $username = self::ticketUsername($this->server);
        $password = self::ticketPassword($this->server);

        if ($username === '' || $password === '') {
            throw new PanelException('Proxmox VE ticket auth needs a username (user@realm) and a password.');
        }

        $key = $this->ticketCacheKey($username, $password);
        $cached = cache()->get($key);

        if (is_array($cached) && isset($cached['ticket'], $cached['csrf'])) {
            return ['ticket' => (string) $cached['ticket'], 'csrf' => (string) $cached['csrf']];
        }

        try {
            $response = Http::withOptions(['verify' => $this->verifyTls()])
                ->timeout($this->timeout)
                ->acceptJson()
                ->asForm()
                ->post($this->url('/access/ticket'), [
                    'username' => $username,
                    'password' => $password,
                ]);
        } catch (Throwable $e) {
            throw new PanelException(sprintf(
                'Could not reach Proxmox VE at %s: %s',
                $this->hostLabel(),
                $this->sanitize($e->getMessage()),
            ), previous: $e);
        }

        $data = $this->unwrap($response, '/access/ticket');
        $ticket = is_array($data) ? (string) ($data['ticket'] ?? '') : '';
        $csrf = is_array($data) ? (string) ($data['CSRFPreventionToken'] ?? '') : '';

        if ($ticket === '' || $csrf === '') {
            throw new PanelException('Proxmox VE returned no ticket for those credentials.');
        }

        cache()->put($key, ['ticket' => $ticket, 'csrf' => $csrf], self::TICKET_CACHE_TTL);

        return ['ticket' => $ticket, 'csrf' => $csrf];
    }

    public function forgetTicket(): void
    {
        cache()->forget($this->ticketCacheKey(
            self::ticketUsername($this->server),
            self::ticketPassword($this->server),
        ));
    }

    /** Hash the password so no credential ever appears in a cache key. */
    private function ticketCacheKey(string $username, string $password): string
    {
        return sprintf('proxmox:ticket:%d:%s:%s', $this->server->id, $username, hash('sha256', $password));
    }

    private function url(string $path): string
    {
        return sprintf(
            'https://%s:%d/api2/json%s',
            self::host($this->server),
            $this->port(),
            str_starts_with($path, '/') ? $path : '/'.$path,
        );
    }

    /**
     * Strip anything credential-shaped out of a transport error.
     *
     * Guzzle/curl messages routinely embed the request it failed to send, so a
     * raw exception can carry the token or password into a flash message,
     * `servers.connection_error` or a log line. Everything the client knows to
     * be secret is removed here, then the shared redactor runs over the rest.
     */
    private function sanitize(string $message): string
    {
        $secrets = array_filter([
            self::secret($this->server),
            self::tokenId($this->server),
            self::ticketUsername($this->server),
        ], static fn (string $s): bool => strlen($s) >= 4);

        if ($secrets !== []) {
            $message = str_replace($secrets, '***', $message);
        }

        return SecretRedactor::redact($message);
    }

    /**
     * Unwrap `{"data": …}` and turn every failure shape into one readable
     * PanelException.
     *
     * PVE reports API errors as HTTP 4xx/5xx *and* as an `errors` map / bare
     * `message` in the body, so the body is preferred over the status line
     * when it carries a reason.
     */
    private function unwrap(Response $response, string $path): mixed
    {
        $body = null;

        try {
            $body = $response->json();
        } catch (Throwable) {
            $body = null;
        }

        $detail = '';

        if (is_array($body)) {
            if (isset($body['errors']) && is_array($body['errors'])) {
                $parts = [];
                foreach ($body['errors'] as $field => $message) {
                    $parts[] = trim((string) $field).': '.trim((string) $message);
                }
                $detail = implode('; ', array_filter($parts));
            }

            if ($detail === '' && isset($body['message']) && is_string($body['message'])) {
                $detail = trim($body['message']);
            }
        }

        if ($response->status() === 401) {
            throw new PanelException(
                $this->authType() === self::AUTH_TICKET
                    ? 'Proxmox VE rejected the ticket credentials (check the username is user@realm, e.g. root@pam).'
                    : 'Proxmox VE rejected the API token (check the token id is user@realm!tokenid and the secret is the token value).',
            );
        }

        if ($response->status() === 403) {
            throw new PanelException(
                'Proxmox VE denied this operation (the token or user lacks the required role/permissions on this node or VM).',
            );
        }

        if ($response->failed()) {
            throw new PanelException(sprintf(
                'Proxmox VE %s returned HTTP %d%s',
                $path,
                $response->status(),
                $detail !== '' ? ': '.$detail : '',
            ));
        }

        if (! is_array($body)) {
            throw new PanelException(sprintf('Proxmox VE %s returned a non-JSON response.', $path));
        }

        if ($detail !== '' && ! array_key_exists('data', $body)) {
            throw new PanelException(sprintf('Proxmox VE %s failed: %s', $path, $detail));
        }

        $data = $body['data'] ?? null;

        // A null `data` on a mutating call is PVE's "accepted with no payload";
        // only a null body on a read is suspicious.
        return $data;
    }

    // ─────────────────────────────── discovery ───────────────────────────────

    /** @return list<string> */
    public function nodes(): array
    {
        $data = $this->call('GET', '/nodes');

        if (! is_array($data)) {
            return [];
        }

        $nodes = [];
        foreach ($data as $node) {
            $name = is_array($node) ? trim((string) ($node['node'] ?? '')) : '';
            if ($name !== '') {
                $nodes[] = $name;
            }
        }

        return $nodes;
    }

    /**
     * Node names, cached briefly — several calls in one provision would
     * otherwise re-list the cluster each time.
     *
     * @return list<string>
     */
    public function cachedNodes(int $ttlSeconds = 60): array
    {
        $nodes = cache()->remember(
            sprintf('proxmox:server:%d:nodes', $this->server->id),
            $ttlSeconds,
            fn (): array => $this->nodes(),
        );

        return is_array($nodes) ? array_values($nodes) : [];
    }

    /**
     * The first node that is neither offline nor unknown, so a provision does
     * not target a node that cannot schedule. Falls back to the first listed
     * node when every node reports a transient status.
     */
    public function defaultNode(): string
    {
        $nodes = $this->call('GET', '/nodes');
        $names = [];
        $online = '';

        if (is_array($nodes)) {
            foreach ($nodes as $node) {
                if (! is_array($node)) {
                    continue;
                }
                $name = trim((string) ($node['node'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $names[] = $name;
                if ($online === '' && strtolower(trim((string) ($node['status'] ?? ''))) === 'online') {
                    $online = $name;
                }
            }
        }

        if ($online !== '') {
            return $online;
        }

        if ($names !== []) {
            return $names[0];
        }

        throw new PanelException('Proxmox VE reports no nodes — check the API token/user can see the cluster.');
    }

    /**
     * Templates and images available to clone on a node.
     *
     * @return list<array{vmid: int, name: string, type: string, node: string, status: string, template: bool}>
     */
    public function listTemplates(string $node): array
    {
        $data = $this->call('GET', sprintf('/nodes/%s/qemu', rawurlencode($node)));
        $out = [];

        if (! is_array($data)) {
            return [];
        }

        foreach ($data as $vm) {
            if (! is_array($vm)) {
                continue;
            }
            $vmid = (int) ($vm['vmid'] ?? 0);
            if ($vmid <= 0) {
                continue;
            }
            $out[] = [
                'vmid' => $vmid,
                'name' => trim((string) ($vm['name'] ?? '')),
                'type' => 'qemu',
                'node' => $node,
                'status' => trim((string) ($vm['status'] ?? '')),
                'template' => (bool) ($vm['template'] ?? false),
            ];
        }

        return $out;
    }

    /**
     * Pick the storage pool a VM disk can actually be built on.
     *
     * PVE returns storages in an arbitrary order, and that list mixes pools that
     * cannot hold a disk (ISO/template/backup-only), pools that are **disabled**,
     * and pools with no free space. Returning the first "disk-capable" entry
     * therefore picked `local` on one call and `local-zfs` on the next on the
     * same node — and that `local` was both disabled and 0 bytes free, which
     * turns into a confusing mid-clone failure.
     *
     * Only active, disk-capable pools with free space are eligible. The roomiest
     * wins, with the name breaking ties so the choice does not drift between
     * calls. A `$requested` pool is validated instead of chosen, so a product
     * that pins one fails with a clear reason before any clone starts.
     *
     * @throws PanelException
     */
    public function storageFor(string $node, ?string $requested = null): string
    {
        return $this->storageForContent($node, ['images', 'rootdir'], $requested, 'VM disks');
    }

    /**
     * Pick an ISO-capable pool for an empty-VM build.
     *
     * ISOs routinely live on a different pool than VM disks (`local` vs
     * `local-zfs`/RBD/Ceph), so attaching the ISO to the disk pool fails with a
     * PVE content-type rejection on the common layout. Attaching an existing ISO
     * consumes no space, so a full pool is still usable here.
     *
     * @throws PanelException
     */
    public function storageForIso(string $node, ?string $requested = null): string
    {
        return $this->storageForContent($node, ['iso'], $requested, 'ISOs', requireSpace: false);
    }

    /**
     * @param  list<string>  $requiredContent  PVE content tokens the pool must offer (any of)
     * @param  bool  $requireSpace  false for content (ISOs) that needs no free space to attach
     *
     * @throws PanelException
     */
    private function storageForContent(
        string $node,
        array $requiredContent,
        ?string $requested,
        string $purpose,
        bool $requireSpace = true,
    ): string {
        $rows = $this->call('GET', sprintf('/nodes/%s/storage', rawurlencode($node)));
        $rows = is_array($rows) ? $rows : [];

        /** @var array<string, array{active: bool, usable: bool, avail: int}> $pools */
        $pools = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['storage'] ?? ''));
            if ($name === '') {
                continue;
            }

            // PVE reports content as a comma list of fixed tokens; match tokens
            // exactly so a pool advertising `backup` cannot be read as `images`.
            $tokens = array_map('trim', explode(',', (string) ($row['content'] ?? '')));

            $pools[$name] = [
                'active' => ! empty($row['active']),
                'usable' => array_intersect($requiredContent, $tokens) !== [],
                'avail' => (int) ($row['avail'] ?? 0),
            ];
        }

        $requested = $requested !== null ? trim($requested) : '';

        if ($requested !== '') {
            if (! isset($pools[$requested])) {
                throw new PanelException(sprintf(
                    'Storage "%s" is not available on Proxmox VE node "%s" (available: %s).',
                    $requested,
                    $node,
                    $pools === [] ? 'none' : implode(', ', array_keys($pools)),
                ));
            }

            $pool = $pools[$requested];

            if (! $pool['active']) {
                throw new PanelException(sprintf('Storage "%s" on Proxmox VE node "%s" is not active.', $requested, $node));
            }

            if (! $pool['usable']) {
                throw new PanelException(sprintf(
                    'Storage "%s" on Proxmox VE node "%s" cannot hold %s (its content is not %s).',
                    $requested,
                    $node,
                    $purpose,
                    implode('/', $requiredContent),
                ));
            }

            if ($requireSpace && $pool['avail'] <= 0) {
                throw new PanelException(sprintf('Storage "%s" on Proxmox VE node "%s" has no free space.', $requested, $node));
            }

            return $requested;
        }

        $eligible = [];
        foreach ($pools as $name => $pool) {
            if ($pool['active'] && $pool['usable'] && (! $requireSpace || $pool['avail'] > 0)) {
                $eligible[$name] = $pool['avail'];
            }
        }

        if ($eligible === []) {
            throw new PanelException(sprintf(
                'No storage on Proxmox VE node "%s" can hold %s — need an active %s pool%s.',
                $node,
                $purpose,
                implode('/', $requiredContent),
                $requireSpace ? ' with free space' : '',
            ));
        }

        uksort($eligible, static function (string $a, string $b) use ($eligible): int {
            return ($eligible[$b] <=> $eligible[$a]) ?: strcmp($a, $b);
        });

        return (string) array_key_first($eligible);
    }

    /**
     * Is this exact ISO present on the given pool?
     *
     * PVE accepts a CD-ROM volid for a file that is not there, so a wrong
     * filename would build a VM that boots to "no bootable device". Checking
     * first makes the mistake fail before the VM exists.
     *
     * @throws PanelException on transport/API failure
     */
    public function isoExists(string $node, string $storage, string $iso): bool
    {
        $rows = $this->call('GET', sprintf(
            '/nodes/%s/storage/%s/content',
            rawurlencode($node),
            rawurlencode($storage),
        ), ['content' => 'iso']);

        if (! is_array($rows)) {
            return false;
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            // volid reads `storage:iso/filename.iso`.
            $volid = trim((string) ($row['volid'] ?? ''));

            if ($volid !== '' && strcasecmp(basename(str_replace('\\', '/', $volid)), $iso) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate a caller-supplied ISO filename.
     *
     * A volid is `storage:iso/<filename>` and the caller supplies only the
     * filename, so a path separator or a comma (PVE's option delimiter) must be
     * rejected rather than interpolated into `ide2`.
     *
     * @throws PanelException
     */
    public function assertIsoName(string $iso): string
    {
        $iso = trim($iso);

        if ($iso === '' || preg_match('/^[^,\/\\\\\x00-\x1F\x7F]+$/', $iso) !== 1) {
            throw new PanelException(sprintf(
                'ISO "%s" is not a plain filename — use the name as it appears on the storage, without path separators or commas.',
                $iso,
            ));
        }

        return $iso;
    }

    /**
     * Next unused VMID at or above `$floor`.
     *
     * Two different PVE endpoints semantics matter here, and conflating them is
     * an easy way to break provisioning:
     *
     *  - `GET /cluster/nextid` with **no** `vmid` answers with the cluster's next
     *    free id — it ignores any notion of a floor.
     *  - `GET /cluster/nextid?vmid=N` **validates** N. It is not "search upward
     *    from N": a taken N answers 400 `VM N already exists`.
     *
     * So the floor is honoured by taking PVE's suggestion when it is already at
     * or above the floor, and otherwise probing upward for the first free id.
     *
     * There is no atomic reserve in the PVE API, so a race between two
     * concurrent provisions is possible; PVE refuses the loser's duplicate id
     * and the failure surfaces rather than silently colliding.
     */
    public function nextVmId(int $floor = 100): int
    {
        $floor = max($floor, 1);

        $data = $this->call('GET', '/cluster/nextid');
        $suggested = (int) (is_array($data) ? ($data['vmid'] ?? 0) : $data);

        if ($suggested >= $floor) {
            return $suggested;
        }

        for ($candidate = $floor; $candidate <= $floor + self::VMID_PROBE_LIMIT; $candidate++) {
            if ($this->vmIdIsFree($candidate)) {
                return $candidate;
            }
        }

        throw new PanelException(sprintf(
            'Proxmox VE has no free VMID in the %d-%d range.',
            $floor,
            $floor + self::VMID_PROBE_LIMIT,
        ));
    }

    /** Is this exact VMID free? Used to honour a floor and to fail early on a pinned id. */
    public function vmIdIsFree(int $vmid): bool
    {
        try {
            $this->call('GET', '/cluster/nextid', ['vmid' => $vmid]);

            return true;
        } catch (PanelException $e) {
            // PVE answers 400 with `vmid: VM <id> already exists` when taken.
            if (str_contains($e->getMessage(), 'already exists')) {
                return false;
            }

            throw $e;
        }
    }

    // ─────────────────────────────── lifecycle ───────────────────────────────

    /**
     * Clone a template into a new VM and wait for PVE to finish.
     *
     * The call is addressed to the node the **template** lives on, with
     * `target` naming where the new VM should end up. Using one node for both
     * only works when they happen to be the same: a template on node A being
     * cloned for provisioning onto node B would otherwise ask node B for a
     * template it does not have.
     *
     * @throws PanelException
     */
    public function cloneVm(
        string $templateNode,
        int $templateVmid,
        int $newVmid,
        string $name,
        ?string $targetNode = null,
        ?string $storage = null,
        bool $full = true,
        int $timeoutSeconds = 300,
    ): void {
        $params = array_filter([
            'newid' => $newVmid,
            'name' => $name,
            'full' => $full ? 1 : 0,
            'target' => $targetNode,
            'storage' => $storage,
        ], static fn ($v) => $v !== null && $v !== '');

        $upid = $this->call('POST', sprintf(
            '/nodes/%s/qemu/%d/clone',
            rawurlencode($templateNode),
            $templateVmid,
        ), $params);

        // The task runs on the node that owns the template; the new VM appears
        // on the target. A timeout must not read as "nothing happened" — the
        // clone may still be running and finish later.
        $this->waitForTask(
            $templateNode,
            is_string($upid) ? $upid : '',
            $timeoutSeconds,
            sprintf(
                'the clone may still be running: check node "%s" for VMID %d before retrying',
                $targetNode !== null && $targetNode !== '' ? $targetNode : $templateNode,
                $newVmid,
            ),
        );
    }

    /**
     * Which node currently holds this VMID?
     *
     * Used when a product names a template VMID that is not in any curated list,
     * and for locating a template whose recorded node may be stale. A wrong node
     * answers "does not exist" (which `vmExists` reports as absent), and an
     * unreachable node throws — both simply move on to the next candidate.
     */
    public function nodeHoldingVm(int $vmid): ?string
    {
        foreach ($this->cachedNodes() as $node) {
            try {
                if ($this->vmExists($node, $vmid)['exists'] === true) {
                    return $node;
                }
            } catch (PanelException) {
                continue;
            }
        }

        return null;
    }

    /**
     * Build a VM from scratch when no template is configured.
     *
     * Not every PVE storage supports `import-from`, so the ISO path is attached
     * as a CD-ROM and the installer is driven in the guest rather than assuming
     * an unattended install. Reported back as a `notice` so the operator knows
     * the guest still needs an OS install.
     *
     * @throws PanelException
     */
    public function createVm(
        string $node,
        int $vmid,
        string $name,
        int $cpu,
        int $ramMb,
        int $diskGb,
        string $storage,
        ?string $iso = null,
        ?string $bridge = null,
        ?string $vlanTag = null,
        ?string $isoStorage = null,
    ): void {
        $net = 'virtio,bridge='.$this->validateBridge($bridge ?? 'vmbr0');

        if ($vlanTag !== null && trim($vlanTag) !== '') {
            $net .= ',tag='.$this->validateVlanTag($vlanTag);
        }

        $ide2 = null;
        if ($iso !== null && trim($iso) !== '') {
            $iso = $this->assertIsoName($iso);

            // ISOs usually live on a different pool than VM disks; the caller
            // resolves one (storageForIso) so the CD-ROM never points at a
            // disk-only pool.
            $isoPool = $isoStorage !== null && trim($isoStorage) !== '' ? trim($isoStorage) : $storage;

            $ide2 = $isoPool.':iso/'.$iso.',media=cdrom';
        }

        $this->call('POST', sprintf('/nodes/%s/qemu', rawurlencode($node)), array_filter([
            'vmid' => $vmid,
            'name' => $name,
            'cores' => $cpu,
            'memory' => $ramMb,
            'scsihw' => 'virtio-scsi-pci',
            'scsi0' => sprintf('%s:%d', $storage, $diskGb),
            'net0' => $net,
            'ide2' => $ide2,
            'ostype' => 'l26',
            'agent' => 1,
        ], static fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * PVE config values are comma-joined option strings, so a comma in any
     * operator-supplied value would silently inject extra options
     * (`bridge=vmbr0,firewall=0`). Validate at the boundary instead.
     *
     * @throws PanelException
     */
    private function validateBridge(string $bridge): string
    {
        $bridge = trim($bridge);

        if (preg_match('/^[A-Za-z0-9._-]{1,32}$/', $bridge) !== 1) {
            throw new PanelException(sprintf(
                'Bridge "%s" is not a valid Proxmox VE bridge name (letters, digits, dot, underscore and dash only).',
                $bridge,
            ));
        }

        return $bridge;
    }

    /** @throws PanelException */
    private function validateVlanTag(string $vlanTag): int
    {
        $vlanTag = trim($vlanTag);

        if (! ctype_digit($vlanTag) || (int) $vlanTag < 1 || (int) $vlanTag > 4094) {
            throw new PanelException(sprintf('VLAN tag "%s" is not valid — PVE accepts 1-4094.', $vlanTag));
        }

        return (int) $vlanTag;
    }

    /**
     * Re-apply the product's hardware spec to an existing VM.
     *
     * Clone-based provisioning inherits the template's `scsi0` sizing, so a
     * 50 GB product would otherwise ship whatever disk the template had. Only
     * growth is applied — PVE cannot shrink a disk, and `updateVm` is skipped
     * entirely when nothing needs changing.
     *
     * @throws PanelException
     */
    public function applySpec(string $node, int $vmid, int $cpu, int $ramMb, int $diskGb): void
    {
        $config = $this->vmConfig($node, $vmid);

        $currentDiskGb = (int) (($config['scsi0_size'] ?? 0));
        if ($currentDiskGb <= 0) {
            // No size key means the disk is not on scsi0 (template used a
            // different bus); growing the wrong bus would fail, so skip.
            $currentDiskGb = $diskGb;
        }

        $resize = $diskGb > $currentDiskGb ? $diskGb : 0;

        if ($resize > 0) {
            $upid = $this->call('PUT', sprintf(
                '/nodes/%s/qemu/%d/resize',
                rawurlencode($node),
                $vmid,
            ), ['disk' => 'scsi0', 'size' => $resize.'G']);

            $this->waitForTask($node, is_string($upid) ? $upid : '');
        }

        $this->updateVm($node, $vmid, ['cores' => $cpu, 'memory' => $ramMb]);
    }

    /** @param array<string, mixed> $params */
    public function updateVm(string $node, int $vmid, array $params): void
    {
        $this->call('PUT', sprintf('/nodes/%s/qemu/%d/config', rawurlencode($node), $vmid), $params);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PanelException
     */
    public function vmConfig(string $node, int $vmid): array
    {
        $data = $this->call('GET', sprintf(
            '/nodes/%s/qemu/%d/config',
            rawurlencode($node),
            $vmid,
        ));

        $config = is_array($data) ? $data : [];

        // `scsi0` reads `storage:50,size=50G,...`; normalise the size so callers
        // do not parse the disk string themselves.
        $scsi0 = (string) ($config['scsi0'] ?? '');
        if ($scsi0 !== '' && preg_match('/size=(\d+(?:\.\d+)?)([KMGT])/i', $scsi0, $m) === 1) {
            $units = ['K' => 1 / 1024 / 1024, 'M' => 1 / 1024, 'G' => 1, 'T' => 1024];
            $config['scsi0_size'] = (int) round(((float) $m[1]) * ($units[strtoupper($m[2])] ?? 1));
        }

        return $config;
    }

    /**
     * Set the initial guest login on a clone that has a cloud-init drive.
     *
     * PVE writes `ciuser`/`cipassword` into the cloud-init payload it
     * regenerates on boot; without a cloud-init drive the keys are inert, so the
     * caller checks first and reports honestly instead of promising a login the
     * guest does not have. Returns false when there is no cloud-init drive.
     *
     * @throws PanelException on transport/API failure
     */
    public function applyCloudInitCredentials(string $node, int $vmid, string $username, string $password): bool
    {
        if (! $this->hasCloudInitDrive($node, $vmid)) {
            return false;
        }

        $this->updateVm($node, $vmid, [
            'ciuser' => $username,
            'cipassword' => $password,
        ]);

        return true;
    }

    /**
     * Does this VM carry a cloud-init drive?
     *
     * `citype` is set when the drive is configured, and the drive's volid
     * contains `cloudinit` on every storage type; either is enough to know PVE
     * will consume ciuser/cipassword on the next boot.
     */
    public function hasCloudInitDrive(string $node, int $vmid): bool
    {
        $config = $this->vmConfig($node, $vmid);

        if (trim((string) ($config['citype'] ?? '')) !== '') {
            return true;
        }

        foreach ($config as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            if (preg_match('/^(ide|sata|scsi|virtio)\d+$/', (string) $key) === 1
                && stripos($value, 'cloudinit') !== false) {
                return true;
            }
        }

        return false;
    }

    public function startVm(string $node, int $vmid): void
    {
        $this->simpleTask($node, $vmid, 'start');
    }

    /**
     * Set a guest OS user password through the QEMU guest agent.
     *
     * The agent must be running inside the guest; PVE answers synchronously
     * (no task to wait for), so any failure surfaces here as a PanelException
     * through the shared `call()` unwrap.
     *
     * @throws PanelException
     */
    public function setGuestPassword(string $node, int $vmid, string $username, string $password): void
    {
        $this->call('POST', sprintf(
            '/nodes/%s/qemu/%d/agent/set-user-password',
            rawurlencode($node),
            $vmid,
        ), [
            'username' => $username,
            'password' => $password,
            'crypted' => 0,
        ]);
    }

    /** Graceful guest reboot (ACPI); PVE answers with a task that is awaited. */
    public function rebootVm(string $node, int $vmid): void
    {
        $this->simpleTask($node, $vmid, 'reboot');
    }

    /** ACPI shutdown, falling back to a hard stop when the guest ignores it. */
    public function stopVm(string $node, int $vmid, bool $force = true): void
    {
        $upid = $this->call('POST', sprintf(
            '/nodes/%s/qemu/%d/status/shutdown',
            rawurlencode($node),
            $vmid,
        ), $force ? ['forceStop' => 1, 'timeout' => 60] : []);

        $this->waitForTask($node, is_string($upid) ? $upid : '', 90);

        if (strtolower((string) $this->vmStatus($node, $vmid)) === 'running') {
            $this->simpleTask($node, $vmid, 'stop');
        }
    }

    /**
     * Suspend = stop the VM but keep it provisioned and recoverable. PVE has no
     * "suspend account" concept, and a stopped VM is what stops the customer
     * using it without destroying anything.
     */
    public function suspendAccount(string $node, int $vmid): void
    {
        $this->stopVm($node, $vmid);
    }

    /**
     * Destroy a VM.
     *
     * Without `purge` PVE keeps the disks and config as leftovers, so the next
     * terminate on the same VMID would fail; the unused-disk sweep is what makes
     * the id reusable. Both steps are tolerated as best-effort because a stale
     * destroy must not block terminating the service record.
     */
    public function destroyVm(string $node, int $vmid, bool $purge = true): void
    {
        // PVE refuses to destroy a running VM ("VM is running - destroy
        // failed"), so a destroy must stop it first — a terminate on a live VM
        // would otherwise fail and leave the record inconsistent.
        if ($this->vmStatus($node, $vmid) === 'running') {
            $this->stopVm($node, $vmid);
        }

        $upid = $this->call('DELETE', sprintf(
            '/nodes/%s/qemu/%d',
            rawurlencode($node),
            $vmid,
        ), $purge ? ['purge' => 1, 'destroy-unreferenced-disks' => 1] : []);

        $this->waitForTask($node, is_string($upid) ? $upid : '', 180);
    }

    private function simpleTask(string $node, int $vmid, string $action): void
    {
        $upid = $this->call('POST', sprintf(
            '/nodes/%s/qemu/%d/status/%s',
            rawurlencode($node),
            $vmid,
            $action,
        ));

        $this->waitForTask($node, is_string($upid) ? $upid : '');
    }

    /**
     * @return array{exists: bool, status: string}
     */
    public function vmExists(string $node, int $vmid): array
    {
        try {
            $data = $this->call('GET', sprintf(
                '/nodes/%s/qemu/%d/status/current',
                rawurlencode($node),
                $vmid,
            ));
        } catch (PanelException $e) {
            // PVE answers HTTP 500 "Configuration file does not exist" for a VM
            // that is not there. Asking whether a VM exists must not require the
            // caller to catch — absence is an answer, not an error. Any other
            // failure (transport, auth, permission) stays an error so it can
            // never be mistaken for a deleted VM.
            if ($this->isMissingVm($e->getMessage())) {
                return ['exists' => false, 'status' => ''];
            }

            throw $e;
        }

        if (! is_array($data)) {
            return ['exists' => false, 'status' => ''];
        }

        return [
            'exists' => true,
            'status' => strtolower(trim((string) ($data['status'] ?? ''))),
        ];
    }

    /**
     * Does this PVE error mean "no such VM"?
     *
     * Deliberately keyed on the message, NOT on a bare HTTP 500. PVE answers 500
     * for a missing VM (`Configuration file ... does not exist`) but also for
     * unrelated internal failures — and an offline node answers **595**, not 500.
     *
     * Treating any 500 as "the VM is gone" would be the most dangerous possible
     * bug here: a transient PVE fault would flip a live record to terminated and
     * rebuild a duplicate VM. Unknown failures must fail closed instead.
     */
    public function isMissingVm(string $message): bool
    {
        foreach (['does not exist', 'no such', 'Configuration file'] as $needle) {
            if (stripos($message, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    public function vmStatus(string $node, int $vmid): string
    {
        return $this->vmExists($node, $vmid)['status'];
    }

    /**
     * Block until a UPID leaves the running state.
     *
     * PVE mutating calls return as soon as the task is queued, so without this
     * a clone would be recorded before the VM exists. Bounded rather than
     * infinite: on timeout the operation is reported as incomplete instead of
     * hanging a queue worker.
     *
     * @throws PanelException
     */
    public function waitForTask(string $node, string $upid, int $timeoutSeconds = 300, string $timeoutHint = ''): void
    {
        if (trim($upid) === '') {
            return;
        }

        // A graceful shutdown runs for up to 90s and a destroy for 180s, but
        // web SAPIs default to max_execution_time = 30s: without headroom PHP
        // kills the request mid-wait, the action's provisioning event is left
        // `running`, and the compute card locks for RUNNING_STALE_AFTER_SECONDS.
        // The wait is bounded by $timeoutSeconds below, so lifting the script
        // limit cannot hang the request. CLI (queue worker) runs unlimited.
        @set_time_limit(0);

        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            $data = $this->call('GET', sprintf(
                '/nodes/%s/tasks/%s/status',
                rawurlencode($node),
                rawurlencode($upid),
            ));

            $status = is_array($data) ? strtolower(trim((string) ($data['status'] ?? ''))) : '';

            if ($status === 'stopped') {
                $exit = is_array($data) ? trim((string) ($data['exitstatus'] ?? '')) : '';
                if ($exit === '' || strtoupper($exit) === 'OK') {
                    return;
                }

                throw new PanelException(sprintf('Proxmox VE task failed: %s', $exit));
            }

            if (microtime(true) >= $deadline) {
                throw new PanelException(sprintf(
                    'Proxmox VE task %s did not finish within %d seconds%s.',
                    $upid,
                    $timeoutSeconds,
                    $timeoutHint !== '' ? ' — '.$timeoutHint : '',
                ));
            }

            usleep(500_000);
        }
    }

    // ──────────────────────────── host information ────────────────────────────

    public function testConnection(): ServerConnectionResult
    {
        $start = microtime(true);

        try {
            $version = $this->version();
            $nodes = $this->nodes();
            $latency = (int) round((microtime(true) - $start) * 1000);

            // Probe the reachable node, not merely the first one listed: PVE
            // returns nodes in an arbitrary order, and probing an OFFLINE node
            // makes storage look invisible when it is not.
            $node = '';

            try {
                $node = $this->defaultNode();
            } catch (PanelException) {
                $node = $nodes[0] ?? '';
            }

            // Reachability is not readiness. A PVE API token is created with
            // "privilege separation" ON and no ACL by default: it authenticates
            // and answers /version and /nodes fine, but every VM and datastore
            // call comes back empty. Reporting that as "connected" is a false
            // green — provisioning then fails much later, on the first clone.
            $privileges = $this->effectivePrivileges();

            if ($privileges === []) {
                return ServerConnectionResult::fail(
                    sprintf(
                        'Authenticated to Proxmox VE %s but this credential has no effective privileges '
                        .'(/access/permissions is empty), so it cannot list or manage VMs. '
                        .'For an API token, either re-create it with "Privilege Separation" unchecked, or grant the '
                        .'token an ACL (Datacenter → Permissions → Add → API Token) with a role such as PVEVMAdmin.',
                        $version,
                    ),
                    $latency,
                );
            }

            $vms = $this->call('GET', '/cluster/resources', ['type' => 'vm']);
            $vmCount = is_array($vms) ? count($vms) : 0;

            $datastores = 0;
            if ($node !== '') {
                try {
                    $storage = $this->call('GET', sprintf('/nodes/%s/storage', rawurlencode($node)));
                    $datastores = is_array($storage) ? count($storage) : 0;
                } catch (PanelException) {
                    // Storage visibility is reported, not fatal — see below.
                }
            }

            $warnings = [];
            if ($datastores === 0) {
                $warnings[] = 'no datastores are visible to this credential, so creating disks will fail';
            }

            return ServerConnectionResult::ok(
                sprintf(
                    'Connected to Proxmox VE %s (%d node%s, %d VM%s visible).%s',
                    $version,
                    count($nodes),
                    count($nodes) === 1 ? '' : 's',
                    $vmCount,
                    $vmCount === 1 ? '' : 's',
                    $warnings === [] ? '' : ' Warning: '.implode('; ', $warnings).'.',
                ),
                $latency,
                AbstractPanelModule::capMeta([
                    'version' => $version,
                    'node_count' => count($nodes),
                    'node' => $node,
                    'auth_type' => $this->authType(),
                    'port' => $this->port(),
                    'verify_tls' => $this->verifyTls(),
                    'privilege_count' => count($privileges),
                    'vms_visible' => $vmCount,
                    'datastores_visible' => $datastores,
                ]),
            );
        } catch (PanelException $e) {
            return ServerConnectionResult::fail(
                $e->getMessage(),
                (int) round((microtime(true) - $start) * 1000),
            );
        } catch (Throwable $e) {
            return ServerConnectionResult::fail(
                sprintf('Proxmox VE test failed: %s', $e->getMessage()),
                (int) round((microtime(true) - $start) * 1000),
            );
        }
    }

    /**
     * Effective privileges for the current credential.
     *
     * Empty means the credential can authenticate but do nothing — the shape a
     * freshly created API token has before it is given an ACL.
     *
     * @return array<string, mixed>
     */
    public function effectivePrivileges(): array
    {
        $data = $this->call('GET', '/access/permissions');

        return is_array($data) ? $data : [];
    }

    /** @throws PanelException */
    public function version(): string
    {
        $data = $this->call('GET', '/version');

        if (! is_array($data)) {
            return '';
        }

        $version = trim((string) ($data['version'] ?? ''));
        $release = trim((string) ($data['release'] ?? ''));

        return $release !== '' ? $release.'-'.$version : $version;
    }

    public function getServerInfo(): ServerInfoDTO
    {
        $start = microtime(true);
        $fallbackHost = $this->hostLabel();

        try {
            $version = $this->version();
            $nodes = $this->nodes();

            $running = 0;
            $total = 0;
            $vms = $this->call('GET', '/cluster/resources', ['type' => 'vm']);
            if (is_array($vms)) {
                foreach ($vms as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    if (strtolower(trim((string) ($row['type'] ?? ''))) !== 'qemu') {
                        continue;
                    }
                    $total++;
                    if (strtolower(trim((string) ($row['status'] ?? ''))) === 'running') {
                        $running++;
                    }
                }
            }

            return new ServerInfoDTO(
                hostname: $this->clusterName() ?: $fallbackHost,
                version: $version,
                ipAddress: (string) $this->server->ip_address,
                totalAccounts: $total,
                latencyMs: (int) round((microtime(true) - $start) * 1000),
                meta: AbstractPanelModule::successProvenance('/version + /cluster/resources', $version, $fallbackHost) + [
                    'node_count' => count($nodes),
                    'node' => $nodes[0] ?? '',
                    'vms_running' => $running,
                    'vms_total' => $total,
                ],
            );
        } catch (PanelException $e) {
            return new ServerInfoDTO(
                hostname: $fallbackHost,
                ipAddress: (string) $this->server->ip_address,
                latencyMs: (int) round((microtime(true) - $start) * 1000),
                meta: AbstractPanelModule::errorMeta($e->getMessage()),
            );
        } catch (Throwable $e) {
            return new ServerInfoDTO(
                hostname: $fallbackHost,
                ipAddress: (string) $this->server->ip_address,
                latencyMs: (int) round((microtime(true) - $start) * 1000),
                meta: AbstractPanelModule::errorMeta(sprintf('Proxmox VE query failed: %s', $e->getMessage())),
            );
        }
    }

    public function cachedServerInfo(int $ttlSeconds = 60): ServerInfoDTO
    {
        $key = sprintf('proxmox:server:%d:info', $this->server->id);

        // Array shape only — a cached ServerInfoDTO object comes back as
        // __PHP_Incomplete_Class (cache.serializable_classes = false).
        $cached = cache()->get($key);

        if (is_array($cached) && $cached !== []) {
            return ServerInfoDTO::fromArray($cached);
        }

        $dto = $this->getServerInfo();

        cache()->put($key, $dto->toArray(), $ttlSeconds);

        return $dto;
    }

    private function clusterName(): string
    {
        try {
            $data = $this->call('GET', '/cluster/status');

            if (is_array($data)) {
                foreach ($data as $row) {
                    if (is_array($row) && strtolower(trim((string) ($row['type'] ?? ''))) === 'cluster') {
                        $name = trim((string) ($row['name'] ?? ''));
                        if ($name !== '') {
                            return $name;
                        }
                    }
                }
            }
        } catch (Throwable) {
            // A cluster name is cosmetic; never fail the info fetch over it.
        }

        return '';
    }

    /**
     * Live per-VM inventory for the admin server-show VM table.
     *
     * Contract matches HyperVClient::listVms() — `vmId` must carry the same
     * value that was written to `panel_accounts.external_id` (the VMID), because
     * ServerVmInventoryPresenter correlates on it. Graceful: any failure yields
     * [] rather than breaking the page.
     *
     * @return list<array<string, mixed>>
     */
    public function listVms(): array
    {
        try {
            $data = $this->call('GET', '/cluster/resources', ['type' => 'vm']);
        } catch (Throwable) {
            return [];
        }

        if (! is_array($data)) {
            return [];
        }

        $vms = [];

        foreach ($data as $row) {
            if (! is_array($row) || strtolower(trim((string) ($row['type'] ?? ''))) !== 'qemu') {
                continue;
            }

            $vmid = (int) ($row['vmid'] ?? 0);
            if ($vmid <= 0) {
                continue;
            }

            $state = strtolower(trim((string) ($row['status'] ?? '')));
            $memory = (int) ($row['maxmem'] ?? 0);
            $uptime = (int) ($row['uptime'] ?? 0);

            $vms[] = [
                'name' => trim((string) ($row['name'] ?? '')),
                'state' => $state,
                'uptime' => $uptime > 0 ? $this->formatUptime($uptime) : '',
                // PVE reports 0..1 normalised to the VM's own vCPU count, so the
                // presenter's percentage mapping is correct as-is.
                'cpuUsage' => (int) round(((float) ($row['cpu'] ?? 0)) * 100),
                'memoryAssigned' => $memory,
                // `mem` is the bytes currently in use — report it directly;
                // `maxmem` may legitimately be 0 in a partially populated row.
                'memoryDemand' => (int) ($row['mem'] ?? 0),
                'processorCount' => (int) ($row['maxcpu'] ?? 0),
                'version' => '',
                'vmId' => (string) $vmid,
                'switchName' => '',
                'vhdPath' => '',
                'node' => trim((string) ($row['node'] ?? '')),
                'template' => (bool) ($row['template'] ?? false),
                'diskGb' => (int) round(((int) ($row['maxdisk'] ?? 0)) / 1073741824),
            ];
        }

        return $vms;
    }

    private function formatUptime(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);

        if ($days > 0) {
            return sprintf('%dd %dh', $days, $hours);
        }

        $minutes = intdiv($seconds % 3600, 60);

        return $hours > 0 ? sprintf('%dh %dm', $hours, $minutes) : sprintf('%dm', $minutes);
    }
}
