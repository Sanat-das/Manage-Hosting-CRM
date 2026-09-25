{{-- Hyper-V narrow supplement (todo 8): Host OS + switches only.
     Transport strip lives in the Host card, stale/limited-data badges live in
     the Available card (both in _essential-panels) — single ownership each.
     Vars: $server, $vm (ServerDetailViewModel|null). Dumb — all parsing in ViewModel.
     Also shows provisioning template VM badge when configured. --}}
@php
    $vm = $vm ?? null;
    $hostOS = $vm?->hostOS;
    $hypervVersion = $vm?->hypervVersion;
    $version = $vm?->version ?? '';
    $osBuild = $vm?->osBuild;
    $switchList = $vm?->switchList ?? [];
    $switchCount = $vm?->switchCount ?? count($switchList);
    // Never repeat the Host card version line: show Host OS / Hyper-V lines
    // only when they carry a fact the version line does not already show.
    $showHostOS = is_string($hostOS) && trim($hostOS) !== '' && trim($hostOS) !== trim((string) $version);
    $showHypervVersion = is_string($hypervVersion) && trim($hypervVersion) !== ''
        && trim($hypervVersion) !== trim((string) $version)
        && (! $showHostOS || trim($hypervVersion) !== trim((string) $hostOS));
    // Provisioning templates badges: curated list + default marker + missing marker per template.
    $templateVms = [];
    $defaultTemplate = null;
    $templateOptions = [];
    if (method_exists($server, 'hypervTemplateVms')) {
        $templateVms = $server->hypervTemplateVms();
        $defaultTemplate = $server->hypervDefaultTemplate();
        if (method_exists($server, 'hypervTemplateOptions')) {
            $templateOptions = $server->hypervTemplateOptions();
        } else {
            $labelsMap = is_array($server->connection_meta['template_labels'] ?? null) ? \App\Models\Server::sanitizeTemplateLabels($server->connection_meta['template_labels'], $templateVms) : [];
            foreach ($templateVms as $n) {
                $templateOptions[] = ['name' => $n, 'label' => $labelsMap[$n] ?? $n];
            }
        }
    } else {
        $templateVmMeta = is_array($server->connection_meta ?? null) ? $server->connection_meta : [];
        if (isset($templateVmMeta['template_vms']) && is_array($templateVmMeta['template_vms'])) {
            $templateVms = \App\Models\Server::sanitizeTemplateVms($templateVmMeta['template_vms']);
        } elseif (isset($templateVmMeta['template_vm']) && trim((string) $templateVmMeta['template_vm']) !== '') {
            $templateVms = [trim((string) $templateVmMeta['template_vm'])];
        }
        $defaultTemplate = isset($templateVmMeta['template_vm']) ? trim((string) $templateVmMeta['template_vm']) : null;
        if ($defaultTemplate !== null && $defaultTemplate !== '' && ! in_array($defaultTemplate, $templateVms, true)) {
            $defaultTemplate = null;
        }
        if ($defaultTemplate === '') $defaultTemplate = null;
        $labelsMap = isset($templateVmMeta['template_labels']) && is_array($templateVmMeta['template_labels']) ? \App\Models\Server::sanitizeTemplateLabels($templateVmMeta['template_labels'], $templateVms) : [];
        foreach ($templateVms as $n) {
            $templateOptions[] = ['name' => $n, 'label' => $labelsMap[$n] ?? $n];
        }
    }
    $liveNames = [];
    if (isset($liveVms) && is_array($liveVms)) {
        foreach ($liveVms as $lv) {
            if (! is_array($lv)) continue;
            $n = trim((string) ($lv['name'] ?? $lv['Name'] ?? ''));
            if ($n !== '') $liveNames[] = $n;
        }
    }
    $showTemplateBadge = ! empty($templateVms);
@endphp

@if ($showHostOS || $showHypervVersion || $osBuild || !empty($switchList) || $showTemplateBadge)
<div class="row g-3 mt-1" id="essentialHypervSupplement">
    @if ($showHostOS || $showHypervVersion || $osBuild)
        <div class="col-md-4">
            <div class="border rounded-3 p-3 h-100">
                <div class="text-muted small mb-1" style="font-size:var(--text-xs); text-transform:uppercase;">Host OS</div>
                @if ($showHostOS)<div class="fw-medium small">{{ $hostOS }}</div>@endif
                @if ($osBuild)<div class="text-muted small" style="font-size:var(--text-xs);">Build {{ $osBuild }}</div>@endif
                @if ($showHypervVersion)<div class="text-muted small" style="font-size:var(--text-xs);">Hyper-V {{ $hypervVersion }}</div>@endif
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
    @if ($showTemplateBadge)
        <div class="col-md-4">
            <div class="border rounded-3 p-3 h-100" data-template-badge>
                <div class="text-muted small mb-1" style="font-size:var(--text-xs); text-transform:uppercase;">Provisioning {{ count($templateVms) > 1 ? 'templates' : 'template' }}</div>
                <div class="d-flex flex-wrap gap-1">
                    @foreach ($templateOptions as $opt)
                        @php
                            $tmpl = $opt['name'];
                            $tmplLabel = $opt['label'];
                            $differs = $tmplLabel !== $tmpl;
                            $isMissing = isset($liveVms) && is_array($liveVms) ? ! in_array($tmpl, $liveNames, true) : false;
                            $isDefault = $defaultTemplate !== null && $tmpl === $defaultTemplate;
                        @endphp
                        <span class="badge {{ $isDefault ? 'text-bg-primary' : 'text-bg-info' }} fw-normal" style="font-size:var(--text-xs);" @if($differs) title="{{ $tmpl }}" @endif>Template VM: {{ $tmplLabel }}@if($differs)<span class="text-muted fw-normal" style="font-weight:400 !important;"> ({{ $tmpl }})</span>@endif{{ $isDefault ? ' (default)' : '' }}{{ $isMissing ? ' (missing on host)' : '' }}</span>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
@endif
