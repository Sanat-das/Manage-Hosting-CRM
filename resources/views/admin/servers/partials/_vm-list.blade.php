{{-- Merged provisioned-VM + live host inventory table (one row per provisioned VM).
     Vars: $server, $vm (ServerDetailViewModel|null), $vmInventory (array|null, controller-built),
     $panelAccounts (paginator|collection — kept for the vms_page pagination links). --}}
@php
    $vm = $vm ?? null;
    $panelAccounts = $panelAccounts ?? collect();
    $inventory = (isset($vmInventory) && is_array($vmInventory)) ? $vmInventory : null;

    $rows = (is_array($inventory['rows'] ?? null)) ? array_values($inventory['rows']) : null;
    $unmatchedLive = (is_array($inventory['unmatchedLive'] ?? null)) ? array_values($inventory['unmatchedLive']) : [];
    $liveTotal = $inventory['liveTotal'] ?? null;
    $liveUnavailable = (bool) ($inventory['liveUnavailable'] ?? false);

    // Transitional fallback until ServerController passes $vmInventory: map legacy
    // PanelAccount models to the display shape WITHOUT any live correlation
    // (hostMatch=false, live fields null). No external_id ↔ vmId matching here.
    if ($rows === null) {
        $fallbackList = $panelAccounts instanceof \Illuminate\Contracts\Pagination\Paginator
            ? collect($panelAccounts->items())
            : collect($panelAccounts);
        $rows = $fallbackList->map(function ($builtVm) {
            $serviceInstance = $builtVm->serviceInstance ?? null;
            $order = $serviceInstance?->order ?? null;
            $customer = $serviceInstance?->customer ?? null;
            $builtAt = $builtVm->provisioned_at ?? $builtVm->created_at ?? null;
            return [
                'vmName' => null,
                'username' => $builtVm->username ?? null,
                'externalId' => $builtVm->external_id ?? null,
                'externalIdShort' => null,
                'customerName' => $customer?->full_name ?? $customer?->user?->email ?? null,
                'orderNumber' => $order?->order_number ?? null,
                'orderStatus' => $order?->status ?? null,
                'orderUrl' => $order ? route('admin.orders.show', $order) : null,
                'provisionStatus' => $builtVm->status ?? null,
                'liveState' => null,
                'liveStateTheme' => null,
                'liveUptime' => null,
                'liveCpu' => null,
                'liveVcpu' => null,
                'liveMemoryAssigned' => null,
                'liveMemoryDemand' => null,
                'hostMatch' => false,
                'builtAtHuman' => $builtAt ? $builtAt->format('Y-m-d H:i') : null,
                'builtAtAbsolute' => $builtAt ? $builtAt->toDateTimeString() : null,
            ];
        })->all();
    }

    // listVms() MemoryAssigned/MemoryDemand are BYTES (Get-VM). Formatted
    // inline via the single source of truth ServerDetailViewModel::fmtBytes.

    // Live themes arrive pre-computed; allowlist so a host-derived value can never
    // leak into a class attribute. Same visual language as the old live table.
    $liveThemes = ['success', 'secondary', 'warning', 'info'];
@endphp
@if($liveUnavailable)
    <p class="text-muted small mb-2"><i class="bi bi-cloud-slash me-1"></i>Live inventory unavailable — re-test to load host data.</p>
@endif
<div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
        <caption class="visually-hidden">Provisioned virtual machines correlated with live host state</caption>
        <thead>
            <tr>
                <th>VM</th><th>VMId</th><th>Customer / Order</th><th>Provisioning</th><th>Live state</th><th>Uptime</th><th>CPU / RAM</th><th>Built at</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                @php
                    $r = is_array($row) ? $row : (array) $row;
                    $rowVmName = $r['vmName'] ?? null;
                    $rowUsername = $r['username'] ?? null;
                    $rowLabel = $rowVmName ?: ($rowUsername ?: '—');
                    $rowExternalId = $r['externalId'] ?? null;
                    $rowExternalIdShort = $r['externalIdShort'] ?? ($rowExternalId ? mb_substr((string) $rowExternalId, 0, 8) : null);
                    if ($rowExternalIdShort === '—') $rowExternalIdShort = null;
                    $rowHostMatch = (bool) ($r['hostMatch'] ?? false);
                    $rowHostPresence = $r['hostPresence'] ?? ($rowHostMatch ? 'matched' : 'missing');
                    $rowAbsentLabel = $r['absentLabel'] ?? 'not on host';
                    $rowTheme = strtolower((string) ($r['liveStateTheme'] ?? ''));
                    $rowTheme = in_array($rowTheme, $liveThemes, true) ? $rowTheme : 'info';
                    $rowCpu = $r['liveCpu'] ?? null;
                    $rowVcpu = $r['liveVcpu'] ?? null;
                    $rowMem = $r['liveMemoryAssigned'] ?? null;
                    $rowProvision = $r['provisionStatus'] ?? null;
                    $rowOrderUrl = $r['orderUrl'] ?? null;
                    $rowBuiltHuman = $r['builtAtHuman'] ?? null;
                    $rowBuiltAbsolute = $r['builtAtAbsolute'] ?? null;
                @endphp
                <tr>
                    <td>
                        <strong>{{ $rowLabel }}</strong>
                        @if($rowUsername && $rowVmName && $rowUsername !== $rowVmName)
                            <div class="text-muted small">Panel username: {{ $rowUsername }}</div>
                        @endif
                    </td>
                    <td>
                        @if($rowExternalIdShort)
                            <span class="font-monospace small text-nowrap" title="{{ $rowExternalId ?? $rowExternalIdShort }}">{{ $rowExternalIdShort }}</span>@if($rowExternalId)<button type="button" class="btn btn-sm btn-link p-0 ms-1 copy-btn" data-copy="{{ $rowExternalId }}" aria-label="Copy full VMId" title="Copy full VMId"><i class="bi bi-clipboard"></i></button>@endif
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td class="small">
                        <div class="text-break">{{ $r['customerName'] ?? '—' }}</div>
                        @if($rowOrderUrl)
                            <a href="{{ $rowOrderUrl }}" class="text-muted small text-nowrap">{{ $r['orderNumber'] ?? '—' }} · {{ $r['orderStatus'] ?? '—' }}</a>
                        @endif
                    </td>
                    <td>
                        @if($rowProvision !== null && $rowProvision !== '')
                            <x-adminlte.partials.status-badge :status="$rowProvision" />
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td>
                        @if($rowHostMatch)
                            <span class="badge text-bg-{{ $rowTheme }}" style="font-size:var(--text-xs);">{{ $r['liveState'] ?? '—' }}</span>
                        @elseif($rowHostPresence === 'absent')
                            <span class="badge text-bg-light border text-muted fw-normal">{{ $rowAbsentLabel }}</span>
                        @else
                            <span class="badge text-bg-warning" style="font-size:var(--text-xs);"><i class="bi bi-exclamation-triangle me-1"></i>not found on host</span>
                        @endif
                    </td>
                    <td class="text-muted small text-nowrap">{{ $rowHostMatch ? ($r['liveUptime'] ?: '—') : '—' }}</td>
                    <td class="small">
                        @if($rowHostMatch)
                            <span class="text-nowrap">{{ ($rowCpu !== null && $rowCpu !== '') ? $rowCpu.'%' : '—' }}@if($rowVcpu !== null && $rowVcpu !== '')<span class="text-muted"> · {{ $rowVcpu }} vCPU</span>@endif</span>
                            <div class="text-muted">{{ ($rowMem !== null && $rowMem !== '') ? \App\ViewModels\Admin\ServerDetailViewModel::fmtBytes($rowMem) : '—' }}</div>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td class="text-muted small text-nowrap"@if($rowBuiltAbsolute) title="{{ $rowBuiltAbsolute }}"@endif>{{ $rowBuiltHuman ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-3">No VMs built on this hypervisor yet. Order a <strong>Windows VPS - Hyper-V</strong> product to provision one.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@if($panelAccounts instanceof \Illuminate\Contracts\Pagination\Paginator)
    <div class="mt-3">{{ $panelAccounts->appends(request()->query())->links() }}</div>
@endif
@if(! $liveUnavailable)
    @if(count($unmatchedLive) > 0)
        <details class="mt-3">
            <summary class="small text-muted" style="cursor:pointer; font-size:var(--text-sm);">On host, not provisioned ({{ count($unmatchedLive) }})</summary>
            <div class="table-responsive mt-2">
                <table class="table table-sm align-middle mb-0">
                    <caption class="visually-hidden">Host virtual machines with no matching provisioned record</caption>
                    <thead>
                        <tr>
                            <th>Name</th><th>VMId</th><th>State</th><th>Uptime</th><th>CPU</th><th>Memory</th><th>Switch</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($unmatchedLive as $live)
                            @php
                                $lv = is_array($live) ? $live : (array) $live;
                                $lvTheme = strtolower((string) ($lv['stateTheme'] ?? ''));
                                $lvTheme = in_array($lvTheme, $liveThemes, true) ? $lvTheme : 'info';
                                $lvVmId = $lv['vmId'] ?? null;
                                $lvVmIdShort = $lv['vmIdShort'] ?? ($lvVmId ? mb_substr((string) $lvVmId, 0, 8) : null);
                                if ($lvVmIdShort === '—') $lvVmIdShort = null;
                                $lvCpu = $lv['cpuUsage'] ?? null;
                                $lvVcpu = $lv['processorCount'] ?? null;
                                $lvMemAssigned = $lv['memoryAssigned'] ?? null;
                                $lvMemDemand = $lv['memoryDemand'] ?? null;
                            @endphp
                            <tr>
                                <td><strong>{{ $lv['name'] ?? '—' }}</strong></td>
                                <td>
                                    @if($lvVmIdShort)
                                        <span class="font-monospace small text-nowrap" title="{{ $lvVmId ?? $lvVmIdShort }}">{{ $lvVmIdShort }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td><span class="badge text-bg-{{ $lvTheme }}" style="font-size:var(--text-xs);">{{ $lv['state'] ?? '—' }}</span></td>
                                <td class="text-muted small text-nowrap">{{ $lv['uptime'] ?? '—' }}</td>
                                <td class="small text-nowrap">{{ ($lvCpu !== null && $lvCpu !== '') ? $lvCpu.'%' : '—' }}@if($lvVcpu !== null && $lvVcpu !== '')<span class="text-muted"> · {{ $lvVcpu }} vCPU</span>@endif</td>
                                <td class="small text-nowrap">{{ ($lvMemAssigned !== null && $lvMemAssigned !== '') ? \App\ViewModels\Admin\ServerDetailViewModel::fmtBytes($lvMemAssigned) : '—' }}@if($lvMemDemand !== null && $lvMemDemand !== '' && $lvMemDemand != $lvMemAssigned)<span class="text-muted"> · demand {{ \App\ViewModels\Admin\ServerDetailViewModel::fmtBytes($lvMemDemand) }}</span>@endif</td>
                                <td class="text-muted small">{{ $lv['switchName'] ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @elseif($liveTotal !== null)
        <p class="text-muted small mb-0 mt-3">All {{ $liveTotal }} host VMs are matched to provisioned records.</p>
    @endif
@endif
