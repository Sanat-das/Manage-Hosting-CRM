<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\Modules\ModuleManager;
use App\ViewModels\Admin\ServerDetailViewModel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin server management (Session 3A.2).
 *
 * Reference note: the task brief lists "name, hostname, ip" — the local
 * servers table has NO hostname column (config_tables migration), so the
 * `name` field is the server's display label. Columns: name, ip_address,
 * panel_type, api_url, api_key, api_username, max_accounts, status.
 *
 * Permission gates: hosting.view (read), hosting.manage (write).
 */
class ServerController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $status = $request->query('status');

        $servers = Server::query()
            ->withCount('hostingAccounts')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%")
                        ->orWhere('api_url', 'like', "%{$search}%");
                });
            })
            ->when(in_array($status, ['active', 'inactive'], true), function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->gridSort([
                'name' => 'name',
                'ip_address' => 'ip_address',
                'panel' => 'panel_type',
                'status' => 'status',
                'created_at' => 'created_at',
            ])
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.servers.index', compact('servers', 'search', 'status'));
    }

    public function show(Server $server, ModuleManager $modules): View
    {
        $groups = $server->groupMembers()->with('group:id,name,status,allowed_server_type')->get();

        // ── Fresh resolve per GET (todo 7) — bounded, graceful degrade, idempotent persist ──
        $freshFailed = false;
        $freshDto = null;
        $serverType = (string) ($server->server_type ?? $server->panel_type ?? '');
        $isProxmox = $serverType === 'proxmox';

        if (! $isProxmox) {
            if ($serverType === 'hyperv') {
                // Hyper-V via cachedServerInfo(60) with WinRM invoke bounded at 8s
                try {
                    $start = microtime(true);
                    // Enforce 8s WinRM bound via client timeout; cachedServerInfo(60) is single cache home (60s TTL)
                    $client = new \Modules\HyperV\Services\HyperVClient($server, 8);
                    $dto = $client->cachedServerInfo(60);
                    $elapsed = microtime(true) - $start;
                    // Bounded: treat >8s as failure even if client returned
                    if ($elapsed > 8) {
                        throw new \RuntimeException('Hyper-V fetch exceeded 8s bound');
                    }
                    // Detect error DTO (Proxmox stub and Hyper-V failure both surface via meta.error)
                    $hasError = is_array($dto->meta) && isset($dto->meta['error']) && trim((string) $dto->meta['error']) !== '';
                    if ($hasError) {
                        $freshFailed = true;
                    } else {
                        $freshDto = $dto;
                    }
                } catch (\Throwable) {
                    $freshFailed = true;
                }
            } elseif (in_array($serverType, ['cpanel', 'plesk', 'directadmin', 'virtualizor'], true)) {
                // Panels via getServerInfo bounded at 5s for HTTP drivers, degrade to persisted on failure/timeout
                try {
                    $driver = $modules->resolveForServer($server);
                    if ($driver !== null && method_exists($driver, 'getServerInfo')) {
                        $start = microtime(true);
                        $dto = $driver->getServerInfo($server);
                        $elapsed = microtime(true) - $start;
                        if ($elapsed > 5) {
                            throw new \RuntimeException('Panel fetch exceeded 5s bound');
                        }
                        $hasError = is_array($dto->meta) && isset($dto->meta['error']) && trim((string) $dto->meta['error']) !== '';
                        if ($hasError) {
                            $freshFailed = true;
                        } else {
                            $freshDto = $dto;
                        }
                    }
                } catch (\Throwable) {
                    $freshFailed = true;
                }
            }
        } else {
            // Proxmox stub → empty state, never exception
            $freshDto = null;
            $freshFailed = false;
        }

        // Persist rule (idempotent GET): on success deep-merge ONLY allow-listed transport keys plus provenance checked_at
        $renderMeta = null;
        if ($freshDto !== null && ! $freshFailed) {
            $existingMeta = is_array($server->connection_meta) ? $server->connection_meta : [];

            // Extract allow-listed transport from current server state (not from telemetry)
            $transport = $this->freshTransportForPersist($server);

            // Build render meta: overlay fresh telemetry for in-memory rendering (never persisted)
            $freshArray = $freshDto->toArray();
            $renderMeta = $existingMeta;
            // Top-level fresh keys for ViewModel hostname/version etc.
            foreach (['hostname', 'version', 'ipAddress', 'totalAccounts', 'latencyMs'] as $k) {
                if (array_key_exists($k, $freshArray) && $freshArray[$k] !== '' && $freshArray[$k] !== null) {
                    $renderMeta[$k] = $freshArray[$k];
                }
            }
            // Nested meta (telemetry) for rendering only — deep-merge fresh meta into render copy
            if (isset($freshArray['meta']) && is_array($freshArray['meta'])) {
                $renderMeta['meta'] = array_merge(is_array($existingMeta['meta'] ?? null) ? $existingMeta['meta'] : [], $freshArray['meta']);
            }
            // Hostname source of truth is the DTO top level (dtoFromDecoded keeps it out of nested
            // meta); nested wins in the ViewModel, so stamp it at both levels of the render copy.
            // In-memory only — never persisted on GET.
            if (isset($freshArray['hostname']) && trim((string) $freshArray['hostname']) !== '') {
                $renderMeta['hostname'] = $freshArray['hostname'];
                if (! is_array($renderMeta['meta'] ?? null)) {
                    $renderMeta['meta'] = [];
                }
                $renderMeta['meta']['hostname'] = $freshArray['hostname'];
            }

            // Prepare persist meta: deep-merge ONLY allow-listed transport keys + provenance checked_at
            $persistMeta = $existingMeta;
            foreach (['host', 'port', 'use_ssl', 'verify_tls'] as $k) {
                if (array_key_exists($k, $transport) && $transport[$k] !== null && $transport[$k] !== '') {
                    $persistMeta[$k] = $transport[$k];
                }
            }
            $checkedAt = now()->toIso8601String();
            $existingProv = is_array($persistMeta['provenance'] ?? null) ? $persistMeta['provenance'] : [];
            $persistMeta['provenance'] = array_merge($existingProv, ['checked_at' => $checkedAt]);
            // Also keep top-level checked_at for Hyper-V age checks that read meta.checked_at directly
            $persistMeta['checked_at'] = $checkedAt;

            try {
                // Never write telemetry/derived on GET — $persistMeta contains only transport + provenance
                $server->update(['connection_meta' => $persistMeta]);
                $server->refresh();
                // Re-apply render telemetry onto refreshed server instance for ViewModel
                $refreshedMeta = is_array($server->connection_meta) ? $server->connection_meta : [];
                foreach (['hostname', 'version', 'ipAddress', 'totalAccounts', 'latencyMs'] as $k) {
                    if (array_key_exists($k, $freshArray) && $freshArray[$k] !== '' && $freshArray[$k] !== null) {
                        $refreshedMeta[$k] = $freshArray[$k];
                    }
                }
                if (isset($freshArray['meta']) && is_array($freshArray['meta'])) {
                    $refreshedMeta['meta'] = array_merge(is_array($refreshedMeta['meta'] ?? null) ? $refreshedMeta['meta'] : [], $freshArray['meta']);
                }
                if (isset($freshArray['hostname']) && trim((string) $freshArray['hostname']) !== '') {
                    $refreshedMeta['hostname'] = $freshArray['hostname'];
                    if (! is_array($refreshedMeta['meta'] ?? null)) {
                        $refreshedMeta['meta'] = [];
                    }
                    $refreshedMeta['meta']['hostname'] = $freshArray['hostname'];
                }
                $renderMeta = $refreshedMeta;
                $server->setAttribute('connection_meta', $renderMeta);
            } catch (\Throwable) {
                // Persist failure must not break page — keep in-memory render meta
                $server->setAttribute('connection_meta', $renderMeta);
            }
        }

        // On failure/timeout write nothing — graceful degrade to persisted connection_meta
        // isStale := last fetch failed OR checked_at older than 60s (Hyper-V) / 15min since servers.updated_at (panels)
        $vm = ServerDetailViewModel::fromServer($server);
        $ageStale = (bool) $vm->isStale;
        $provenanceStale = false;
        if ($serverType === 'hyperv') {
            $meta = is_array($server->connection_meta) ? $server->connection_meta : [];
            $provenance = is_array($meta['provenance'] ?? null) ? $meta['provenance'] : [];
            $checkedAtRaw = $provenance['checked_at'] ?? $meta['checked_at'] ?? null;
            if ($checkedAtRaw !== null && $checkedAtRaw !== '') {
                try {
                    $checkedAt = \Carbon\Carbon::parse($checkedAtRaw);
                    $provenanceStale = $checkedAt->diffInSeconds(now()) > 60;
                } catch (\Throwable) {
                    $provenanceStale = false;
                }
            } else {
                // No provenance yet — fall back to ViewModel age (last_checked_at >60s)
                $provenanceStale = $ageStale;
            }
            $isStaleComputed = $freshFailed || $provenanceStale || $ageStale;
        } else {
            // Panels: stale if updated_at older than 15min or fetch failed
            $ts = $server->updated_at ?? $server->last_checked_at;
            $panelAgeStale = $ts ? $ts->diffInMinutes(now()) > 15 : false;
            $isStaleComputed = $freshFailed || $panelAgeStale || $ageStale;
            // Proxmox stub always empty but never throws — stale follows panel 15min rule
        }

        // Wire isStale to ViewModel (todo 1 prop, consume-only: no parser edits).
        // readonly DTO cannot be mutated, so rebuild via named-arg unpack with overridden isStale.
        if ($isStaleComputed !== $vm->isStale) {
            $vmVars = get_object_vars($vm);
            $vmVars['isStale'] = $isStaleComputed;
            $vm = new ServerDetailViewModel(...$vmVars);
        }

        return view('admin.servers.show', compact('server', 'groups', 'vm'));
    }

    /**
     * Extract allow-listed transport keys [host, port, use_ssl, verify_tls] for persist.
     * Deep-merge ONLY these plus provenance checked_at — never telemetry/derived.
     *
     * @return array{host?: string, port?: int, use_ssl?: bool, verify_tls?: mixed}
     */
    private function freshTransportForPersist(Server $server): array
    {
        $meta = is_array($server->connection_meta) ? $server->connection_meta : [];
        $out = [];

        // host: prefer meta host else parsed api_url host else ip_address
        $host = null;
        if (isset($meta['host']) && is_string($meta['host']) && trim($meta['host']) !== '') {
            $host = trim($meta['host']);
        } elseif (isset($meta['Host']) && is_string($meta['Host']) && trim($meta['Host']) !== '') {
            $host = trim($meta['Host']);
        } else {
            $apiHost = null;
            if (is_string($server->api_url) && $server->api_url !== '' && str_contains($server->api_url, '://')) {
                $apiHost = parse_url($server->api_url, PHP_URL_HOST);
            }
            $host = is_string($apiHost) && $apiHost !== '' ? $apiHost : (is_string($server->ip_address) && trim($server->ip_address) !== '' ? trim($server->ip_address) : null);
        }
        if ($host !== null) {
            $out['host'] = $host;
        }

        // port: meta port else api_url port else null (ViewModel will default)
        $port = null;
        if (array_key_exists('port', $meta) && $meta['port'] !== '' && $meta['port'] !== null) {
            $port = (int) $meta['port'];
        } elseif (array_key_exists('Port', $meta) && $meta['Port'] !== '' && $meta['Port'] !== null) {
            $port = (int) $meta['Port'];
        } elseif (is_string($server->api_url) && str_contains($server->api_url, '://')) {
            $parsed = parse_url($server->api_url, PHP_URL_PORT);
            if (is_numeric($parsed)) {
                $port = (int) $parsed;
            }
        }
        if ($port !== null) {
            $out['port'] = $port;
        }

        // use_ssl: meta snake/camel else scheme else null
        $useSsl = null;
        if (array_key_exists('use_ssl', $meta) && $meta['use_ssl'] !== '' && $meta['use_ssl'] !== null) {
            $useSsl = filter_var($meta['use_ssl'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $meta['use_ssl'];
        } elseif (array_key_exists('useSsl', $meta) && $meta['useSsl'] !== '' && $meta['useSsl'] !== null) {
            $useSsl = filter_var($meta['useSsl'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $meta['useSsl'];
        } elseif (is_string($server->api_url) && $server->api_url !== '') {
            if (str_contains($server->api_url, 'https://')) {
                $useSsl = true;
            } elseif (str_contains($server->api_url, 'http://')) {
                $useSsl = false;
            }
        }
        if ($useSsl !== null) {
            $out['use_ssl'] = $useSsl;
        }

        // verify_tls: meta snake/camel else true default only if already present — don't invent
        if (array_key_exists('verify_tls', $meta)) {
            $out['verify_tls'] = (bool) $meta['verify_tls'];
        } elseif (array_key_exists('verifyTls', $meta)) {
            $out['verify_tls'] = (bool) $meta['verifyTls'];
        }

        return $out;
    }

    public function create(): View
    {
        return view('admin.servers.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->rules());

        $server = Server::create($validated);

        return redirect()
            ->route('admin.servers.show', $server)
            ->with('success', "Server {$server->name} created.");
    }

    public function edit(Server $server): View
    {
        return view('admin.servers.edit', compact('server'));
    }

    public function update(Request $request, Server $server): RedirectResponse
    {
        $validated = $request->validate($this->rules());

        $server->update($validated);

        return redirect()
            ->route('admin.servers.show', $server)
            ->with('success', "Server {$server->name} updated.");
    }

    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'ip_address' => ['required', 'ip'],
            'panel_type' => ['required', Rule::in(['cpanel', 'plesk', 'directadmin', 'custom'])],
            'api_url' => ['nullable', 'url', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:255'],
            'api_username' => ['nullable', 'string', 'max:255'],
            'max_accounts' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }
}
