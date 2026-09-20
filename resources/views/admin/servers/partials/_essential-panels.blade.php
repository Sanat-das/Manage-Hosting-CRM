{{-- Essential 4-card grid (todo 8 canonical): Host / Uptime / Available / Consumption.
    Vars: $server, $vm (ServerDetailViewModel|null), plus view-scope
    $poolAvailability (todo 10 entitlement rows), $consumptionRows (todo 10
    metered + legacy rows), $provisionedTotal / $schedulerLoad (todo 11).
    Dumb — all parsing in ViewModel / controller aggregates. Frozen vocabulary
    (todo 1): missing scalar renders —, missing census source renders
    "No remote data", disconnected renders "No data yet — Re-test".
    SOURCE→CARD TABLE:
      Host: hostname + ip + version + latency + single Transport strip (Hyper-V only).
      Uptime: Hyper-V uptimeDisplay + bootTime, else SNMP snmpUptime (labeled), else frozen empty.
      Available: Live telemetry (Hyper-V RAM/storage bars, CPU load, SNMP gauges
        tagged once) + Entitlement pools ($poolAvailability) + Census (todo 11
        labels + drift badge) as separately labeled sections.
      Consumption: metered usage_records rows + legacy quota rows, separately labeled.
    No fact appears twice within one card (frozen-vocabulary placeholders shared). --}}
@php
    $vm = $vm ?? null;
    $hostname = $vm?->hostname ?? ($hostname ?? $server->ip_address ?? null);
    $version = $vm?->version ?? ($version ?? '');
    $latency = $vm?->latency ?? ($latency ?? null);
    $isHyperv = $vm?->isHyperv ?? (($server->server_type ?? null) === 'hyperv');
    // Census (todo 11): explicit meanings, never silent substitution.
    // Remote = connection_meta.totalAccounts (Hyper-V: meta.meta.vmCounts.total);
    // missing remote renders —, Plesk stub renders "No remote data" (never "0 servers").
    $remoteTotal = $vm?->remoteTotal;
    $localTotal = $vm?->localTotal;
    $hasDrift = $vm?->hasDrift ?? false;
    // Hyper-V with a failed live fetch has no census source: never print a
    // number we do not have (missing scalar renders —, missing census source
    // renders No remote data). Non-Hyper-V behaviour unchanged.
    $hypervNoRemote = $isHyperv && ! ($vm?->hasVmCounts ?? false) && $remoteTotal === null;
    $censusCheckedAt = $vm?->censusCheckedAt;
    $provisionedTotal = $provisionedTotal ?? null;
    $schedulerLoad = $schedulerLoad ?? null;
    $capDisplay = ($server->max_accounts ?? 0) > 0 ? $server->max_accounts : 'Unlimited';
    // Uptime sources: Hyper-V meta wins, SNMP bridge (todo 9) labeled fallback.
    $uptimeDisplay = $vm?->uptimeDisplay;
    $bootTime = $vm?->bootTime;
    $snmpUptime = $vm?->snmpUptime;
    // Available: live telemetry (Hyper-V bars + SNMP gauges, todo 9).
    $ramTotal = $vm?->ramTotal;
    $ramFree = $vm?->ramFree;
    $ramUsed = $vm?->ramUsed;
    $ramPct = $vm?->ramPct;
    $storageTotal = $vm?->storageTotal;
    $storageFree = $vm?->storageFree;
    $storageUsed = $vm?->storageUsed;
    $storagePct = $vm?->storagePct;
    $volumes = $vm?->volumes;
    $logicalCpu = $vm?->logicalCpu;
    $cpuLoadPercent = $vm?->cpuLoadPercent;
    $snmpCpu = $vm?->snmpCpu;
    $snmpMem = $vm?->snmpMem;
    $snmpDisks = $vm?->snmpDisks;
    $snmpSource = $vm?->snmpSource;
    $snmpCollectedAt = $vm?->snmpCollectedAt;
    $vmCounts = $vm?->vmCounts ?? ($vmCounts ?? null);
    $isStale = $vm?->isStale ?? false;
    $hasVmCounts = $vm?->hasVmCounts ?? false;
    // Aggregates (todo 10): pools-minus-allocations + usage/quotas, read-only rows.
    $poolAvailability = $poolAvailability ?? [];
    $consumptionRows = $consumptionRows ?? [];
    $meteredRows = array_values(array_filter(is_array($consumptionRows) ? $consumptionRows : [], fn ($r) => ($r['source'] ?? '') === 'metered'));
    $legacyRows = array_values(array_filter(is_array($consumptionRows) ? $consumptionRows : [], fn ($r) => ($r['source'] ?? '') === 'legacy'));
    $hasLive = $ramTotal !== null || $storageFree !== null || (is_numeric($storageTotal) && $storageTotal > 0)
        || $logicalCpu !== null || $cpuLoadPercent !== null
        || $snmpCpu !== null || $snmpMem !== null || $snmpDisks !== null;
@endphp
<div class="row g-3" id="essentialPanels">
    {{-- Card 1 — Host: hostname + ip + version + latency + single Transport strip. --}}
    <div class="col-6 col-lg-3">
        <div class="border rounded-3 p-3 h-100" data-card="host" style="border-radius:var(--radius-lg); background: var(--bs-body-bg);">
            <div class="text-muted small mb-1" style="font-size:var(--text-xs); letter-spacing:0.02em; text-transform:uppercase;">Host</div>
            <div class="fw-semibold text-truncate" style="font-size:var(--text-sm);">{{ $hostname ?: $server->ip_address }}</div>
            @if (($server->ip_address ?? null) && $server->ip_address !== $hostname)
                <div class="text-muted small" style="font-size:var(--text-xs);">{{ $server->ip_address }}</div>
            @endif
            <div class="d-flex justify-content-between gap-2 mt-1" style="font-size:var(--text-xs);">
                <span class="text-muted">Version</span>
                <span class="fw-semibold">{{ $version !== '' ? $version : '—' }}</span>
            </div>
            <div class="d-flex justify-content-between gap-2" style="font-size:var(--text-xs);">
                <span class="text-muted">Latency</span>
                <span class="fw-semibold">{{ $latency !== null ? $latency.' ms' : '—' }}</span>
            </div>
            @if ($isHyperv)
                <div class="d-flex flex-wrap align-items-center gap-1 mt-2 pt-2 border-top small" data-transport="strip">
                    <span class="text-muted" style="font-size:var(--text-xs); text-transform:uppercase;">Transport</span>
                    <code style="font-size:var(--text-xs);">{{ $vm?->transportHost }}:{{ $vm?->transportPort }}</code>
                    @if ($vm?->metaUseSsl !== null)
                        @if ($vm?->metaUseSsl)
                            <span class="badge text-bg-success" style="font-size:var(--text-xs);">SSL</span>
                        @else
                            <span class="badge text-bg-secondary" style="font-size:var(--text-xs);">No SSL</span>
                        @endif
                    @endif
                    @if ($vm?->metaVerifyTls !== null)
                        @if ($vm?->metaVerifyTls)
                            <span class="badge text-bg-info" style="font-size:var(--text-xs);">Verify TLS on</span>
                        @else
                            <span class="badge text-bg-warning" style="font-size:var(--text-xs);">Verify TLS off</span>
                        @endif
                    @endif
                    @if ($server->api_url)
                        <span class="text-muted text-truncate" style="font-size:var(--text-xs); max-width: 100%;">{{ $server->api_url }}</span>
                    @endif
                </div>
            @endif
        </div>
    </div>
    {{-- Card 2 — Uptime: uptimeDisplay + bootTime, else labeled SNMP, else frozen empty. --}}
    <div class="col-6 col-lg-3">
        <div class="border rounded-3 p-3 h-100" data-card="uptime" style="border-radius:var(--radius-lg); background: var(--bs-body-bg);">
            <div class="text-muted small mb-1" style="font-size:var(--text-xs); letter-spacing:0.02em; text-transform:uppercase;">Uptime</div>
            @if ($uptimeDisplay)
                <div class="fw-semibold" style="font-size:var(--text-sm);">{{ $uptimeDisplay }}</div>
                @if ($bootTime)
                    <div class="text-muted small" style="font-size:var(--text-xs);">Booted {{ $bootTime }}</div>
                @endif
            @elseif ($bootTime)
                <div class="fw-semibold" style="font-size:var(--text-sm);">Booted {{ $bootTime }}</div>
            @elseif ($snmpUptime)
                <div class="fw-semibold" style="font-size:var(--text-sm);">{{ $snmpUptime }}</div>
                <div class="text-muted small" style="font-size:var(--text-xs);">SNMP · {{ $snmpSource }} · {{ $snmpCollectedAt }}</div>
            @else
                <div class="fw-semibold" style="font-size:var(--text-sm);">—</div>
                <div class="text-muted small" style="font-size:var(--text-xs);">No data yet — Re-test</div>
            @endif
        </div>
    </div>
    {{-- Card 3 — Available: live telemetry + entitlement pools + census, separately labeled. --}}
    <div class="col-6 col-lg-3">
        <div class="border rounded-3 p-3 h-100" data-card="available" style="border-radius:var(--radius-lg); background: var(--bs-body-bg);">
            <div class="text-muted small mb-1" style="font-size:var(--text-xs); letter-spacing:0.02em; text-transform:uppercase;">Available</div>
            @if ($isStale)
                <div class="alert alert-warning py-1 px-2 mb-2 small" data-available="stale-badge">
                    <i class="bi bi-clock-history me-1"></i>@if ($server->last_checked_at)Live data may be stale — last checked {{ $server->last_checked_at->diffForHumans() }}.@elseLive data may be stale — never checked.@endif Re-test to refresh.
                    <button type="button" class="btn btn-sm btn-outline-warning ms-2" onclick="document.getElementById('retestBtn')?.click()">Re-test now</button>
                </div>
            @elseif ($isHyperv && ! $hasVmCounts)
                <div class="alert alert-warning py-1 px-2 mb-2 small" data-available="limited-badge">
                    <i class="bi bi-exclamation-triangle me-1"></i>Limited data — re-test to load VM counts.
                </div>
            @endif
            <div class="text-muted small mt-1" style="font-size:var(--text-xs); text-transform:uppercase;">Live</div>
            @if ($ramTotal !== null)
                <div class="small mt-1" data-available="live-ram">RAM: <strong>{{ \App\ViewModels\Admin\ServerDetailViewModel::fmtBytes($ramUsed) }} used of {{ \App\ViewModels\Admin\ServerDetailViewModel::fmtBytes($ramTotal) }}</strong></div>
                @if ($ramPct !== null)
                    <div class="progress mt-1" style="height:6px;" role="progressbar" aria-valuenow="{{ $ramPct }}" aria-valuemin="0" aria-valuemax="100" title="RAM used {{ $ramPct }}%">
                        <div class="progress-bar {{ $ramPct >= 90 ? 'bg-danger' : ($ramPct >= 75 ? 'bg-warning' : 'bg-success') }}" style="width:{{ $ramPct }}%;"></div>
                    </div>
                    <div class="text-muted mt-1" style="font-size:var(--text-xs);">{{ $ramPct }}% · {{ \App\ViewModels\Admin\ServerDetailViewModel::fmtBytes($ramFree) }} free</div>
                @endif
            @endif
            @php $storageHasTotal = is_numeric($storageTotal) && $storageTotal > 0; @endphp
            @if ($storageHasTotal || $storageFree !== null)
                <div class="small mt-1" data-available="live-storage">Storage:
                    @if ($storageHasTotal)<strong>{{ is_numeric($storageUsed) ? \App\ViewModels\Admin\ServerDetailViewModel::fmtBytes($storageUsed) : $storageUsed }} used of {{ \App\ViewModels\Admin\ServerDetailViewModel::fmtBytes($storageTotal) }}</strong>@else<strong>{{ is_numeric($storageFree) ? \App\ViewModels\Admin\ServerDetailViewModel::fmtBytes($storageFree) : $storageFree }} free</strong>@endif
                </div>
                @if ($storageHasTotal && $storagePct !== null)
                    <div class="progress mt-1" style="height:6px;" role="progressbar" aria-valuenow="{{ $storagePct }}" aria-valuemin="0" aria-valuemax="100" title="Storage used {{ $storagePct }}%">
                        <div class="progress-bar {{ $storagePct >= 90 ? 'bg-danger' : ($storagePct >= 75 ? 'bg-warning' : 'bg-success') }}" style="width:{{ $storagePct }}%;"></div>
                    </div>
                    <div class="text-muted mt-1" style="font-size:var(--text-xs);">{{ $storagePct }}% used · {{ is_numeric($storageFree) ? \App\ViewModels\Admin\ServerDetailViewModel::fmtBytes($storageFree) : $storageFree }} free</div>
                @endif
                @if (is_array($volumes) && count($volumes) > 0)
                    <div class="mt-1 d-flex flex-column gap-1">
                        @foreach (array_slice(array_values($volumes), 0, 4) as $vol)
                            @php
                                $vName = is_array($vol) ? ($vol['name'] ?? $vol['Name'] ?? '?') : '?';
                                $vTotal = is_array($vol) ? ($vol['total'] ?? $vol['Total'] ?? null) : null;
                                $vUsed = is_array($vol) ? ($vol['used'] ?? $vol['Used'] ?? null) : null;
                                $vFree = is_array($vol) ? ($vol['free'] ?? $vol['Free'] ?? null) : null;
                                $vPct = (is_numeric($vTotal) && $vTotal > 0 && is_numeric($vUsed)) ? min(100, round($vUsed / $vTotal * 100)) : null;
                            @endphp
                            <div class="text-muted" style="font-size:var(--text-xs);">{{ $vName }}:
                                @if($vPct !== null)<strong class="text-body">{{ $vPct }}%</strong> · @endif
                                <span>{{ is_numeric($vFree) ? \App\ViewModels\Admin\ServerDetailViewModel::fmtBytes($vFree).' free' : '' }}@if(is_numeric($vTotal)) of {{ \App\ViewModels\Admin\ServerDetailViewModel::fmtBytes($vTotal) }}@endif</span>
                            </div>
                        @endforeach
                        @if (count($volumes) > 4)
                            <div class="text-muted" style="font-size:var(--text-xs);">+{{ count($volumes) - 4 }} more volume(s)</div>
                        @endif
                    </div>
                @endif
            @endif
            @if ($logicalCpu !== null || $cpuLoadPercent !== null)
                <div class="small mt-1" data-available="live-compute">Compute:
                    @if ($logicalCpu !== null)<strong>{{ $logicalCpu }} logical CPUs</strong>@endif
                    @if ($logicalCpu !== null && $cpuLoadPercent !== null)<span class="text-muted"> · </span>@endif
                    @if ($cpuLoadPercent !== null)<span>load <strong>{{ $cpuLoadPercent }}%</strong></span>@endif
                </div>
            @endif
            @if ($snmpCpu !== null || $snmpMem !== null || $snmpDisks !== null)
                <div class="text-muted small mt-1" style="font-size:var(--text-xs);">SNMP · {{ $snmpSource }} · {{ $snmpCollectedAt }}</div>
                @if ($snmpCpu !== null)
                    <div class="d-flex justify-content-between gap-2" style="font-size:var(--text-xs);"><span class="text-muted">SNMP CPU</span><span class="fw-semibold">{{ $snmpCpu }}%</span></div>
                @endif
                @if ($snmpMem !== null)
                    <div class="d-flex justify-content-between gap-2" style="font-size:var(--text-xs);"><span class="text-muted">SNMP memory</span><span class="fw-semibold">{{ $snmpMem }}%</span></div>
                @endif
                @if ($snmpDisks !== null)
                    <div class="d-flex justify-content-between gap-2" style="font-size:var(--text-xs);"><span class="text-muted">SNMP disk</span><span class="fw-semibold">{{ $snmpDisks }}%</span></div>
                @endif
            @endif
            @if (! $hasLive)
                <div class="fw-semibold" style="font-size:var(--text-sm);">—</div>
            @endif
            <div class="text-muted small mt-2" style="font-size:var(--text-xs); text-transform:uppercase;">Entitlement pools</div>
            @forelse ($poolAvailability as $pool)
                <div class="d-flex justify-content-between gap-2" style="font-size:var(--text-xs);">
                    <span class="text-muted">{{ $pool['pool_type'] }}@if(trim((string) ($pool['unit'] ?? '')) !== '') ({{ $pool['unit'] }})@endif</span>
                    <span class="fw-semibold">{{ $pool['display'] }} available</span>
                </div>
            @empty
                <div class="fw-semibold" style="font-size:var(--text-xs);">—</div>
            @endforelse
            <div class="text-muted small mt-2" style="font-size:var(--text-xs); text-transform:uppercase;">Census</div>
            <div class="d-flex justify-content-between gap-2" style="font-size:var(--text-xs);">
                <span class="text-muted">Remote</span>
                <span class="fw-semibold" data-census="remote">@if ($remoteTotal !== null){{ $remoteTotal }}@elseif (($server->server_type ?? null) === 'plesk')<span class="fw-normal text-muted">No remote data</span>@else—@endif</span>
            </div>
            @if ($hypervNoRemote)
                <div class="text-muted small" style="font-size:var(--text-xs);">No remote data</div>
            @endif
            <div class="d-flex justify-content-between gap-2" style="font-size:var(--text-xs);">
                <span class="text-muted">Local ledger</span>
                <span class="fw-semibold" data-census="local">{{ $localTotal ?? '—' }}</span>
            </div>
            <div class="d-flex justify-content-between gap-2" style="font-size:var(--text-xs);">
                <span class="text-muted">Provisioned VMs</span>
                <span class="fw-semibold" data-census="provisioned">{{ $provisionedTotal ?? '—' }}</span>
            </div>
            <div class="d-flex justify-content-between gap-2" style="font-size:var(--text-xs);">
                <span class="text-muted">Scheduler load</span>
                <span class="fw-semibold" data-census="load">{{ $schedulerLoad ?? '—' }}</span>
            </div>
            <div class="d-flex justify-content-between gap-2" style="font-size:var(--text-xs);">
                <span class="text-muted">Cap</span>
                <span class="fw-semibold" data-census="cap">{{ $capDisplay }}</span>
            </div>
            @if (is_array($vmCounts) && $hasVmCounts)
                <div class="text-muted small mt-1" style="font-size:var(--text-xs);">
                    {{ $vmCounts['running'] ?? 0 }} running · {{ $vmCounts['stopped'] ?? 0 }} stopped
                    @if (isset($vmCounts['saved']) && $vmCounts['saved'] > 0) · {{ $vmCounts['saved'] }} saved @endif
                </div>
            @endif
            @if ($hasDrift)
                <div class="mt-2" data-census="drift-badge">
                    <span class="badge text-bg-warning" style="font-size:var(--text-xs); font-weight:500;">Remote differs from ledger — last poll {{ $censusCheckedAt ?? 'never' }}</span>
                </div>
            @endif
        </div>
    </div>
    {{-- Card 4 — Consumption: metered usage_records rows + legacy quota rows, separately labeled. --}}
    <div class="col-6 col-lg-3">
        <div class="border rounded-3 p-3 h-100" data-card="consumption" style="border-radius:var(--radius-lg); background: var(--bs-body-bg);">
            <div class="text-muted small mb-1" style="font-size:var(--text-xs); letter-spacing:0.02em; text-transform:uppercase;">Consumption</div>
            <div class="text-muted small mt-1" style="font-size:var(--text-xs); text-transform:uppercase;">Metered</div>
            @forelse ($meteredRows as $row)
                <div class="d-flex justify-content-between gap-2" style="font-size:var(--text-xs);">
                    <span class="text-muted">{{ $row['label'] }}</span>
                    <span class="fw-semibold">{{ $row['display'] }}</span>
                </div>
            @empty
                <div class="fw-semibold" style="font-size:var(--text-xs);">—</div>
            @endforelse
            <div class="text-muted small mt-2" style="font-size:var(--text-xs); text-transform:uppercase;">Legacy quota</div>
            @forelse ($legacyRows as $row)
                <div class="d-flex justify-content-between gap-2" style="font-size:var(--text-xs);">
                    <span class="text-muted">{{ $row['label'] }}</span>
                    <span class="fw-semibold">{{ $row['display'] }}</span>
                </div>
            @empty
                <div class="fw-semibold" style="font-size:var(--text-xs);">—</div>
            @endforelse
        </div>
    </div>
</div>
{{-- Re-test skeleton: unhidden by re-test JS before fetch so the card never looks frozen. --}}
<div class="retest-skeleton d-none mt-3" aria-hidden="true">
    <div class="placeholder-glow">
        <div class="row g-3">
            @for ($i = 0; $i < 4; $i++)
                <div class="col-6 col-lg-3"><span class="placeholder col-12 rounded-3" style="height:74px; display:block;"></span></div>
            @endfor
        </div>
        <span class="placeholder col-4 mt-2"></span>
    </div>
</div>
