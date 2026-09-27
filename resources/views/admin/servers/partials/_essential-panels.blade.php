{{-- Essential 4-card grid (todo 8 canonical): Host / Uptime / Available / Consumption.
    Vars: $server, $vm (ServerDetailViewModel|null), plus view-scope
    $poolAvailability (todo 10 entitlement rows), $consumptionRows (todo 10
    metered + legacy rows), $provisionedTotal / $schedulerLoad (todo 11).
    Dumb — all parsing in ViewModel / controller aggregates. Frozen vocabulary
    (todo 1): missing scalar renders —, missing census source renders
    "No remote data", disconnected renders "No data yet — Re-test".
    SOURCE→CARD TABLE:
      Host: hostname + version + latency + single Transport strip (Hyper-V only:
        host:port + one security badge — "No SSL" already implies TLS is moot).
      Uptime: Hyper-V uptimeDisplay + bootTime, else SNMP snmpUptime (labeled), else frozen empty.
      Available: Live telemetry (Hyper-V RAM/storage bars, CPU load, SNMP gauges
        tagged once) + Entitlement pools ($poolAvailability) + Census (todo 11
        labels + drift badge) as separately labeled sections.
      Consumption: metered usage_records rows + legacy quota rows, separately labeled.
    No fact appears twice anywhere in this section: the page header owns the IP
    address and the API URL, RAM/storage print used-of-total + share only (free
    and the aggregate are derivable), a volume row is shown only when there is
    more than one real volume, the Hyper-V run/stop split replaces the census
    total it is made of, and labeled groups with nothing to say are hidden
    (zero-value usage rows and all-zero quota rows never render). --}}
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
    // Census remote cell: the run/stop split IS the remote total, so it replaces
    // it rather than printing the same VMs twice. Built here (not with inline
    // directives) so no @if can be glued to a word and leak as literal text.
    $remoteDisplay = '—';
    if ($hasVmCounts && is_array($vmCounts)) {
        $remoteDisplay = ((int) ($vmCounts['running'] ?? 0)).' running · '.((int) ($vmCounts['stopped'] ?? 0)).' stopped';
        if ((int) ($vmCounts['saved'] ?? 0) > 0) {
            $remoteDisplay .= ' · '.((int) $vmCounts['saved']).' saved';
        }
    } elseif ($remoteTotal !== null) {
        $remoteDisplay = (string) $remoteTotal;
    } elseif ($hypervNoRemote || ($server->server_type ?? null) === 'plesk') {
        $remoteDisplay = 'No remote data';
    }
    $freshError = $freshError ?? null;
    // Aggregates (todo 10): pools-minus-allocations + usage/quotas, read-only rows.
    $poolAvailability = $poolAvailability ?? [];
    $consumptionRows = $consumptionRows ?? [];
    // Zero-value rows are dropped once, here: a 0 B quota is a missing setting,
    // never usage, and an all-zero block must not render at all.
    $isNonZeroRow = fn ($r) => is_numeric($r['value'] ?? null) && (float) $r['value'] != 0.0;
    $meteredRows = array_values(array_filter(is_array($consumptionRows) ? $consumptionRows : [], fn ($r) => ($r['source'] ?? '') === 'metered' && $isNonZeroRow($r)));
    $legacyRows = array_values(array_filter(is_array($consumptionRows) ? $consumptionRows : [], fn ($r) => ($r['source'] ?? '') === 'legacy' && $isNonZeroRow($r)));
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
                    {{-- One security badge: without SSL, a TLS-verify flag says nothing new. --}}
                    @if ($vm?->metaUseSsl !== null)
                        @if ($vm?->metaUseSsl)
                            <span class="badge text-bg-success" style="font-size:var(--text-xs);">SSL</span>
                            @if ($vm?->metaVerifyTls === false)
                                <span class="badge text-bg-warning" style="font-size:var(--text-xs);">Verify TLS off</span>
                            @endif
                        @else
                            <span class="badge text-bg-secondary" style="font-size:var(--text-xs);">No SSL</span>
                        @endif
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
                    <i class="bi bi-clock-history me-1"></i>
                    @if ($server->last_checked_at)
                        Live data may be stale — last checked {{ $server->last_checked_at->diffForHumans() }}.
                    @else
                        Live data may be stale — never checked.
                    @endif
                    @if (! empty($freshError))
                        <br>Last refresh failed: {{ $freshError }}
                    @endif
                    Re-test to refresh.
                    <button type="button" class="btn btn-sm btn-outline-warning ms-2" onclick="document.getElementById('retestBtn')?.click()">Re-test now</button>
                </div>
            @elseif ($isHyperv && ! $hasVmCounts)
                <div class="alert alert-warning py-1 px-2 mb-2 small" data-available="limited-badge">
                    <i class="bi bi-exclamation-triangle me-1"></i>Limited data — re-test to load VM counts.
                </div>
            @endif
            <div class="text-muted small mt-1" style="font-size:var(--text-xs); text-transform:uppercase;">Live</div>
            @if ($ramTotal !== null)
                <div class="d-flex justify-content-between gap-2 small mt-1" data-available="live-ram">
                    <span class="text-muted">RAM</span>
                    <span class="fw-semibold">{{ \App\ViewModels\Admin\ServerDetailViewModel::fmtBytes($ramUsed) }} of {{ \App\ViewModels\Admin\ServerDetailViewModel::fmtBytes($ramTotal) }}@if ($ramPct !== null)<span class="fw-normal text-muted"> · {{ $ramPct }}%</span>@endif</span>
                </div>
                @if ($ramPct !== null)
                    <div class="progress mt-1" style="height:6px;" role="progressbar" aria-valuenow="{{ $ramPct }}" aria-valuemin="0" aria-valuemax="100" title="RAM used {{ $ramPct }}%">
                        <div class="progress-bar {{ $ramPct >= 90 ? 'bg-danger' : ($ramPct >= 75 ? 'bg-warning' : 'bg-success') }}" style="width:{{ $ramPct }}%;"></div>
                    </div>
                @endif
            @endif
            @php
                $storageHasTotal = is_numeric($storageTotal) && $storageTotal > 0;
                // Per-volume rows only when they carry a fact the aggregate does
                // not: more than one real volume. Zero-size drives are device
                // artefacts (empty CD/removable), never capacity.
                $realVolumes = array_values(array_filter(is_array($volumes) ? $volumes : [], fn ($v) => is_array($v) && is_numeric($v['total'] ?? $v['Total'] ?? null) && (int) ($v['total'] ?? $v['Total']) > 0));
            @endphp
            @if ($storageHasTotal || $storageFree !== null)
                <div class="d-flex justify-content-between gap-2 small mt-1" data-available="live-storage">
                    <span class="text-muted">Storage</span>
                    @if ($storageHasTotal)
                        <span class="fw-semibold">{{ is_numeric($storageUsed) ? \App\ViewModels\Admin\ServerDetailViewModel::fmtBytes($storageUsed) : $storageUsed }} of {{ \App\ViewModels\Admin\ServerDetailViewModel::fmtBytes($storageTotal) }}@if ($storagePct !== null)<span class="fw-normal text-muted"> · {{ $storagePct }}%</span>@endif</span>
                    @else
                        <span class="fw-semibold">{{ is_numeric($storageFree) ? \App\ViewModels\Admin\ServerDetailViewModel::fmtBytes($storageFree) : $storageFree }} free</span>
                    @endif
                </div>
                @if ($storageHasTotal && $storagePct !== null)
                    <div class="progress mt-1" style="height:6px;" role="progressbar" aria-valuenow="{{ $storagePct }}" aria-valuemin="0" aria-valuemax="100" title="Storage used {{ $storagePct }}%">
                        <div class="progress-bar {{ $storagePct >= 90 ? 'bg-danger' : ($storagePct >= 75 ? 'bg-warning' : 'bg-success') }}" style="width:{{ $storagePct }}%;"></div>
                    </div>
                @endif
                @if (count($realVolumes) > 1)
                    <div class="mt-1 d-flex flex-column gap-1">
                        @foreach ($realVolumes as $vol)
                            @php
                                $vName = (string) ($vol['name'] ?? $vol['Name'] ?? '?');
                                $vTotal = (int) ($vol['total'] ?? $vol['Total'] ?? 0);
                                $vUsed = (int) ($vol['used'] ?? $vol['Used'] ?? 0);
                                $vFree = (int) ($vol['free'] ?? $vol['Free'] ?? 0);
                                $vPct = $vTotal > 0 ? min(100, round($vUsed / $vTotal * 100)) : null;
                            @endphp
                            <div class="d-flex justify-content-between gap-2" style="font-size:var(--text-xs);">
                                <span class="text-muted">{{ $vName }}:</span>
                                <span class="fw-semibold">@if($vPct !== null){{ $vPct }}%@endif<span class="fw-normal text-muted"> · {{ \App\ViewModels\Admin\ServerDetailViewModel::fmtBytes($vFree) }} free</span></span>
                            </div>
                        @endforeach
                    </div>
                @endif
            @endif
            @if ($logicalCpu !== null || $cpuLoadPercent !== null)
                <div class="small mt-1" data-available="live-compute">Compute:
                    @if ($logicalCpu !== null)<strong>{{ $logicalCpu }} logical CPUs</strong>@endif
                    @if ($logicalCpu !== null && $cpuLoadPercent !== null)<span class="text-muted"> · </span>@endif
                    @if ($cpuLoadPercent !== null)<span>avg load <strong>{{ $cpuLoadPercent }}%</strong></span>@endif
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
            @if (! empty($poolAvailability))
                <div class="text-muted small mt-2" style="font-size:var(--text-xs); text-transform:uppercase;">Entitlement pools</div>
                @foreach ($poolAvailability as $pool)
                    <div class="d-flex justify-content-between gap-2" style="font-size:var(--text-xs);">
                        <span class="text-muted">{{ $pool['pool_type'] }}@if(trim((string) ($pool['unit'] ?? '')) !== '') ({{ $pool['unit'] }})@endif</span>
                        <span class="fw-semibold">{{ $pool['display'] }} available</span>
                    </div>
                @endforeach
            @endif
            <div class="text-muted small mt-2" style="font-size:var(--text-xs); text-transform:uppercase;">Census</div>
            <div class="d-flex justify-content-between gap-2" style="font-size:var(--text-xs);">
                <span class="text-muted">Remote</span>
                <span class="fw-semibold" data-census="remote">{{ $remoteDisplay }}</span>
            </div>
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
            @if ($hasDrift)
                <div class="mt-2" data-census="drift-badge">
                    <span class="badge text-bg-warning" style="font-size:var(--text-xs); font-weight:500;">Remote differs from ledger — last poll {{ $vm?->censusCheckedAtHuman ?? $censusCheckedAt ?? 'never' }}</span>
                </div>
            @endif
        </div>
    </div>
    {{-- Card 4 — Consumption: metered usage_records rows + legacy quota rows, separately labeled.
         Zero-value rows are dropped (a 0 B quota is a missing setting, not usage) and a
         labeled block disappears with them; both empty means one honest sentence. --}}
    <div class="col-6 col-lg-3">
        <div class="border rounded-3 p-3 h-100" data-card="consumption" style="border-radius:var(--radius-lg); background: var(--bs-body-bg);">
            <div class="text-muted small mb-1" style="font-size:var(--text-xs); letter-spacing:0.02em; text-transform:uppercase;">Consumption</div>
            @if ($meteredRows === [] && $legacyRows === [])
                <div class="fw-semibold" style="font-size:var(--text-xs);">No usage recorded</div>
            @else
                @if ($meteredRows !== [])
                    <div class="text-muted small mt-1" style="font-size:var(--text-xs); text-transform:uppercase;">Metered</div>
                    @foreach ($meteredRows as $row)
                        <div class="d-flex justify-content-between gap-2" style="font-size:var(--text-xs);">
                            <span class="text-muted">{{ $row['label'] }}</span>
                            <span class="fw-semibold">{{ $row['display'] }}</span>
                        </div>
                    @endforeach
                @endif
                @if ($legacyRows !== [])
                    <div class="text-muted small mt-2" style="font-size:var(--text-xs); text-transform:uppercase;">Legacy quota</div>
                    @foreach ($legacyRows as $row)
                        <div class="d-flex justify-content-between gap-2" style="font-size:var(--text-xs);">
                            <span class="text-muted">{{ $row['label'] }}</span>
                            <span class="fw-semibold">{{ $row['display'] }}</span>
                        </div>
                    @endforeach
                @endif
            @endif
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
