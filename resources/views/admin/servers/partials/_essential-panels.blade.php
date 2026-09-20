{{-- Essential 4-card grid: Version / Host / Census / Latency + re-test skeleton.
    Vars: $server, $vm (ServerDetailViewModel|null), plus optional
    injectable $hostname override (ViewModel is single reader). --}}
@php
    $vm = $vm ?? null;
    $hostname = $vm?->hostname ?? ($hostname ?? $server->ip_address ?? null);
    $version = $vm?->version ?? ($version ?? '');
    $latency = $vm?->latency ?? ($latency ?? null);
    $vmCounts = $vm?->vmCounts ?? ($vmCounts ?? null);
    // Census (todo 11): explicit meanings, never silent substitution.
    // Remote = connection_meta.totalAccounts (Hyper-V: meta.meta.vmCounts.total);
    // missing remote renders —, Plesk stub renders "No remote data" (never "0 servers").
    $remoteTotal = $vm?->remoteTotal;
    $localTotal = $vm?->localTotal;
    $hasDrift = $vm?->hasDrift ?? false;
    $censusCheckedAt = $vm?->censusCheckedAt;
    $provisionedTotal = $provisionedTotal ?? null;
    $schedulerLoad = $schedulerLoad ?? null;
    $capDisplay = ($server->max_accounts ?? 0) > 0 ? $server->max_accounts : 'Unlimited';
@endphp
<div class="row g-3" id="essentialPanels">
    <div class="col-6 col-lg-3">
        <div class="border rounded-3 p-3 h-100" style="border-radius:var(--radius-lg); background: var(--bs-body-bg);">
            <div class="text-muted small mb-1" style="font-size:var(--text-xs); letter-spacing:0.02em; text-transform:uppercase;">Version</div>
            <div class="fw-semibold" style="font-size:var(--text-sm);">{{ $version !== '' ? $version : '—' }}</div>
            {{-- hostname lives in Host card — not repeated here --}}
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="border rounded-3 p-3 h-100" style="border-radius:var(--radius-lg); background: var(--bs-body-bg);">
            <div class="text-muted small mb-1" style="font-size:var(--text-xs); letter-spacing:0.02em; text-transform:uppercase;">Host</div>
            <div class="fw-semibold text-truncate" style="font-size:var(--text-sm);">{{ $hostname ?: $server->ip_address }}</div>
            <div class="text-muted small" style="font-size:var(--text-xs);">{{ $server->ip_address }}</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="border rounded-3 p-3 h-100" style="border-radius:var(--radius-lg); background: var(--bs-body-bg);">
            <div class="text-muted small mb-1" style="font-size:var(--text-xs); letter-spacing:0.02em; text-transform:uppercase;">Census</div>
            <div class="d-flex justify-content-between gap-2" style="font-size:var(--text-xs);">
                <span class="text-muted">Remote</span>
                <span class="fw-semibold" data-census="remote">@if ($remoteTotal !== null){{ $remoteTotal }}@elseif (($server->server_type ?? null) === 'plesk')<span class="fw-normal text-muted">No remote data</span>@else—@endif</span>
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
            @if (is_array($vmCounts) && ($vm?->hasVmCounts ?? false))
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
    <div class="col-6 col-lg-3">
        <div class="border rounded-3 p-3 h-100" style="border-radius:var(--radius-lg); background: var(--bs-body-bg);">
            <div class="text-muted small mb-1" style="font-size:var(--text-xs); letter-spacing:0.02em; text-transform:uppercase;">Latency</div>
            <div class="fw-semibold" style="font-size:var(--text-sm);">{{ $latency !== null ? $latency.' ms' : '—' }}</div>
            <div class="text-muted small" style="font-size:var(--text-xs);">Measured on last test</div>
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
