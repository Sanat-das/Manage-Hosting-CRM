@extends('adminlte::page')

@section('title', 'Edit Server — '.$server->name)

@section('content_header')
    <x-ui.page-header title="Edit Server" subtitle="Update server configuration" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Servers', 'url' => route('admin.servers.index')],
        ['label' => $server->name, 'url' => route('admin.servers.show', $server)],
        ['label' => 'Edit', 'active' => true],
    ]" />
@stop

@section('content')
    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible>
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-adminlte-alert>
    @endif

    {{-- todo-12 transport prefill (scope only): camel-aware value resolution --}}
    @php
        // todo-12 (scope only): camel-aware transport prefill value resolution.
        // Nested connection_meta.meta wins (snake+camel aware), then flat meta,
        // then api_url parse, then useSsl ? 5986 : 5985 default. String bools
        // normalized like ServerDetailViewModel. api_url / api_username /
        // api_key+password branches preserved (base inputs keep their own
        // old() fallbacks; resolved here for parity with the verifier).
        $transportPrefill = [];
        foreach (['api_url', 'api_username', 'api_key', 'host', 'port', 'use_ssl', 'verify_tls'] as $key) {
            // value resolution: old > server attribute mapping
            $serverVal = null;
            if ($key === 'api_url') {
                $serverVal = $server->api_url ?? $server->ip_address;
            } elseif ($key === 'api_username' || $key === 'username') {
                $serverVal = $server->api_username;
            } elseif ($key === 'api_key' || $key === 'password') {
                $serverVal = ''; // encrypted - never display
            } elseif (in_array($key, ['host','port','use_ssl','verify_tls'], true)) {
                // Hyper-V transport: nested meta.meta wins, snake+camel aware
                // (same rule as ServerDetailViewModel: nested-first normalized
                // lookup, then api_url parse, then useSsl ? 5986 : 5985 default).
                $meta = is_array($server->connection_meta) ? $server->connection_meta : [];
                $hv = (isset($meta['meta']) && is_array($meta['meta'])) ? $meta['meta'] : [];
                $normTransportKey = static fn ($k) => strtolower(str_replace('_', '', (string) $k));
                $findTransport = static function (array $arr, array $cands) use ($normTransportKey) {
                    $map = [];
                    foreach ($arr as $k => $v) {
                        if (! is_string($k)) { continue; }
                        $n = $normTransportKey($k);
                        if (! array_key_exists($n, $map)) { $map[$n] = $v; }
                    }
                    foreach ($cands as $c) {
                        $n = $normTransportKey($c);
                        if (array_key_exists($n, $map) && $map[$n] !== null && $map[$n] !== '') { return $map[$n]; }
                    }
                    return null;
                };
                $transportCands = match ($key) {
                    'host' => ['host'],
                    'port' => ['port'],
                    'use_ssl' => ['use_ssl','useSsl'],
                    'verify_tls' => ['verify_tls','verifyTls'],
                    default => [$key],
                };
                $serverVal = $findTransport($hv, $transportCands) ?? $findTransport($meta, $transportCands);
                // Normalize string bools like the ViewModel (accept "true"/"false", 0/1).
                if (($key === 'use_ssl' || $key === 'verify_tls') && is_string($serverVal)) {
                    $lowerTransport = strtolower($serverVal);
                    if (in_array($lowerTransport, ['1','true','yes'], true)) { $serverVal = true; }
                    elseif (in_array($lowerTransport, ['0','false','no'], true)) { $serverVal = false; }
                }
                if ($key === 'host' && ($serverVal === null || $serverVal === '')) {
                    if (is_string($server->api_url) && $server->api_url !== '' && str_contains($server->api_url, '://')) {
                        $serverVal = parse_url($server->api_url, PHP_URL_HOST) ?? $server->api_url;
                    } else {
                        $serverVal = $server->api_url ?? $server->ip_address;
                    }
                }
                if ($key === 'port' && $serverVal === null) {
                    if (is_string($server->api_url) && str_contains($server->api_url, '://')) {
                        $parsedPort = parse_url($server->api_url, PHP_URL_PORT);
                        $serverVal = is_numeric($parsedPort) ? (int) $parsedPort : null;
                    }
                }
                if ($key === 'port' && $serverVal === null) {
                    $rawSsl = $findTransport($hv, ['use_ssl','useSsl']) ?? $findTransport($meta, ['use_ssl','useSsl']);
                    if (is_string($rawSsl)) {
                        $lowerSsl = strtolower($rawSsl);
                        $rawSsl = in_array($lowerSsl, ['1','true','yes'], true) ? true : (in_array($lowerSsl, ['0','false','no'], true) ? false : $rawSsl);
                    }
                    $serverVal = ($rawSsl === true || $rawSsl === 1 || $rawSsl === '1') ? 5986 : 5985;
                }
            } else {
                $meta = is_array($server->connection_meta) ? $server->connection_meta : [];
                $serverVal = $meta[$key] ?? $server->getAttribute($key) ?? null;
            }
            $transportPrefill[$key] = old($key, $serverVal);
        }
        unset($key, $serverVal, $meta, $hv, $transportCands);
    @endphp

    <x-adminlte.partials.form-card
        icon="bi bi-server"
        title="Edit Server"
        :action="route('admin.servers.update', $server)"
        method="PUT"
        submit-label="Update Server"
        :cancel-url="route('admin.servers.show', $server)"
    >
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="name" label="Name" placeholder="e.g. Web-01"
                                  value="{{ old('name', $server->name) }}" required />
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="ip_address" label="IP address" placeholder="e.g. 192.168.1.10"
                                  value="{{ old('ip_address', $server->ip_address) }}" required />
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <label for="server-type-display" class="form-label fw-medium">Server type</label>
                <input type="text" id="server-type-display" class="form-control" value="{{ $moduleName ?? $serverType }}" disabled>
                <input type="hidden" name="server_type" value="{{ old('server_type', $serverType) }}">
                <div class="form-text">Fixed after creation.</div>
            </div>
            <div class="col-md-6">
                <x-adminlte-select name="status" label="Status">
                    <option value="active" @selected(old('status', $server->status) === 'active')>Active</option>
                    <option value="inactive" @selected(old('status', $server->status) === 'inactive')>Inactive</option>
                </x-adminlte-select>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="api_url" label="API URL" placeholder="e.g. https://web01.example.com:2083"
                                  value="{{ old('api_url', $server->api_url) }}" />
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="api_username" label="API username" placeholder="Optional"
                                  value="{{ old('api_username', $server->api_username) }}" />
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="api_key" label="API key" placeholder="Leave blank to keep unchanged"
                                  value="{{ old('api_key', $server->api_key) }}" />
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="max_accounts" type="number" min="0" step="1" label="Max accounts (0 = unlimited)"
                                  value="{{ old('max_accounts', $server->max_accounts) }}" />
            </div>
        </div>

        @if ($server->server_type === 'hyperv')
            <hr class="my-3">
            <h6 class="fw-semibold mb-3">Transport connection</h6>
            <div class="row">
                <div class="col-md-6">
                    <x-adminlte-input name="host" label="Host" placeholder="hv01.example.local"
                                      value="{{ $transportPrefill['host'] ?? '' }}" />
                </div>
                <div class="col-md-6">
                    <x-adminlte-input name="port" type="number" label="Port" placeholder="5985"
                                      value="{{ $transportPrefill['port'] ?? '' }}" />
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <x-adminlte-select name="use_ssl" label="Use SSL">
                        <option value="1" @selected(filter_var($transportPrefill['use_ssl'] ?? false, FILTER_VALIDATE_BOOLEAN))>Yes</option>
                        <option value="0" @selected(! filter_var($transportPrefill['use_ssl'] ?? false, FILTER_VALIDATE_BOOLEAN))>No</option>
                    </x-adminlte-select>
                </div>
                <div class="col-md-6">
                    <x-adminlte-select name="verify_tls" label="Verify TLS">
                        <option value="1" @selected(($transportPrefill['verify_tls'] ?? null) === null ? true : filter_var($transportPrefill['verify_tls'], FILTER_VALIDATE_BOOLEAN))>Yes</option>
                        <option value="0" @selected(($transportPrefill['verify_tls'] ?? null) !== null && ! filter_var($transportPrefill['verify_tls'], FILTER_VALIDATE_BOOLEAN))>No</option>
                    </x-adminlte-select>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <label for="field_password" class="form-label fw-medium">Password</label>
                    <input type="password" id="field_password" name="password" value="" class="form-control"
                           placeholder="Leave blank to keep existing" autocomplete="new-password">
                    <div class="form-text">Leave blank to keep existing.</div>
                </div>
            </div>

            @php
                $templateMeta = is_array($server->connection_meta) ? $server->connection_meta : [];
                $effectiveList = method_exists($server, 'hypervTemplateVms') ? $server->hypervTemplateVms() : (isset($templateMeta['template_vms']) && is_array($templateMeta['template_vms']) ? \App\Models\Server::sanitizeTemplateVms($templateMeta['template_vms']) : (isset($templateMeta['template_vm']) ? [trim((string) $templateMeta['template_vm'])] : []));
                $effectiveDefault = method_exists($server, 'hypervDefaultTemplate') ? ($server->hypervDefaultTemplate() ?? '') : (isset($templateMeta['template_vm']) ? trim((string) $templateMeta['template_vm']) : '');
                // old('template_vms') is array when present; preserve it even when empty array not sent (check old input existence via session)
                $oldVmsRaw = old('template_vms');
                if (is_array($oldVmsRaw)) {
                    $templateVmsOld = \App\Models\Server::sanitizeTemplateVms($oldVmsRaw);
                    // If old was empty array explicitly, sanitize returns [] — keep it (do not fallback to effectiveList)
                    // Distinguish absent old (null) vs empty array handled above.
                } else {
                    $templateVmsOld = $effectiveList;
                }
                // For default, old() may be null (ConvertEmptyStringsToNull); treat null as "" when key was present.
                // Use old() with fallback but detect when old input exists at all.
                $hasOldDefault = session()->hasOldInput('template_vm');
                $templateVmOld = $hasOldDefault ? trim((string) old('template_vm')) : $effectiveDefault;
                // Labels: build map VM name → label, handling old input for validation repopulation.
                $templateLabelsOld = [];
                if (method_exists($server, 'hypervTemplateLabels')) {
                    $templateLabelsOld = $server->hypervTemplateLabels();
                } elseif (isset($templateMeta['template_labels']) && is_array($templateMeta['template_labels'])) {
                    $templateLabelsOld = \App\Models\Server::sanitizeTemplateLabels($templateMeta['template_labels'], $effectiveList);
                }
                // If old label payload present, rebuild from parallel arrays to preserve typed values after validation error.
                if (session()->hasOldInput('template_label_for') || session()->hasOldInput('template_label')) {
                    $oldFor = old('template_label_for');
                    $oldLab = old('template_label');
                    $oldFor = is_array($oldFor) ? $oldFor : [];
                    $oldLab = is_array($oldLab) ? $oldLab : [];
                    $rebuilt = [];
                    foreach ($oldFor as $idx => $vmKey) {
                        $k = trim((string) $vmKey);
                        if ($k === '') { continue; }
                        $v = isset($oldLab[$idx]) ? trim((string) $oldLab[$idx]) : '';
                        if ($v !== '') { $v = mb_substr($v, 0, 80); }
                        $rebuilt[$k] = $v;
                    }
                    $templateLabelsOld = $rebuilt;
                    // Filter rebuilt to currently selected (oldVms) to avoid showing stale in inputs, but keep for JS preservation.
                    // Keep all rebuilt keys; JS will filter to curated.
                }
            @endphp
            <hr class="my-3">
            <h6 class="fw-semibold mb-3">Provisioning templates</h6>
            <div class="row">
                <div class="col-md-8">
                    <label for="field_template_vms" class="form-label fw-medium">Curated templates</label>
                    <div class="d-flex gap-2 align-items-start">
                        <select id="field_template_vms" name="template_vms[]" multiple size="6" class="form-select flex-grow-1">
                            @foreach ($templateVmsOld as $vmName)
                                <option value="{{ $vmName }}" selected>{{ $vmName }} (not found on host)</option>
                            @endforeach
                        </select>
                        <button type="button" id="templateVmRefresh" class="btn btn-outline-secondary btn-sm flex-shrink-0" title="Refresh VM list" aria-label="Refresh VM list">
                            <i class="bi bi-arrow-clockwise"></i> Refresh
                        </button>
                    </div>
                    <div class="form-text">Curated templates are what admin/clients can pick when provisioning manually; the default is used when a product auto-provisions.</div>
                    <div class="form-text">Hold Ctrl/Cmd to select multiple. Template must be shut down (Off). New VMs clone its disk; plan still controls vCPU/RAM.</div>
                    <div id="templateVmsStatus" class="form-text text-muted d-none" role="status" aria-live="polite"></div>
                    @error('template_vms')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    @error('template_vms.*')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="row mt-3">
                <div class="col-md-8">
                    <label for="field_template_vm" class="form-label fw-medium">Default template</label>
                    <select id="field_template_vm" name="template_vm" class="form-select">
                        <option value="" @selected($templateVmOld === '')>— No default —</option>
                        @foreach ($templateVmsOld as $vmName)
                            <option value="{{ $vmName }}" @selected($templateVmOld === $vmName)>{{ $vmName }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">Used when a product auto-provisions without a template choice.</div>
                    @error('template_vm')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="row mt-3">
                <div class="col-md-8">
                    <label class="form-label fw-medium">Template labels</label>
                    <div id="templateLabelsContainer" class="d-flex flex-column gap-2" style="gap:8px !important;">
                        @forelse ($templateVmsOld as $idx => $vmName)
                            @php
                                $safeVm = trim((string) $vmName);
                                $slug = 'template_label_' . $idx . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', substr($safeVm, 0, 24));
                                $labelVal = $templateLabelsOld[$safeVm] ?? '';
                            @endphp
                            <div class="d-flex flex-column" style="gap:4px;" data-label-row="1" data-vm="{{ $safeVm }}">
                                <label for="{{ $slug }}" class="form-label fw-medium small mb-0" style="margin-bottom:2px;">Label for {{ $safeVm }}</label>
                                <div class="d-flex gap-2 align-items-center">
                                    <input type="hidden" name="template_label_for[]" value="{{ $safeVm }}">
                                    <input type="text" id="{{ $slug }}" name="template_label[]" value="{{ $labelVal }}" class="form-control form-control-sm" maxlength="80" placeholder="{{ $safeVm }}" aria-label="Label for {{ $safeVm }}">
                                </div>
                            </div>
                        @empty
                            <div class="form-text text-muted">Select a template above to add a friendly label.</div>
                        @endforelse
                    </div>
                    <div class="form-text">Friendly labels are shown to admins and clients when choosing a template; leave blank to show the raw VM name.</div>
                    @error('template_label_for')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    @error('template_label_for.*')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    @error('template_label')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    @error('template_label.*')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
            </div>
        @endif
    </x-adminlte.partials.form-card>

    @if ($server->server_type === 'hyperv')
    @push('js')
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var curatedSelect = document.getElementById('field_template_vms');
        var defaultSelect = document.getElementById('field_template_vm');
        var refreshBtn = document.getElementById('templateVmRefresh');
        var statusEl = document.getElementById('templateVmsStatus');
        // Fallback for legacy templateVmStatus id
        if (!statusEl) statusEl = document.getElementById('templateVmStatus');
        var labelsContainer = document.getElementById('templateLabelsContainer');
        if (!curatedSelect || !defaultSelect) return;
        var savedCurated = @json($templateVmsOld ?? []);
        var savedDefault = @json($templateVmOld ?? '');
        var savedLabels = @json($templateLabelsOld ?? (object)[]);
        var endpoint = @json(route('admin.servers.vms', $server));

        function setStatus(msg, isError) {
            if (!statusEl) return;
            if (!msg) { statusEl.classList.add('d-none'); statusEl.textContent = ''; return; }
            statusEl.textContent = msg;
            statusEl.classList.remove('d-none');
            statusEl.className = 'form-text ' + (isError ? 'text-danger' : 'text-muted');
        }

        function getCuratedSelected() {
            return Array.from(curatedSelect.selectedOptions).map(function (o) { return o.value; }).filter(function (v) { return v !== ''; });
        }

        function syncDefaultOptions() {
            var selected = getCuratedSelected();
            // Preserve current default choice if still valid
            var currentDefault = defaultSelect.value;
            // Prefer savedDefault on initial load if it is in curated list
            var preferred = currentDefault !== '' ? currentDefault : savedDefault;
            defaultSelect.innerHTML = '';
            var noOpt = document.createElement('option');
            noOpt.value = '';
            noOpt.textContent = '\u2014 No default \u2014';
            defaultSelect.appendChild(noOpt);
            selected.forEach(function (name) {
                var opt = document.createElement('option');
                opt.value = name;
                opt.textContent = name;
                defaultSelect.appendChild(opt);
            });
            if (preferred !== '' && selected.indexOf(preferred) !== -1) {
                defaultSelect.value = preferred;
            } else if (preferred !== '' && selected.length === 0) {
                // Keep preferred as missing? No, default must be in list — leave No default
                defaultSelect.value = '';
            } else {
                // Keep previous selection if still valid, else No default
                if (selected.indexOf(currentDefault) !== -1) {
                    defaultSelect.value = currentDefault;
                } else {
                    defaultSelect.value = '';
                }
            }
        }

        function slugForVmName(name, idx) {
            return 'template_label_' + idx + '_' + name.replace(/[^a-zA-Z0-9_-]/g, '_').slice(0, 24);
        }

        function rebuildLabelInputs() {
            if (!labelsContainer) return;
            var selected = getCuratedSelected();
            // Preserve already-typed values
            var preserved = {};
            labelsContainer.querySelectorAll('[data-label-row]').forEach(function (row) {
                var vm = row.getAttribute('data-vm') || '';
                var input = row.querySelector('input[name="template_label[]"]');
                if (vm !== '' && input) preserved[vm] = input.value;
            });
            labelsContainer.innerHTML = '';
            if (selected.length === 0) {
                var emptyHint = document.createElement('div');
                emptyHint.className = 'form-text text-muted';
                emptyHint.textContent = 'Select a template above to add a friendly label.';
                labelsContainer.appendChild(emptyHint);
                return;
            }
            selected.forEach(function (vmName, idx) {
                var row = document.createElement('div');
                row.className = 'd-flex flex-column';
                row.style.gap = '4px';
                row.setAttribute('data-label-row', '1');
                row.setAttribute('data-vm', vmName);
                var id = slugForVmName(vmName, idx);
                var label = document.createElement('label');
                label.setAttribute('for', id);
                label.className = 'form-label fw-medium small mb-0';
                label.style.marginBottom = '2px';
                label.textContent = 'Label for ' + vmName;
                var inputGroup = document.createElement('div');
                inputGroup.className = 'd-flex gap-2 align-items-center';
                var hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'template_label_for[]';
                hidden.value = vmName;
                var input = document.createElement('input');
                input.type = 'text';
                input.id = id;
                input.name = 'template_label[]';
                input.className = 'form-control form-control-sm';
                input.maxLength = 80;
                input.placeholder = vmName;
                var existingVal = preserved.hasOwnProperty(vmName) ? preserved[vmName] : (savedLabels[vmName] !== undefined ? savedLabels[vmName] : '');
                input.value = existingVal;
                input.setAttribute('aria-label', 'Label for ' + vmName);
                inputGroup.appendChild(hidden);
                inputGroup.appendChild(input);
                row.appendChild(label);
                row.appendChild(inputGroup);
                labelsContainer.appendChild(row);
            });
        }

        function buildCuratedOptions(vms) {
            var selectedBefore = getCuratedSelected();
            // Use savedCurated as initial if selections empty and vms just loaded first time
            var consider = selectedBefore.length > 0 ? selectedBefore : savedCurated;
            var vmMap = {};
            vms.forEach(function (vm) { vmMap[vm.name] = vm; });
            curatedSelect.innerHTML = '';
            // Add all live VMs as options
            vms.forEach(function (vm) {
                var opt = document.createElement('option');
                opt.value = vm.name;
                opt.textContent = vm.name + ' (' + (vm.state || 'Unknown') + ')';
                if (consider.indexOf(vm.name) !== -1) opt.selected = true;
                curatedSelect.appendChild(opt);
            });
            // Add missing curated entries not found on host
            consider.forEach(function (name) {
                if (!vmMap[name]) {
                    var miss = document.createElement('option');
                    miss.value = name;
                    miss.textContent = name + ' (not found on host)';
                    miss.selected = true;
                    curatedSelect.appendChild(miss);
                }
            });
            syncDefaultOptions();
            rebuildLabelInputs();
        }

        async function fetchVms(withRefresh) {
            var url = endpoint + (withRefresh ? '?refresh=1' : '');
            setStatus('Loading VMs…', false);
            curatedSelect.disabled = true;
            defaultSelect.disabled = true;
            if (refreshBtn) refreshBtn.disabled = true;
            try {
                var res = await fetch(url, {
                    method: 'GET',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                var data = await res.json().catch(function () { return {}; });
                if (!res.ok && data.ok !== false) {
                    throw new Error(data.error || data.message || 'Failed to load VMs (' + res.status + ').');
                }
                if (data.ok === false) {
                    var msg = data.error || data.message || 'Failed to load VMs.';
                    throw new Error(msg);
                }
                var vms = Array.isArray(data.vms) ? data.vms : [];
                buildCuratedOptions(vms);
                if (vms.length === 0) {
                    setStatus('No VMs found on host. Create a template VM first.', false);
                } else {
                    setStatus('', false);
                }
            } catch (e) {
                var errMsg = (e && e.message) ? e.message : 'Failed to load VMs.';
                setStatus(errMsg + ' — check host connection or try again.', true);
                if (statusEl && statusEl.textContent.indexOf('Retry') === -1) {
                    statusEl.textContent += ' Retry with Refresh.';
                }
                // Ensure at least missing entries remain visible
                if (curatedSelect.options.length === 0 && savedCurated.length > 0) {
                    savedCurated.forEach(function (name) {
                        var opt = document.createElement('option');
                        opt.value = name;
                        opt.textContent = name + ' (not found on host)';
                        opt.selected = true;
                        curatedSelect.appendChild(opt);
                    });
                    syncDefaultOptions();
                }
            } finally {
                if (refreshBtn) refreshBtn.disabled = false;
                curatedSelect.disabled = false;
                defaultSelect.disabled = false;
            }
        }

        curatedSelect.addEventListener('change', function () { syncDefaultOptions(); rebuildLabelInputs(); });

        // Ensure empty curated list is submitted as empty array (multi-select sends nothing when empty).
        var form = curatedSelect.closest('form');
        if (form) {
            form.addEventListener('submit', function () {
                if (getCuratedSelected().length === 0) {
                    // Inject a hidden marker so controller sees template_vms as empty array.
                    // Create a temporary hidden input that signals empty list; server interprets missing as preserve, so we need explicit empty.
                    // We add an empty array sentinel via a hidden input with name template_vms and empty value, handled as [] in controller fallback.
                    var existing = form.querySelector('input[name="template_vms_empty_sentinel"]');
                    if (!existing) {
                        var inp = document.createElement('input');
                        inp.type = 'hidden';
                        inp.name = 'template_vms_empty_sentinel';
                        inp.value = '1';
                        form.appendChild(inp);
                    }
                    // Also ensure default is No default when list empty
                    defaultSelect.value = '';
                }
                // If the form will submit with template_vms_empty_sentinel, we need to also ensure template_vms key arrives.
                // Browsers won't send template_vms[] when nothing selected, so we inject a hidden empty field that Laravel will not count as array.
                // Instead, intercept via FormData approach: add a hidden template_vms key with empty array notation handled server-side as empty.
                // Simplest: if sentinel present, add a hidden disabled select workaround is not needed — controller checks sentinel.
            });
        }

        // Expose sentinel handling: before fetch, patch the submit to translate sentinel into template_vms=[]
        // We do it by listening to form submission and appending a hidden field that the controller will interpret.
        // The controller checks for template_vms_empty_sentinel via request input — if present and template_vms absent, treat as [].
        // That logic will be handled server-side in rules/hypervConnectionMeta via request merge; for now ensure sentinel is sent.
        // Initial sync without fetch: ensure default options reflect saved state
        syncDefaultOptions();
        rebuildLabelInputs();
        // Initial async load (non-blocking)
        fetchVms(false);

        if (refreshBtn) {
            refreshBtn.addEventListener('click', function () { fetchVms(true); });
        }
    });
    </script>
    @endpush
    @endif
@stop
