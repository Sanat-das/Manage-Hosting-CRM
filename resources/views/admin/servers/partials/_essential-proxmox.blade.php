{{-- Proxmox VE narrow supplement: Cluster + Nodes + Storage + Provisioning templates.
     Transport strip lives in the Host card, stale/limited-data badges live in
     the Available card (both in _essential-panels) — single ownership each.
     Vars: $server, $vm (ServerDetailViewModel|null), $liveVms (listVms() rows|null).
     Dumb — all parsing in ProxmoxClient::essentialTelemetry(); bytes via
     ServerDetailViewModel::fmtBytes, uptimes via its fmtUptime humaniser.
     Missing remote renders "No remote data", never 0. --}}
@php
    $vm = $vm ?? null;
    // Cold loads read the flat persisted meta, fresh renders the nested DTO
    // meta — either level wins, mirroring the ViewModel's nested-first rule.
    $hvMeta = is_array($vm?->hv ?? null) ? $vm->hv : [];
    $topMeta = is_array($vm?->meta ?? null) ? $vm->meta : [];
    $clusterName = trim((string) ($hvMeta['clusterName'] ?? $topMeta['clusterName'] ?? ''));
    $clusterNodes = is_array($hvMeta['clusterNodes'] ?? null) ? $hvMeta['clusterNodes'] : (is_array($topMeta['clusterNodes'] ?? null) ? $topMeta['clusterNodes'] : []);
    $storagePools = is_array($hvMeta['storagePools'] ?? null) ? $hvMeta['storagePools'] : (is_array($topMeta['storagePools'] ?? null) ? $topMeta['storagePools'] : []);
    $quorate = $hvMeta['quorate'] ?? $topMeta['quorate'] ?? null;
    $nodeCountRaw = $hvMeta['node_count'] ?? $topMeta['node_count'] ?? null;
    $nodeCount = is_numeric($nodeCountRaw) ? (int) $nodeCountRaw : null;
    if ($nodeCount === null && $clusterNodes !== []) {
        $nodeCount = count($clusterNodes);
    }
    // Provisioning templates: curated list + default marker + not-on-cluster
    // marker per template, cross-checked against the live cluster inventory.
    $proxmoxTemplates = method_exists($server, 'proxmoxTemplates') ? $server->proxmoxTemplates() : [];
    $proxmoxDefault = method_exists($server, 'proxmoxDefaultTemplate') ? $server->proxmoxDefaultTemplate() : null;
    $liveVmIds = [];
    if (isset($liveVms) && is_array($liveVms)) {
        foreach ($liveVms as $lv) {
            if (! is_array($lv)) continue;
            $id = trim((string) ($lv['vmId'] ?? $lv['vmid'] ?? ''));
            if ($id !== '') $liveVmIds[] = $id;
        }
    }
    $hasLiveInventory = isset($liveVms) && is_array($liveVms);
    $showTemplates = ! empty($proxmoxTemplates);
@endphp

@if ($clusterName !== '' || $nodeCount !== null || $quorate !== null || $clusterNodes !== [] || $storagePools !== [] || $showTemplates)
<div class="row g-3 mt-1" id="essentialProxmoxSupplement">
    @if ($clusterName !== '' || $nodeCount !== null || $quorate !== null)
        <div class="col-md-4">
            <div class="border rounded-3 p-3 h-100" data-proxmox="cluster">
                <div class="text-muted small mb-1" style="font-size:var(--text-xs); text-transform:uppercase;">Cluster</div>
                <div class="fw-medium small">{{ $clusterName !== '' ? $clusterName : '—' }}</div>
                @if ($nodeCount !== null)<div class="text-muted small" style="font-size:var(--text-xs);">{{ $nodeCount }} {{ $nodeCount === 1 ? 'node' : 'nodes' }}</div>@endif
                @if ($quorate !== null)<div class="text-muted small" style="font-size:var(--text-xs);">Quorum {{ $quorate ? 'quorate' : 'not quorate' }}</div>@endif
            </div>
        </div>
    @endif
    @if ($clusterNodes !== [])
        <div class="col-md-4">
            <div class="border rounded-3 p-3 h-100" data-proxmox="nodes">
                <div class="text-muted small mb-1" style="font-size:var(--text-xs); text-transform:uppercase;">Nodes ({{ count($clusterNodes) }})</div>
                <div class="d-flex flex-wrap gap-1" id="proxmoxNodes">
                    @foreach ($clusterNodes as $i => $nd)
                        @php
                            $ndArr = is_array($nd) ? $nd : [];
                            $ndName = trim((string) ($ndArr['name'] ?? ''));
                            if ($ndName === '') continue;
                            $ndOnline = (bool) ($ndArr['online'] ?? false);
                            $ndIp = trim((string) ($ndArr['ip'] ?? ''));
                            $ndUptimeRaw = $ndArr['uptime'] ?? null;
                            $ndUptime = is_string($ndUptimeRaw) && trim($ndUptimeRaw) !== ''
                                ? (\App\ViewModels\Admin\ServerDetailViewModel::fmtUptime($ndUptimeRaw) ?? trim($ndUptimeRaw))
                                : '';
                        @endphp
                        <span class="badge text-bg-light border fw-normal proxmox-node {{ $i >= 8 ? 'd-none' : '' }}" style="font-size:var(--text-xs);" @if($ndIp !== '') title="{{ $ndIp }}" @endif><span class="d-inline-block rounded-circle me-1 align-middle {{ $ndOnline ? 'bg-success' : 'bg-secondary' }}" style="width:8px;height:8px;"></span>{{ $ndName }}@if($ndUptime !== '')<span class="text-muted"> · {{ $ndUptime }}</span>@endif</span>
                    @endforeach
                </div>
                @if (count($clusterNodes) > 8)
                    <button type="button" class="btn btn-link btn-sm p-0 mt-1" style="font-size:var(--text-xs);" onclick="var h=this.closest('div').querySelectorAll('.proxmox-node.d-none');var hidden=h.length>0;h.forEach(function(e){e.classList.toggle('d-none');});this.textContent=hidden?'Show less':'Show all ({{ count($clusterNodes) }})';">Show all ({{ count($clusterNodes) }})</button>
                @endif
            </div>
        </div>
    @endif
    @if ($storagePools !== [])
        <div class="col-md-4">
            <div class="border rounded-3 p-3 h-100" data-proxmox="storage">
                <div class="text-muted small mb-1" style="font-size:var(--text-xs); text-transform:uppercase;">Storage ({{ count($storagePools) }})</div>
                <div class="d-flex flex-column gap-1" id="proxmoxStorage">
                    @foreach ($storagePools as $i => $pool)
                        @php
                            $poolArr = is_array($pool) ? $pool : [];
                            $pName = trim((string) ($poolArr['name'] ?? ''));
                            if ($pName === '') continue;
                            $pTotal = (int) ($poolArr['total'] ?? 0);
                            $pAvail = (int) ($poolArr['avail'] ?? 0);
                            $pUsed = $pTotal > 0 ? max(0, $pTotal - $pAvail) : 0;
                            $pPct = $pTotal > 0 ? min(100, (int) round($pUsed / $pTotal * 100)) : null;
                            $pContent = trim((string) ($poolArr['content'] ?? ''));
                        @endphp
                        <div class="d-flex justify-content-between gap-2 proxmox-pool {{ $i >= 8 ? 'd-none' : '' }}" style="font-size:var(--text-xs);" @if($pContent !== '') title="Content: {{ $pContent }}" @endif>
                            <span class="text-muted">{{ $pName }}:</span>
                            <span class="fw-semibold">@if($pPct !== null){{ $pPct }}%<span class="fw-normal text-muted"> · </span>@endif<span class="fw-normal text-muted">{{ \App\ViewModels\Admin\ServerDetailViewModel::fmtBytes($pAvail) }} free</span>@if(empty($poolArr['active']))<span class="fw-normal text-muted"> · inactive</span>@endif</span>
                        </div>
                    @endforeach
                </div>
                @if (count($storagePools) > 8)
                    <button type="button" class="btn btn-link btn-sm p-0 mt-1" style="font-size:var(--text-xs);" onclick="var h=this.closest('div').querySelectorAll('.proxmox-pool.d-none');var hidden=h.length>0;h.forEach(function(e){e.classList.toggle('d-none');});this.textContent=hidden?'Show less':'Show all ({{ count($storagePools) }})';">Show all ({{ count($storagePools) }})</button>
                @endif
            </div>
        </div>
    @endif
    @if ($showTemplates)
        <div class="col-md-4">
            <div class="border rounded-3 p-3 h-100" data-proxmox="templates">
                <div class="text-muted small mb-1" style="font-size:var(--text-xs); text-transform:uppercase;">Provisioning {{ count($proxmoxTemplates) > 1 ? 'templates' : 'template' }}</div>
                <div class="d-flex flex-wrap gap-1">
                    @foreach ($proxmoxTemplates as $tmpl)
                        @php
                            $tmplArr = is_array($tmpl) ? $tmpl : [];
                            $tVmid = trim((string) ($tmplArr['vmid'] ?? ''));
                            if ($tVmid === '') continue;
                            $tLabel = trim((string) ($tmplArr['label'] ?? ''));
                            if ($tLabel === '') $tLabel = $tVmid;
                            $differs = $tLabel !== $tVmid;
                            $isMissing = $hasLiveInventory ? ! in_array($tVmid, $liveVmIds, true) : false;
                            $isDefault = $proxmoxDefault !== null && $tVmid === (string) $proxmoxDefault;
                        @endphp
                        <span class="badge {{ $isDefault ? 'text-bg-primary' : 'text-bg-info' }} fw-normal" style="font-size:var(--text-xs);" @if($differs) title="{{ $tVmid }}" @endif>Template VMID: {{ $tLabel }}@if($differs)<span class="fw-normal"> ({{ $tVmid }})</span>@endif{{ $isDefault ? ' (default)' : '' }}{{ $isMissing ? ' (not on cluster)' : '' }}</span>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
@endif
