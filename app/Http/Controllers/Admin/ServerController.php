<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Integrations\ServerConnectionResult;
use App\Http\Controllers\Controller;
use App\Models\PanelAccount;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\ServerGroupMember;
use App\Services\Integrations\IntegrationRegistry;
use App\Services\Modules\ModuleManager;
use App\ViewModels\Admin\ServerDetailViewModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ServerController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request, IntegrationRegistry $registry): View
    {
        $search = trim((string) $request->query('search'));
        $status = $request->query('status');
        $serverType = trim((string) $request->query('server_type'));
        $connectionStatus = trim((string) $request->query('connection_status'));

        $activeSlugs = $this->activeSlugs($registry);

        $servers = Server::query()
            ->withCount('hostingAccounts')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%")
                        ->orWhere('api_url', 'like', "%{$search}%")
                        ->orWhere('server_type', 'like', "%{$search}%");
                });
            })
            ->when(in_array($status, ['active', 'inactive'], true), function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->when($serverType !== '' && in_array($serverType, $activeSlugs, true), function ($query) use ($serverType) {
                $query->where('server_type', $serverType);
            })
            ->when(in_array($connectionStatus, ['connected', 'failed', 'untested'], true), function ($query) use ($connectionStatus) {
                $query->where('connection_status', $connectionStatus);
            })
            ->gridSort([
                'name' => 'name',
                'ip_address' => 'ip_address',
                'panel' => 'server_type',
                'server_type' => 'server_type',
                'connection_status' => 'connection_status',
                'status' => 'status',
                'created_at' => 'created_at',
            ])
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $serverTypeOptions = $registry->serverTypeOptions();

        return view('admin.servers.index', [
            'servers' => $servers,
            'search' => $search,
            'status' => $status,
            'serverType' => $serverType,
            'serverTypeOptions' => $serverTypeOptions,
            'typeOptions' => $serverTypeOptions,
            'connectionStatus' => $connectionStatus,
        ]);
    }

    public function show(Server $server, IntegrationRegistry $registry): View
    {
        $perPage = 10;

        $hostingAccounts = $server->hostingAccounts()->with(['customer.user:id,email,first_name,last_name', 'product:id,name', 'order:id,order_number,status'])->orderByDesc('id')->paginate($perPage, ['*'], 'hosting_page');
        $server->setRelation('hostingAccounts', $hostingAccounts);

        // For virtualization servers (hyperv/proxmox/virtualizor) show VMs via PanelAccount/ServiceInstance
        $panelAccounts = \App\Models\PanelAccount::where('server_id', $server->id)
            ->with(['serviceInstance.order:id,order_number,status', 'serviceInstance.customer.user:id,email,first_name,last_name'])
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'vms_page');

        $groups = $server->groupMembers()->with('group:id,name,status,allowed_server_type')->get();

        // Live Hyper-V host inventory (guarded: hyperv + connected only, 60s cache, never breaks page).
        $liveVms = null;
        if (($server->server_type ?? $server->panel_type) === 'hyperv' && ($server->connection_status ?? '') === 'connected') {
            try {
                $driver = $registry->resolveForServer($server);
                if ($driver !== null && method_exists($driver, 'listVms')) {
                    $liveVms = \Illuminate\Support\Facades\Cache::remember(
                        "hyperv:server:{$server->id}:vms",
                        60,
                        fn () => $driver->listVms($server)
                    );
                }
            } catch (\Throwable) {
                $liveVms = null;
            }
        }

        // Correlate provisioned PanelAccount rows with the live host inventory
        // for the merged VM table (pure presenter — no Blade owned here).
        $vmInventory = \App\ViewModels\Admin\ServerVmInventoryPresenter::build($panelAccounts->items(), $liveVms);

        // ── Fresh resolve per GET (todo 7) — bounded, graceful degrade, idempotent persist ──
        $freshFailed = false;
        $freshDto = null;
        $freshError = null;
        $serverType = (string) ($server->server_type ?? $server->panel_type ?? '');
        $isProxmox = $serverType === 'proxmox';

        if (! $isProxmox) {
            if ($serverType === 'hyperv') {
                // Hyper-V via cachedServerInfo(60) with WinRM invoke bounded at 8s
                try {
                    $start = microtime(true);
                    // Enforce 8s WinRM bound via client timeout; cachedServerInfo(60) is single cache home (60s TTL)
                    $client = new \App\Modules\HyperV\Services\HyperVClient($server, 8);
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
                        $errorMsg = trim((string) $dto->meta['error']);
                        $freshError = $errorMsg !== '' ? \Illuminate\Support\Str::limit($errorMsg, 200) : 'Live fetch failed.';
                    } else {
                        $freshDto = $dto;
                    }
                } catch (\Throwable $e) {
                    $freshFailed = true;
                    $errorMsg = trim((string) $e->getMessage());
                    $freshError = $errorMsg !== '' ? \Illuminate\Support\Str::limit($errorMsg, 200) : 'Live fetch failed.';
                }
            } elseif (in_array($serverType, ['cpanel', 'plesk', 'directadmin', 'virtualizor'], true)) {
                // Panels via getServerInfo bounded at 5s for HTTP drivers, degrade to persisted on failure/timeout
                try {
                    $driver = $registry->resolveForServer($server);
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
                            $errorMsg = trim((string) $dto->meta['error']);
                            $freshError = $errorMsg !== '' ? \Illuminate\Support\Str::limit($errorMsg, 200) : 'Live fetch failed.';
                        } else {
                            $freshDto = $dto;
                        }
                    }
                } catch (\Throwable $e) {
                    $freshFailed = true;
                    $errorMsg = trim((string) $e->getMessage());
                    $freshError = $errorMsg !== '' ? \Illuminate\Support\Str::limit($errorMsg, 200) : 'Live fetch failed.';
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

        // ── SNMP-latest read-only bridge (todo 9) — cached reads only, never live dial ──
        $vm = $this->applySnmpLatestBridge($server, $vm);

        // Totals for header badges (paginators only hold one page — counts need separate queries).
        $panelAccountsTotal = \App\Models\PanelAccount::where('server_id', $server->id)->count();
        $panelAccountsActive = \App\Models\PanelAccount::where('server_id', $server->id)->where('status', 'active')->count();

        // Census counts (todo 11): explicit meanings for the drift card.
        // Provisioned VMs = panelAccounts + service_instances rows on this server.
        // Scheduler load mirrors ServerAllocator::load(): hosting_accounts rows +
        // live service_instances (pending/provisioning/active/suspended).
        // Cap renders from $server->max_accounts directly (0 = Unlimited).
        $serviceInstancesTotal = \App\Models\ServiceInstance::where('server_id', $server->id)->count();
        $hostingAccountsTotal = $server->hostingAccounts()->count();
        $provisionedTotal = $panelAccountsTotal + $serviceInstancesTotal;
        $schedulerLoad = $hostingAccountsTotal + \App\Models\ServiceInstance::where('server_id', $server->id)
            ->whereIn('status', ['pending', 'provisioning', 'active', 'suspended'])
            ->count();

        // ── Aggregates (todo 10): pools-minus-allocations + usage/quotas, read-only ──
        // Entitlement vs metered sources stay labeled, never merged; any failure
        // degrades to empty rows so the page (and todos 7/9/11) never breaks.
        $poolAvailability = [];
        $consumptionRows = [];
        try {
            $poolAvailability = $this->poolAvailabilityForServer((int) $server->id);
            $consumptionRows = $this->consumptionForServer($server);
        } catch (\Throwable) {
            $poolAvailability = [];
            $consumptionRows = [];
        }

        return view('admin.servers.show', compact('server', 'groups', 'hostingAccounts', 'panelAccounts', 'liveVms', 'vm', 'vmInventory', 'panelAccountsTotal', 'panelAccountsActive', 'serviceInstancesTotal', 'hostingAccountsTotal', 'provisionedTotal', 'schedulerLoad', 'poolAvailability', 'consumptionRows', 'freshError'));
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

    /**
     * SNMP-latest read-only bridge (todo 9, LIGHT tier).
     *
     * Resolves the SNMP target as the server's hostingAccount whose cached
     * snmp_latest row carries the latest non-null collected_at (tie-break:
     * lowest snmp_targets.id), then fills ONLY the todo-1 $vm->snmp* props
     * from that payload plus derived gauges. Pure cached DB reads — one
     * targets query, one snmp_latest IN query, one gauges query — never a
     * live SNMP dial from the web request. Any failure (no targets, no
     * rows, missing monitoring connection) leaves the props null so the
     * cards render the frozen empty state.
     */
    private function applySnmpLatestBridge(Server $server, ServerDetailViewModel $vm): ServerDetailViewModel
    {
        try {
            $targets = DB::table('snmp_targets as t')
                ->join('hosting_accounts as ha', 'ha.id', '=', 't.hosting_account_id')
                ->where('ha.server_id', $server->id)
                ->get(['t.id', 't.hosting_account_id']);

            if ($targets->isEmpty()) {
                return $vm;
            }

            $accountByTarget = [];
            foreach ($targets as $target) {
                $accountByTarget[(int) $target->id] = (int) $target->hosting_account_id;
            }

            $rows = DB::connection('monitoring')->table('snmp_latest')
                ->whereIn('host_id', array_keys($accountByTarget))
                ->get(['host_id', 'collected_at', 'payload', 'status']);

            // Latest non-null collected_at wins; exact tie breaks to lowest id.
            $winner = null;
            foreach ($rows as $row) {
                $collectedAt = $row->collected_at ?? null;
                if ($collectedAt === null || trim((string) $collectedAt) === '') {
                    continue;
                }
                $hostId = (int) $row->host_id;
                if ($winner === null
                    || strcmp((string) $collectedAt, (string) $winner->collected_at) > 0
                    || (strcmp((string) $collectedAt, (string) $winner->collected_at) === 0 && $hostId < (int) $winner->host_id)
                ) {
                    $winner = $row;
                }
            }

            if ($winner === null) {
                return $vm;
            }

            $targetId = (int) $winner->host_id;
            $decoded = json_decode((string) ($winner->payload ?? ''), true);
            $payload = is_array($decoded) ? $decoded : [];

            // Derived gauges (computed pct) win; raw payload fields fall back.
            $gauges = [];
            try {
                $gauges = app(\Modules\SnmpMonitor\Services\SnmpMetricRepository::class)->latestSampleMetrics([$targetId]);
            } catch (\Throwable) {
                $gauges = [];
            }
            $gauge = $gauges[$targetId] ?? [];

            $uptime = $payload['uptime_human'] ?? null;
            if (! is_string($uptime) || trim($uptime) === '') {
                $uptime = null;
            }

            $cpu = $gauge['cpu_pct'] ?? null;
            if ($cpu === null && isset($payload['cpu_load']) && is_numeric($payload['cpu_load'])) {
                $cpu = (float) $payload['cpu_load'];
            }

            $mem = $gauge['mem_pct'] ?? null;
            if ($mem === null
                && isset($payload['memory_used_mb'], $payload['memory_total_mb'])
                && is_numeric($payload['memory_total_mb']) && (float) $payload['memory_total_mb'] > 0
                && is_numeric($payload['memory_used_mb'])
            ) {
                $mem = round((float) $payload['memory_used_mb'] / (float) $payload['memory_total_mb'] * 100, 1);
            }

            $disk = $gauge['disk_pct'] ?? null;
            if ($disk === null && isset($payload['disks']) && is_array($payload['disks'])) {
                $disk = $this->snmpDisksPct($payload['disks']);
            }

            $vars = get_object_vars($vm);
            $vars['snmpSource'] = 'snmp:account-'.$accountByTarget[$targetId];
            $vars['snmpCollectedAt'] = (string) $winner->collected_at;
            $vars['snmpUptime'] = $uptime;
            $vars['snmpCpu'] = $cpu;
            $vars['snmpMem'] = $mem;
            $vars['snmpDisks'] = $disk;

            return new ServerDetailViewModel(...$vars);
        } catch (\Throwable) {
            // Read-only bridge must never break the page — degrade to empty SNMP slots.
            return $vm;
        }
    }

    /**
     * Aggregate disk usage % from an snmp_latest disks[] payload, mirroring
     * PollHostBatch::storagePct(). NULL when no capacity is known.
     *
     * @param  list<array<string, mixed>>  $disks
     */
    private function snmpDisksPct(array $disks): ?float
    {
        $total = 0.0;
        $used = 0.0;

        foreach ($disks as $disk) {
            if (! is_array($disk)) {
                continue;
            }
            $total += isset($disk['total_gb']) && is_numeric($disk['total_gb']) ? (float) $disk['total_gb'] : 0.0;
            $used += isset($disk['used_gb']) && is_numeric($disk['used_gb']) ? (float) $disk['used_gb'] : 0.0;
        }

        return $total > 0 ? round($used / $total * 100, 2) : null;
    }

    /**
     * Todo 10 aggregates — entitlement Available, read-only.
     *
     * Available = SUM(resource_pools by server_id) minus SUM(resource_allocations
     * with status IN [allocated, active] only), grouped by pool_type+unit so
     * values in different units are never added together.
     *
     * @return list<array{pool_type:string,unit:string,total:float,allocated:float,available:float,display:string}>
     */
    private function poolAvailabilityForServer(int $serverId): array
    {
        $pools = DB::table('resource_pools')
            ->where('server_id', $serverId)
            ->get(['id', 'pool_type', 'total_capacity', 'unit'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'pool_type' => (string) ($row->pool_type ?? ''),
                'unit' => (string) ($row->unit ?? ''),
                'total_capacity' => $row->total_capacity,
            ])
            ->all();

        $poolIds = array_column($pools, 'id');

        $allocs = $poolIds === []
            ? []
            : DB::table('resource_allocations')
                ->whereIn('pool_id', $poolIds)
                ->whereIn('status', ['allocated', 'active'])
                ->get(['pool_id', 'quantity_allocated', 'status'])
                ->map(fn ($row) => [
                    'pool_id' => (int) $row->pool_id,
                    'quantity_allocated' => $row->quantity_allocated,
                    'status' => (string) ($row->status ?? ''),
                ])
                ->all();

        return self::summarizePoolAvailability($pools, $allocs);
    }

    /**
     * Todo 10 aggregates — Consumption rows, read-only.
     *
     * Metered usage_records (via server → service_instances → usage_records,
     * recent-50 per metric within the current calendar month and open billing
     * window) plus legacy hosting quota sums, each as a separately labeled row.
     *
     * @return list<array{source:string,label:string,metric:string,value:float,unit:string,display:string}>
     */
    private function consumptionForServer(Server $server): array
    {
        $rows = [];

        $serviceIds = DB::table('service_instances')
            ->where('server_id', $server->id)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->all();

        if ($serviceIds !== []) {
            $today = today()->toDateString();
            $monthStart = now()->startOfMonth()->toDateTimeString();
            $metrics = DB::table('usage_records')
                ->whereIn('service_id', $serviceIds)
                ->distinct()
                ->pluck('metric')
                ->all();

            $records = [];
            foreach ($metrics as $metric) {
                $recent = DB::table('usage_records')
                    ->whereIn('service_id', $serviceIds)
                    ->where('metric', $metric)
                    ->where('recorded_at', '>=', $monthStart)
                    ->where(function ($query) use ($today) {
                        $query->whereNull('billing_period_end')
                            ->orWhere('billing_period_end', '>=', $today);
                    })
                    ->orderByDesc('recorded_at')
                    ->limit(50)
                    ->get(['metric', 'value', 'unit'])
                    ->map(fn ($row) => [
                        'metric' => (string) ($row->metric ?? ''),
                        'value' => $row->value,
                        'unit' => (string) ($row->unit ?? ''),
                    ])
                    ->all();
                array_push($records, ...$recent);
            }
            array_push($rows, ...self::summarizeUsageByMetric($records));
        }

        // Legacy caps: hosting quota sums (quotas are stored in MB — see
        // admin.hosting.show) as separate labeled rows, never merged metered.
        $quota = DB::table('hosting_accounts')
            ->where('server_id', $server->id)
            ->selectRaw('COALESCE(SUM(disk_quota),0) as disk_quota, COALESCE(SUM(disk_used),0) as disk_used, COALESCE(SUM(bandwidth_quota),0) as bandwidth_quota, COALESCE(SUM(bandwidth_used),0) as bandwidth_used')
            ->first();

        foreach ([
            'quota:disk_quota' => [(float) ($quota->disk_quota ?? 0), 'MB'],
            'quota:disk_used' => [(float) ($quota->disk_used ?? 0), 'MB'],
            'quota:bandwidth_quota' => [(float) ($quota->bandwidth_quota ?? 0), 'MB'],
            'quota:bandwidth_used' => [(float) ($quota->bandwidth_used ?? 0), 'MB'],
        ] as $label => [$value, $unit]) {
            $rows[] = [
                'source' => 'legacy',
                'label' => $label,
                'metric' => $label,
                'value' => $value,
                'unit' => $unit,
                'display' => self::formatAggregateValue($value, $unit),
            ];
        }

        return $rows;
    }

    /**
     * Pure pool math behind poolAvailabilityForServer (DB-free for QA).
     * Allocations with status outside [allocated, active] are ignored, as are
     * rows pointing at pools that do not belong to this server.
     *
     * @param  list<array{id:int,pool_type:string,unit:mixed,total_capacity:mixed}>  $pools
     * @param  list<array{pool_id:int,quantity_allocated:mixed,status:string}>  $allocs
     * @return list<array{pool_type:string,unit:string,total:float,allocated:float,available:float,display:string}>
     */
    public static function summarizePoolAvailability(array $pools, array $allocs): array
    {
        $groups = [];
        $poolKeyById = [];

        foreach ($pools as $pool) {
            $poolType = (string) ($pool['pool_type'] ?? '');
            $unit = (string) ($pool['unit'] ?? '');
            $key = strtolower(trim($poolType))."\0".strtolower(trim($unit));
            $poolKeyById[(int) ($pool['id'] ?? 0)] = $key;
            if (! isset($groups[$key])) {
                $groups[$key] = ['pool_type' => $poolType, 'unit' => $unit, 'total' => 0.0, 'allocated' => 0.0];
            }
            $groups[$key]['total'] += is_numeric($pool['total_capacity'] ?? null) ? (float) $pool['total_capacity'] : 0.0;
        }

        foreach ($allocs as $alloc) {
            if (! in_array(strtolower(trim((string) ($alloc['status'] ?? ''))), ['allocated', 'active'], true)) {
                continue;
            }
            $key = $poolKeyById[(int) ($alloc['pool_id'] ?? 0)] ?? null;
            if ($key === null || ! isset($groups[$key])) {
                continue;
            }
            $groups[$key]['allocated'] += is_numeric($alloc['quantity_allocated'] ?? null) ? (float) $alloc['quantity_allocated'] : 0.0;
        }

        $rows = [];
        foreach ($groups as $group) {
            $available = $group['total'] - $group['allocated'];
            $rows[] = [
                'pool_type' => $group['pool_type'],
                'unit' => $group['unit'],
                'total' => $group['total'],
                'allocated' => $group['allocated'],
                'available' => $available,
                'display' => self::formatAggregateValue($available, $group['unit']),
            ];
        }

        usort($rows, fn ($a, $b) => [$a['pool_type'], $a['unit']] <=> [$b['pool_type'], $b['unit']]);

        return $rows;
    }

    /**
     * Pure usage math behind consumptionForServer (DB-free for QA).
     * Sums are grouped by metric+unit — never across units.
     *
     * @param  list<array{metric:string,value:mixed,unit:mixed}>  $records
     * @return list<array{source:string,label:string,metric:string,value:float,unit:string,display:string}>
     */
    public static function summarizeUsageByMetric(array $records): array
    {
        $groups = [];

        foreach ($records as $record) {
            $metric = (string) ($record['metric'] ?? '');
            $unit = (string) ($record['unit'] ?? '');
            $key = $metric."\0".strtolower(trim($unit));
            if (! isset($groups[$key])) {
                $groups[$key] = ['metric' => $metric, 'unit' => $unit, 'value' => 0.0];
            }
            $groups[$key]['value'] += is_numeric($record['value'] ?? null) ? (float) $record['value'] : 0.0;
        }

        $rows = [];
        foreach ($groups as $group) {
            $rows[] = [
                'source' => 'metered',
                'label' => 'usage:'.$group['metric'],
                'metric' => $group['metric'],
                'value' => $group['value'],
                'unit' => $group['unit'],
                'display' => self::formatAggregateValue($group['value'], $group['unit']),
            ];
        }

        usort($rows, fn ($a, $b) => [$a['metric'], $a['unit']] <=> [$b['metric'], $b['unit']]);

        return $rows;
    }

    /**
     * Display one aggregate value. fmtBytes converts ONLY within the
     * byte-dimension (B/KB/MB/GB/TB/PB); every other unit — including unknown
     * ones — renders verbatim as "value unit" and never throws.
     */
    public static function formatAggregateValue(mixed $value, mixed $unit): string
    {
        if (! is_numeric($value)) {
            return is_scalar($value) ? (string) $value : '—';
        }

        $numeric = (float) $value;
        $label = trim((string) ($unit ?? ''));

        $byteFactor = [
            'b' => 1, 'byte' => 1, 'bytes' => 1,
            'kb' => 1024, 'mb' => 1048576, 'gb' => 1073741824,
            'tb' => 1099511627776, 'pb' => 1125899906842624,
        ];
        $factor = $byteFactor[strtolower($label)] ?? null;
        if ($factor !== null) {
            try {
                return \App\ViewModels\Admin\ServerDetailViewModel::fmtBytes($numeric * $factor);
            } catch (\Throwable) {
                // Fall through to verbatim below — display must never throw.
            }
        }

        $number = floor($numeric) == $numeric ? (string) (int) $numeric : (string) round($numeric, 2);

        return $label !== '' ? $number.' '.$label : $number;
    }

    public function create(Request $request, IntegrationRegistry $registry): View
    {
        $type = trim((string) $request->query('type'));

        if ($type === '') {
            return $this->createType($registry);
        }

        $activeSlugs = $this->activeSlugs($registry);

        if (! in_array($type, $activeSlugs, true)) {
            abort(404, "Unknown server type [{$type}].");
        }

        $instance = $registry->instanceFor($type);

        // Plugin fallback: registry->instanceFor only covers builtins
        if ($instance === null) {
            try {
                $module = app(ModuleManager::class)->find($type);
                $instance = $module ? app(ModuleManager::class)->resolve($module) : null;
            } catch (\Throwable) {
                $instance = null;
            }
        }

        $schema = [];
        if ($instance !== null && method_exists($instance, 'serverConfigSchema')) {
            $raw = $instance->serverConfigSchema();
            // Normalize to ['fields' => [...]]
            if (isset($raw['fields']) && is_array($raw['fields'])) {
                $schema = $raw;
            } elseif (is_array($raw) && array_is_list($raw)) {
                $schema = ['fields' => $raw];
            } else {
                $schema = is_array($raw) ? $raw : [];
            }
        }

        $groups = ServerGroup::query()
            ->where(function ($q) use ($type) {
                $q->whereNull('allowed_server_type')
                    ->orWhere('allowed_server_type', '')
                    ->orWhere('allowed_server_type', $type);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'allowed_server_type', 'status']);

        $moduleName = $registry->nameFor($type);

        return view('admin.servers.create', [
            'serverType' => $type,
            'moduleName' => $moduleName,
            'schema' => $schema,
            'groups' => $groups,
        ]);
    }

    public function createType(IntegrationRegistry $registry): View
    {
        $options = $registry->serverTypeOptions();

        // Keep proxmox marked as coming soon (stub) even though builtin now always exists
        foreach ($options as &$opt) {
            if (($opt['slug'] ?? $opt['value'] ?? '') === 'proxmox') {
                $opt['coming_soon'] = true;
                $opt['disabled'] = true;
            }
        }
        unset($opt);

        $grouped = collect($options)->groupBy('group')->all();

        // Provide both variable names for view compatibility
        return view('admin.servers.create-type', [
            'grouped' => $grouped,
            'groupedOptions' => $grouped,
            'options' => $options,
            'typeOptions' => $options,
        ]);
    }

    public function store(Request $request, IntegrationRegistry $registry): RedirectResponse
    {
        $validated = $request->validate($this->rules($registry, $request->input('server_type')));

        $serverType = (string) $validated['server_type'];

        // Server group compatibility
        $groupId = $validated['server_group_id'] ?? null;
        if ($groupId !== null && $groupId !== '') {
            $group = ServerGroup::find($groupId);
            if ($group !== null && $group->allowed_server_type !== null && $group->allowed_server_type !== '' && $group->allowed_server_type !== $serverType) {
                return back()->withInput()->withErrors([
                    'server_group_id' => "Group '{$group->name}' is locked to '{$group->allowed_server_type}' and cannot hold a '{$serverType}' server.",
                ]);
            }
        }

        $attributes = $this->mapValidatedToAttributes($validated, $serverType);
        $attributes['server_type'] = $serverType;
        $attributes['connection_status'] = 'untested';

        // Hyper-V transport prefs live in connection_meta (no columns exist);
        // seed them so the edit form + show badges reflect saved values pre-test.
        if ($serverType === 'hyperv') {
            $transportMeta = $this->hypervConnectionMeta($validated);
            if ($transportMeta !== null) {
                $attributes['connection_meta'] = $transportMeta;
            }
        }

        // Build api_url for hyperv from host+port+use_ssl if not already set
        if ($serverType === 'hyperv') {
            $host = trim((string) ($validated['host'] ?? $validated['ip_address'] ?? $validated['api_url'] ?? ''));
            // host may still be empty if top ip_address exists fallback
            if ($host === '' && isset($validated['ip_address']) && trim((string)$validated['ip_address']) !== '') {
                $host = trim((string) $validated['ip_address']);
            }
            $port = $validated['port'] ?? 5985;
            $useSsl = filter_var($validated['use_ssl'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($host !== '' && ! isset($attributes['api_url'])) {
                $scheme = $useSsl ? 'https' : 'http';
                $attributes['api_url'] = sprintf('%s://%s:%d', $scheme, $host, (int) $port);
            }
            // ensure api_username comes from username alias if present
            if (isset($validated['username']) && trim((string)$validated['username']) !== '' && empty($attributes['api_username'] ?? null)) {
                $attributes['api_username'] = trim((string) $validated['username']);
            }
        }

        $server = null;

        DB::transaction(function () use (&$server, $attributes, $groupId) {
            $server = Server::create($attributes);

            if ($groupId !== null && $groupId !== '') {
                ServerGroupMember::create([
                    'server_group_id' => (int) $groupId,
                    'server_id' => $server->id,
                    'priority' => 0,
                ]);
            }
        });

        return redirect()
            ->route('admin.servers.show', $server)
            ->with('success', "Server {$server->name} created.");
    }

    public function edit(Server $server, IntegrationRegistry $registry): View
    {
        $type = (string) $server->server_type;

        $instance = $registry->instanceFor($type);
        if ($instance === null) {
            try {
                $module = app(ModuleManager::class)->find($type);
                $instance = $module ? app(ModuleManager::class)->resolve($module) : null;
            } catch (\Throwable) {
                $instance = null;
            }
        }

        $schema = [];
        if ($instance !== null && method_exists($instance, 'serverConfigSchema')) {
            $raw = $instance->serverConfigSchema();
            if (isset($raw['fields']) && is_array($raw['fields'])) {
                $schema = $raw;
            } elseif (is_array($raw) && array_is_list($raw)) {
                $schema = ['fields' => $raw];
            } else {
                $schema = is_array($raw) ? $raw : [];
            }
        }

        $groups = ServerGroup::query()
            ->where(function ($q) use ($type) {
                $q->whereNull('allowed_server_type')
                    ->orWhere('allowed_server_type', '')
                    ->orWhere('allowed_server_type', $type);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'allowed_server_type', 'status']);

        $selectedGroupId = $server->groupMembers()->first()?->server_group_id;

        return view('admin.servers.edit', [
            'server' => $server,
            'serverType' => $type,
            'moduleName' => $registry->nameFor($type),
            'schema' => $schema,
            'groups' => $groups,
            'selectedGroupId' => $selectedGroupId,
            'typeLocked' => true,
        ]);
    }

    public function update(Request $request, Server $server, IntegrationRegistry $registry): RedirectResponse
    {
        $oldType = (string) $server->server_type;

        $rules = $this->rules($registry, $request->input('server_type'), $server);

        // The type is immutable; accept the server's own value even when it is
        // no longer in the active registry (legacy 'generic' rows, or a plugin
        // module that is currently disabled).
        $rules['server_type'] = ['required', 'string', Rule::in(array_merge($this->activeSlugs($registry), [$oldType]))];

        // Editing never re-asks for stored credentials: blank keeps the current
        // value (mapValidatedToAttributes skips blanks, update() skips null).
        $rules['api_key'] = ['nullable', 'string', 'max:2000'];
        $rules['api_password'] = ['nullable', 'string', 'max:2000'];
        $rules['api_password_encrypted'] = ['nullable', 'string', 'max:2000'];
        $rules['api_username'] = ['nullable', 'string', 'max:255'];
        $rules['username'] = ['nullable', 'string', 'max:255'];
        $rules['password'] = ['nullable', 'string', 'max:2000'];

        $validated = $request->validate($rules);

        $newType = (string) $validated['server_type'];

        if ($newType !== $oldType) {
            $hasAccounts = $server->hostingAccounts()->exists() || PanelAccount::where('server_id', $server->id)->exists();

            if ($hasAccounts) {
                return back()->withInput()->withErrors([
                    'server_type' => 'Server type cannot be changed after provisioning.',
                ]);
            }

            // Strict immutable for phase-1: any type change is rejected
            return back()->withInput()->withErrors([
                'server_type' => 'Server type is immutable and cannot be changed.',
            ]);
        }

        $groupId = $validated['server_group_id'] ?? null;
        if ($groupId !== null && $groupId !== '') {
            $group = ServerGroup::find($groupId);
            if ($group !== null && $group->allowed_server_type !== null && $group->allowed_server_type !== '' && $group->allowed_server_type !== $newType) {
                return back()->withInput()->withErrors([
                    'server_group_id' => "Group '{$group->name}' is locked to '{$group->allowed_server_type}' and cannot hold a '{$newType}' server.",
                ]);
            }
        }

        $attributes = $this->mapValidatedToAttributes($validated, $newType);
        // Never allow changing server_type via update (immutable)
        unset($attributes['server_type'], $attributes['connection_status']);

        // Merge Hyper-V transport prefs into existing connection_meta so the
        // port/use_ssl/verify_tls toggles actually save (host telemetry under
        // the nested `meta` key is preserved; only transport keys are replaced).
        if ($newType === 'hyperv') {
            $existingMeta = is_array($server->connection_meta) ? $server->connection_meta : [];
            $transportMeta = $this->hypervConnectionMeta($validated, $existingMeta);
            if ($transportMeta !== null) {
                $attributes['connection_meta'] = $transportMeta;
            }
        }

        if ($newType === 'hyperv') {
            $host = trim((string) ($validated['host'] ?? $validated['ip_address'] ?? $server->ip_address));
            $port = $validated['port'] ?? null;
            $useSsl = array_key_exists('use_ssl', $validated) ? filter_var($validated['use_ssl'], FILTER_VALIDATE_BOOLEAN) : null;
            if ($host !== '' && $port !== null) {
                $scheme = $useSsl ? 'https' : 'http';
                $attributes['api_url'] = sprintf('%s://%s:%d', $scheme, $host, (int) $port);
            }
        }

        DB::transaction(function () use ($server, $attributes, $groupId) {
            // Only update api_password_encrypted if a non-empty password was supplied; empty means keep existing
            if (array_key_exists('api_password_encrypted', $attributes) && $attributes['api_password_encrypted'] === null) {
                unset($attributes['api_password_encrypted']);
            }

            $server->update($attributes);

            if (array_key_exists('server_group_id', $attributes) || func_num_args() >= 0) {
                // Sync single group membership if server_group_id was in validated
                // We treat validated presence as intent; null/'' means detach all
                $validatedGroups = $groupId;
                if ($validatedGroups !== null) {
                    // Use validated value: '' / null = detach, otherwise attach to that single group
                    if ($validatedGroups === '' || $validatedGroups === null) {
                        ServerGroupMember::where('server_id', $server->id)->delete();
                    } else {
                        ServerGroupMember::where('server_id', $server->id)->delete();
                        ServerGroupMember::create([
                            'server_group_id' => (int) $validatedGroups,
                            'server_id' => $server->id,
                            'priority' => 0,
                        ]);
                    }
                }
            }
        });

        return redirect()
            ->route('admin.servers.show', $server)
            ->with('success', "Server {$server->name} updated.");
    }

    public function testConnection(Request $request, Server $server, IntegrationRegistry $registry): JsonResponse
    {
        $driver = $registry->resolveForServer($server);

        if ($driver === null) {
            return response()->json([
                'ok' => false,
                'message' => 'No module driver found for server type [' . $server->server_type . '].',
            ], 422);
        }

        $result = $driver->testConnection($server);

        // Deep-merge incoming meta over persisted telemetry: keys where BOTH
        // sides are arrays merge recursively, otherwise the incoming value
        // replaces. A partial-success Re-test (transport only) must never
        // wipe persisted telemetry (version/vmCounts/totalAccounts).
        $existingMeta = is_array($server->connection_meta) ? $server->connection_meta : [];
        $merged = $existingMeta;
        foreach ($result->meta as $key => $value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                $merged[$key] = array_replace_recursive($merged[$key], $value);
            } else {
                $merged[$key] = $value;
            }
        }

        $server->update([
            'connection_status' => $result->ok ? 'connected' : 'failed',
            'last_checked_at' => now(),
            'connection_error' => $result->ok ? null : $result->message,
            'connection_meta' => $merged !== [] ? $merged : null,
        ]);

        return response()->json([
            'ok' => $result->ok,
            'message' => $result->message,
            'latencyMs' => $result->latencyMs,
            'meta' => $result->meta,
            'server' => $this->sanitizedServerArray($server->fresh()),
        ]);
    }

    public function testConnectionDry(Request $request, IntegrationRegistry $registry): JsonResponse
    {
        $serverType = trim((string) $request->input('server_type'));

        if ($serverType === '') {
            return response()->json(['ok' => false, 'message' => 'server_type is required.'], 422);
        }

        $activeSlugs = $this->activeSlugs($registry);

        if (! in_array($serverType, $activeSlugs, true)) {
            return response()->json(['ok' => false, 'message' => "Unknown server type [{$serverType}]."], 422);
        }

        // Validate dry-run payload generically; per-type required fields are enforced by isConfigured / client
        $request->validate([
            'server_type' => ['required', 'string', Rule::in($activeSlugs)],
            'host' => ['nullable', 'string', 'max:255'],
            'ip_address' => ['nullable', 'string', 'max:255'],
            'api_url' => ['nullable', 'string', 'max:2000'],
            'api_username' => ['nullable', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:2000'],
            'password' => ['nullable', 'string', 'max:2000'],
            'api_password' => ['nullable', 'string', 'max:2000'],
            'api_password_encrypted' => ['nullable', 'string', 'max:2000'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'use_ssl' => ['nullable', 'boolean'],
            'verify_tls' => ['nullable', 'boolean'],
        ]);

        $driver = $registry->instanceFor($serverType);
        if ($driver === null) {
            try {
                $module = app(ModuleManager::class)->find($serverType);
                $driver = $module ? app(ModuleManager::class)->resolve($module) : null;
            } catch (\Throwable) {
                $driver = null;
            }
        }

        if ($driver === null) {
            return response()->json(['ok' => false, 'message' => 'No module driver found for server type [' . $serverType . '].'], 422);
        }

        // Build transient Server instance (not persisted) with encrypted cast handling
        $transient = new Server();
        $transient->server_type = $serverType;

        // Host resolution: prefer host, fallback to ip_address or api_url
        $host = trim((string) ($request->input('host') ?? $request->input('ip_address') ?? ''));
        $apiUrl = trim((string) $request->input('api_url'));

        if ($serverType === 'hyperv') {
            $port = $request->input('port', 5985);
            $useSsl = filter_var($request->input('use_ssl', false), FILTER_VALIDATE_BOOLEAN);
            $scheme = $useSsl ? 'https' : 'http';

            if ($host !== '') {
                $transient->ip_address = $host;
                $transient->api_url = sprintf('%s://%s:%d', $scheme, $host, (int) $port);
            } elseif ($apiUrl !== '') {
                $transient->api_url = $apiUrl;
                $transient->ip_address = $host !== '' ? $host : '127.0.0.1';
            } else {
                $transient->ip_address = '127.0.0.1';
            }

            $username = trim((string) ($request->input('username') ?? $request->input('api_username') ?? ''));
            $password = (string) ($request->input('password') ?? $request->input('api_password') ?? $request->input('api_password_encrypted') ?? $request->input('api_key') ?? '');

            $transient->api_username = $username;
            if ($password !== '') {
                $transient->api_password_encrypted = $password;
            }
        } else {
            // Panel types: map generic fields
            $transient->ip_address = $host !== '' ? $host : (trim((string) $request->input('ip_address')) !== '' ? trim((string) $request->input('ip_address')) : '127.0.0.1');
            $transient->api_url = $apiUrl !== '' ? $apiUrl : null;
            $transient->api_username = trim((string) ($request->input('api_username') ?? $request->input('username') ?? ''));
            $apiKey = trim((string) ($request->input('api_key') ?? $request->input('password') ?? $request->input('api_password_encrypted') ?? ''));
            $transient->api_key = $apiKey !== '' ? $apiKey : null;
            // Also populate encrypted field if password provided for panels that use it
            $pwd = trim((string) ($request->input('password') ?? ''));
            if ($pwd !== '' && $serverType === 'directadmin') {
                // DirectAdmin test uses api_key but we ensure something is set
                $transient->api_key = $pwd;
            }
        }

        // Verify TLS preference affects connection_meta for HyperVClient::verifyTls()
        $verifyTls = $request->has('verify_tls') ? filter_var($request->input('verify_tls'), FILTER_VALIDATE_BOOLEAN) : true;
        $transient->connection_meta = ['verify_tls' => $verifyTls];

        $result = $driver->testConnection($transient);

        return response()->json([
            'ok' => $result->ok,
            'message' => $result->message,
            'latencyMs' => $result->latencyMs,
            'meta' => $result->meta,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(IntegrationRegistry $registry, ?string $serverType = null, ?Server $existing = null): array
    {
        $activeSlugs = $this->activeSlugs($registry);

        $base = [
            'name' => ['required', 'string', 'max:255'],
            'server_type' => ['required', 'string', Rule::in($activeSlugs)],
            'server_group_id' => ['nullable', 'integer', 'exists:server_groups,id'],
            'max_accounts' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];

        // ip_address: required for all; allow hostname for hyperv/virtualization
        $typeForIp = $serverType ?? $existing?->server_type;

        if ($typeForIp === 'hyperv' || $typeForIp === 'proxmox') {
            // Hyper-V: allow either ip_address (top common field) or host (schema field) to satisfy hostname/ip
            $base['ip_address'] = ['nullable', 'string', 'max:255'];
            $base['host'] = ['nullable', 'string', 'max:255'];
        } else {
            $base['ip_address'] = ['required', 'string', 'max:255'];
            $base['host'] = ['nullable', 'string', 'max:255'];
        }

        // Common optional panel fields (always allowed so panel forms pass)
        $base['api_url'] = ['nullable', 'string', 'max:2000'];
        $base['api_url_url'] = []; // placeholder to avoid url rule breaking hyperv host:port
        // Re-define api_url with url check only when not hyperv and value looks like url
        // We keep it nullable url for panels; hyperv api_url is constructed so accept any string
        if (($typeForIp !== 'hyperv') && ($typeForIp !== 'proxmox')) {
            $base['api_url'] = ['nullable', 'url', 'max:2000'];
        }

        $base['api_username'] = ['nullable', 'string', 'max:255'];
        $base['username'] = ['nullable', 'string', 'max:255'];
        $base['api_key'] = ['nullable', 'string', 'max:2000'];
        $base['password'] = ['nullable', 'string', 'max:2000'];
        $base['api_password'] = ['nullable', 'string', 'max:2000'];
        $base['api_password_encrypted'] = ['nullable', 'string', 'max:2000'];

        // Type-specific refinements
        if ($serverType === 'hyperv') {
            $base['port'] = ['required', 'integer', 'min:1', 'max:65535'];
            $base['use_ssl'] = ['nullable', 'boolean'];
            $base['verify_tls'] = ['nullable', 'boolean'];
            // HyperV schema uses `username`/`password` keys — validate those, keep api_username as nullable alias
            $base['username'] = ['required', 'string', 'max:255'];
            $base['password'] = ['required', 'string', 'max:2000'];
            $base['api_username'] = ['nullable', 'string', 'max:255'];
        } elseif ($serverType !== null && $serverType !== '') {
            // For panel types, try to infer required keys from schema
            try {
                $instance = $registry->instanceFor($serverType);
                if ($instance === null) {
                    $module = app(ModuleManager::class)->find($serverType);
                    $instance = $module ? app(ModuleManager::class)->resolve($module) : null;
                }
                if ($instance !== null && method_exists($instance, 'serverConfigSchema')) {
                    $raw = $instance->serverConfigSchema();
                    $fields = $raw['fields'] ?? (is_array($raw) && array_is_list($raw) ? $raw : []);
                    foreach ($fields as $field) {
                        $key = $field['key'] ?? null;
                        if (! is_string($key) || $key === '') {
                            continue;
                        }
                        $required = (bool) ($field['required'] ?? false);
                        // Map schema keys to request keys; skip host/port already handled
                        if (in_array($key, ['api_url'], true)) {
                            $base[$key] = $required ? ['required', 'url', 'max:2000'] : ['nullable', 'url', 'max:2000'];
                        } elseif (in_array($key, ['api_username', 'username'], true)) {
                            $k = $key === 'username' ? 'username' : 'api_username';
                            $base[$k] = $required ? ['required', 'string', 'max:255'] : ['nullable', 'string', 'max:255'];
                        } elseif (in_array($key, ['api_key', 'password', 'api_password'], true)) {
                            $k = $key;
                            $base[$k] = $required ? ['required', 'string', 'max:2000'] : ['nullable', 'string', 'max:2000'];
                        } elseif ($key === 'verify_tls' || $key === 'use_ssl') {
                            $base[$key] = ['nullable', 'boolean'];
                        }
                    }
                }
            } catch (\Throwable) {
                // Keep base rules on schema failure
            }
        }

        return $base;
    }

    /**
     * Map validated request data to Server model attributes (column names).
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function mapValidatedToAttributes(array $validated, string $serverType): array
    {
        $out = [];

        if (array_key_exists('name', $validated)) {
            $out['name'] = $validated['name'];
        }
        if (array_key_exists('status', $validated)) {
            $out['status'] = $validated['status'];
        }
        if (array_key_exists('max_accounts', $validated)) {
            $out['max_accounts'] = $validated['max_accounts'];
        }
        if (array_key_exists('server_group_id', $validated)) {
            // Not a column; handled separately
        }

        // ip_address / host
        if ($serverType === 'hyperv') {
            $host = trim((string) ($validated['host'] ?? $validated['ip_address'] ?? ''));
            if ($host !== '') {
                $out['ip_address'] = $host;
            } elseif (isset($validated['ip_address'])) {
                $out['ip_address'] = $validated['ip_address'];
            }
        } else {
            if (array_key_exists('ip_address', $validated)) {
                $out['ip_address'] = $validated['ip_address'];
            } elseif (array_key_exists('host', $validated) && trim((string) $validated['host']) !== '') {
                $out['ip_address'] = trim((string) $validated['host']);
            }
        }

        if (array_key_exists('api_url', $validated)) {
            $out['api_url'] = $validated['api_url'] !== '' ? $validated['api_url'] : null;
        }

        // username
        if (array_key_exists('api_username', $validated) && trim((string) $validated['api_username']) !== '') {
            $out['api_username'] = trim((string) $validated['api_username']);
        } elseif (array_key_exists('username', $validated) && trim((string) $validated['username']) !== '') {
            $out['api_username'] = trim((string) $validated['username']);
        }

        // api_key vs encrypted password
        if ($serverType === 'hyperv') {
            $pwd = $validated['password'] ?? $validated['api_password'] ?? $validated['api_password_encrypted'] ?? null;
            if (is_string($pwd) && trim($pwd) !== '') {
                $out['api_password_encrypted'] = $pwd;
            } elseif (array_key_exists('password', $validated) && $validated['password'] === '') {
                // Explicitly empty on update means do not overwrite; handled in update()
                $out['api_password_encrypted'] = null;
            }
            // HyperV also stores api_key as null to avoid leaking
            if (array_key_exists('api_key', $validated) && $validated['api_key'] !== null && trim((string) $validated['api_key']) !== '') {
                $out['api_key'] = trim((string) $validated['api_key']);
            }
        } else {
            if (array_key_exists('api_key', $validated)) {
                $v = $validated['api_key'];
                $out['api_key'] = $v !== '' ? (string) $v : null;
            }
            // Panels that send password (directadmin encrypted etc.) may map to api_key or encrypted
            if (array_key_exists('password', $validated) && trim((string) $validated['password']) !== '') {
                // If server is directadmin/plesk/cpanel, password field is actually api_key
                // but if no api_key was provided, use it
                if (! isset($out['api_key']) || $out['api_key'] === null) {
                    $out['api_key'] = trim((string) $validated['password']);
                }
            }
            if (array_key_exists('api_password_encrypted', $validated) && trim((string) $validated['api_password_encrypted']) !== '') {
                $out['api_password_encrypted'] = trim((string) $validated['api_password_encrypted']);
            }
        }

        return $out;
    }

    /**
     * Transport prefs for Hyper-V (port/use_ssl/verify_tls) have no columns —
     * they persist inside connection_meta. Returns the merged meta, or null
     * when the request carries none of the three keys.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>|null  $existing  existing meta to merge into (update); null seeds fresh (store)
     * @return array<string, mixed>|null
     */
    private function hypervConnectionMeta(array $validated, ?array $existing = null): ?array
    {
        $transport = [];

        if (array_key_exists('port', $validated) && $validated['port'] !== null && $validated['port'] !== '') {
            $transport['port'] = (int) $validated['port'];
        }
        if (array_key_exists('use_ssl', $validated) && $validated['use_ssl'] !== null && $validated['use_ssl'] !== '') {
            $transport['use_ssl'] = filter_var($validated['use_ssl'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('verify_tls', $validated) && $validated['verify_tls'] !== null && $validated['verify_tls'] !== '') {
            $transport['verify_tls'] = filter_var($validated['verify_tls'], FILTER_VALIDATE_BOOLEAN);
        }

        if ($transport === []) {
            return null;
        }

        return $existing !== null ? array_merge($existing, $transport) : $transport;
    }

    /**
     * @return list<string>
     */
    private function activeSlugs(IntegrationRegistry $registry): array
    {
        try {
            return array_column($registry->serverTypeOptions(), 'value');
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function sanitizedServerArray(?Server $server): ?array
    {
        if ($server === null) {
            return null;
        }

        $arr = $server->toArray();

        unset($arr['api_key'], $arr['api_password_encrypted'], $arr['api_password']);

        // Also strip from connection_meta if it accidentally contains credentials
        if (isset($arr['connection_meta']) && is_array($arr['connection_meta'])) {
            unset($arr['connection_meta']['password'], $arr['connection_meta']['api_key']);
        }

        return $arr;
    }
}
