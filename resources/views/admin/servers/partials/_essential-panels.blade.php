{{-- Essential 4-card grid: Version / Host / Accounts-VMs / Latency + re-test skeleton.
    Vars: $server, $vm (ServerDetailViewModel|null), plus optional
    injectable $hostname / $totalAccounts overrides (ViewModel is single reader). --}}
@php
    $vm = $vm ?? null;
    $hostname = $vm?->hostname ?? ($hostname ?? $server->ip_address ?? null);
    $version = $vm?->version ?? ($version ?? '');
    $latency = $vm?->latency ?? ($latency ?? null);
    $vmCounts = $vm?->vmCounts ?? ($vmCounts ?? null);
    $totalAccounts = $vm?->totalAccounts ?? ($totalAccounts ?? $server->hostingAccounts->count());
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
            <div class="text-muted small mb-1" style="font-size:var(--text-xs); letter-spacing:0.02em; text-transform:uppercase;">Accounts / VMs</div>
            <div class="fw-semibold" style="font-size:var(--text-lg);">{{ is_array($vmCounts) ? ($vmCounts['total'] ?? $vmCounts['running'] ?? $totalAccounts) : $totalAccounts }}</div>
            <div class="text-muted small" style="font-size:var(--text-xs);">
                @if (is_array($vmCounts))
                    {{ $vmCounts['running'] ?? 0 }} running · {{ $vmCounts['stopped'] ?? 0 }} stopped
                    @if (isset($vmCounts['saved']) && $vmCounts['saved'] > 0) · {{ $vmCounts['saved'] }} saved @endif
                @else
                    on this server
                @endif
            </div>
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
