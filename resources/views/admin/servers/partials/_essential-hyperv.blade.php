{{-- Hyper-V HostOS / Compute / Switches / Storage row.
    Vars: $server, $vm (ServerDetailViewModel|null). Dumb — all parsing in ViewModel. --}}
@php
    $vm = $vm ?? null;
    $hostOS = $vm?->hostOS;
    $hypervVersion = $vm?->hypervVersion;
    $logicalCpu = $vm?->logicalCpu;
    if ($logicalCpu !== null && ((int) $logicalCpu) <= 0) $logicalCpu = null;
    $ramTotal = $vm?->ramTotal;
    $ramFree = $vm?->ramFree;
    if ($ramTotal !== null && ((int) $ramTotal) <= 0) $ramTotal = null;
    if ($ramFree !== null && ((int) $ramFree) <= 0) $ramFree = null;
    $ramUsed = $vm?->ramUsed;
    $ramPct = $vm?->ramPct;
    $vmCounts = $vm?->vmCounts;
    $hasVmCounts = $vm?->hasVmCounts ?? false;
    $isStale = $vm?->isStale ?? false;
    $transportHost = $vm?->transportHost;
    $transportPort = $vm?->transportPort;
    $metaUseSsl = $vm?->metaUseSsl;
    $metaVerifyTls = $vm?->metaVerifyTls;
    $switchList = $vm?->switchList ?? [];
    $switchCount = $vm?->switchCount ?? count($switchList);
    $storageTotal = $vm?->storageTotal;
    $storageFree = $vm?->storageFree;
    $storageUsed = $vm?->storageUsed;
    $storagePct = $vm?->storagePct;
    $volumes = $vm?->volumes;
    $uptimeDisplay = $vm?->uptimeDisplay;
    $bootTime = $vm?->bootTime;
    $cpuLoadPercent = $vm?->cpuLoadPercent;
    $osBuild = $vm?->osBuild;
@endphp
<div class="d-flex flex-wrap align-items-center gap-2 mt-3 px-3 py-2 border rounded-3 small" style="border-radius:var(--radius-lg); background: var(--bs-tertiary-bg);">
    <span class="text-muted" style="font-size:var(--text-xs); text-transform:uppercase;">Transport</span>
    <code style="font-size:var(--text-xs);">{{ $transportHost }}:{{ $transportPort }}</code>
    @if ($metaUseSsl !== null)
        @if ($metaUseSsl)
            <span class="badge text-bg-success" style="font-size:var(--text-xs);">SSL</span>
        @else
            <span class="badge text-bg-secondary" style="font-size:var(--text-xs);">No SSL</span>
        @endif
    @endif
    @if ($metaVerifyTls !== null)
        @if ($metaVerifyTls)
            <span class="badge text-bg-info" style="font-size:var(--text-xs);">Verify TLS on</span>
        @else
            <span class="badge text-bg-warning" style="font-size:var(--text-xs);">Verify TLS off</span>
        @endif
    @endif
    @if ($server->api_url)
        <span class="text-muted text-truncate" style="font-size:var(--text-xs); max-width: 420px;">{{ $server->api_url }}</span>
    @endif
</div>

@if ($isStale)
    <div class="alert alert-warning py-2 px-3 mt-2 mb-0 small">
        <i class="bi bi-clock-history me-1"></i> Data is over 24 hours old — re-test to refresh.
    </div>
@endif
@if (! $hasVmCounts)
    <div class="alert alert-warning py-2 px-3 mt-2 mb-0 small">
        <i class="bi bi-exclamation-triangle me-1"></i> Limited data — re-test to load VM counts.
    </div>
@endif

<div class="row g-3 mt-1">
    @if ($hostOS || $hypervVersion || $osBuild || $uptimeDisplay || $bootTime)
        <div class="col-md-4">
            <div class="border rounded-3 p-3 h-100">
                <div class="text-muted small mb-1" style="font-size:var(--text-xs); text-transform:uppercase;">Host OS</div>
                <div class="fw-medium small">{{ $hostOS ?? '—' }}</div>
                @if ($osBuild)<div class="text-muted small" style="font-size:var(--text-xs);">Build {{ $osBuild }}</div>@endif
                @if ($hypervVersion)<div class="text-muted small" style="font-size:var(--text-xs);">Hyper-V {{ $hypervVersion }}</div>@endif
                @if ($uptimeDisplay)<div class="small mt-1">Uptime: <strong>{{ $uptimeDisplay }}</strong></div>@endif
                @if ($bootTime)<div class="text-muted small" style="font-size:var(--text-xs);">Booted {{ $bootTime }}</div>@endif
            </div>
        </div>
    @endif
    @if ($logicalCpu !== null || $ramTotal !== null || $cpuLoadPercent !== null)
        <div class="col-md-4">
            <div class="border rounded-3 p-3 h-100">
                <div class="text-muted small mb-1" style="font-size:var(--text-xs); text-transform:uppercase;">Compute</div>
                <div class="small">
                    @if ($logicalCpu !== null)<div>Logical CPUs: <strong>{{ $logicalCpu }}</strong>@if ($cpuLoadPercent !== null)<span class="text-muted"> · load {{ $cpuLoadPercent }}%</span>@endif</div>@endif
                    @if ($ramTotal !== null)
                        <div class="mt-1">RAM: <strong>{{ \App\ViewModels\Admin\ServerDetailViewModel::fmtBytes($ramTotal) }}</strong>
                            @if ($ramFree !== null)<span class="text-muted"> · free {{ \App\ViewModels\Admin\ServerDetailViewModel::fmtBytes($ramFree) }}</span>@endif
                        </div>
                        @if ($ramPct !== null)
                            <div class="progress mt-2" style="height:6px;" role="progressbar" aria-valuenow="{{ $ramPct }}" aria-valuemin="0" aria-valuemax="100" title="RAM used {{ $ramPct }}%">
                                <div class="progress-bar {{ $ramPct >= 90 ? 'bg-danger' : ($ramPct >= 75 ? 'bg-warning' : 'bg-success') }}" style="width:{{ $ramPct }}%;"></div>
                            </div>
                            <div class="text-muted mt-1" style="font-size:var(--text-xs);">{{ \App\ViewModels\Admin\ServerDetailViewModel::fmtBytes($ramUsed) }} used · {{ $ramPct }}%</div>
                        @endif
                    @elseif ($cpuLoadPercent !== null)
                        <div>CPU load: <strong>{{ $cpuLoadPercent }}%</strong></div>
                    @endif
                </div>
            </div>
        </div>
    @endif
    @if (!empty($switchList))
        <div class="col-md-4">
            <div class="border rounded-3 p-3 h-100">
                <div class="text-muted small mb-1" style="font-size:var(--text-xs); text-transform:uppercase;">Switches ({{ $switchCount }})</div>
                <div class="d-flex flex-wrap gap-1" id="hvSwitches">
                    @foreach ($switchList as $i => $sw)
                        <span class="badge text-bg-light border fw-normal hv-switch {{ $i >= 8 ? 'd-none' : '' }}" style="font-size:var(--text-xs);" @if(!empty($sw['type'])) title="{{ $sw['type'] }}" @endif>{{ $sw['name'] }}@if(!empty($sw['type']))<span class="text-muted"> · {{ $sw['type'] }}</span>@endif</span>
                    @endforeach
                </div>
                @if ($switchCount > 8)
                    <button type="button" class="btn btn-link btn-sm p-0 mt-1" style="font-size:var(--text-xs);" onclick="var h=this.closest('div').querySelectorAll('.hv-switch.d-none');var hidden=h.length>0;h.forEach(function(e){e.classList.toggle('d-none');});this.textContent=hidden?'Show less':'Show all ({{ $switchCount }})';">Show all ({{ $switchCount }})</button>
                @endif
            </div>
        </div>
    @endif
    @php $storageHasTotal = is_numeric($storageTotal) && $storageTotal > 0; @endphp
    @if ($storageFree !== null || $storageHasTotal)
        <div class="col-md-4">
            <div class="border rounded-3 p-3 h-100">
                <div class="text-muted small mb-1" style="font-size:var(--text-xs); text-transform:uppercase;">Storage</div>
                @if ($storageHasTotal)
                    <div class="small"><strong>{{ is_numeric($storageUsed) ? \App\ViewModels\Admin\ServerDetailViewModel::fmtBytes($storageUsed) : $storageUsed }}</strong><span class="text-muted"> used of {{ \App\ViewModels\Admin\ServerDetailViewModel::fmtBytes($storageTotal) }}</span></div>
                    @if ($storagePct !== null)
                        <div class="progress mt-2" style="height:6px;" role="progressbar" aria-valuenow="{{ $storagePct }}" aria-valuemin="0" aria-valuemax="100" title="Storage used {{ $storagePct }}%">
                            <div class="progress-bar {{ $storagePct >= 90 ? 'bg-danger' : ($storagePct >= 75 ? 'bg-warning' : 'bg-success') }}" style="width:{{ $storagePct }}%;"></div>
                        </div>
                        <div class="text-muted mt-1" style="font-size:var(--text-xs);">{{ $storagePct }}% used · {{ is_numeric($storageFree) ? \App\ViewModels\Admin\ServerDetailViewModel::fmtBytes($storageFree) : $storageFree }} free</div>
                    @else
                        <div class="fw-medium small mt-1">{{ is_numeric($storageFree) ? \App\ViewModels\Admin\ServerDetailViewModel::fmtBytes($storageFree) : $storageFree }} free</div>
                    @endif
                @else
                    <div class="fw-medium small">{{ is_numeric($storageFree) ? \App\ViewModels\Admin\ServerDetailViewModel::fmtBytes($storageFree) : $storageFree }} free</div>
                @endif
                @if (is_array($volumes) && count($volumes) > 0)
                    <div class="mt-2 d-flex flex-column gap-1">
                        @foreach (array_slice(array_values($volumes), 0, 6) as $vol)
                            @php
                                $vName = is_array($vol) ? ($vol['name'] ?? $vol['Name'] ?? '?') : '?';
                                $vTotal = is_array($vol) ? ($vol['total'] ?? $vol['Total'] ?? null) : null;
                                $vUsed = is_array($vol) ? ($vol['used'] ?? $vol['Used'] ?? null) : null;
                                $vFree = is_array($vol) ? ($vol['free'] ?? $vol['Free'] ?? null) : null;
                                $vPct = (is_numeric($vTotal) && $vTotal > 0 && is_numeric($vUsed)) ? min(100, round($vUsed / $vTotal * 100)) : null;
                            @endphp
                            <div class="text-muted" style="font-size:var(--text-xs);">{{ $vName }}: @if($vPct !== null)<strong class="text-body">{{ $vPct }}%</strong> · @endif{{ is_numeric($vFree) ? \App\ViewModels\Admin\ServerDetailViewModel::fmtBytes($vFree).' free' : '' }}@if(is_numeric($vTotal))<span> of {{ \App\ViewModels\Admin\ServerDetailViewModel::fmtBytes($vTotal) }}</span>@endif</div>
                        @endforeach
                        @if (count($volumes) > 6)
                            <div class="text-muted" style="font-size:var(--text-xs);">+{{ count($volumes) - 6 }} more volume(s)</div>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
