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

    @php
        $schemaFields = $schema['fields'] ?? $schema ?? [];
        if (isset($schemaFields['fields'])) { $schemaFields = $schemaFields['fields']; }
        $hasSchema = is_array($schemaFields) && count($schemaFields) > 0;
        $groupOptions = $groups ?? collect();
        $typeLabel = $moduleName ?? ($server->display_type ?? $server->server_type ?? $server->panel_type);
        $typeSlug = $server->server_type ?? $server->panel_type ?? '';
    @endphp

    {{-- Type badge disabled --}}
    <x-adminlte-card class="mb-3">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="text-muted small">Server type</span>
                <span class="badge text-bg-secondary rounded-pill" style="font-size:var(--text-xs); font-weight:500; padding:0.35em 0.7em;">
                    <i class="bi bi-lock me-1"></i> {{ $typeLabel }} <span class="opacity-75">· {{ $typeSlug }}</span>
                </span>
                <span class="text-muted small">Type is locked — it cannot be changed after creation.</span>
            </div>
            <span class="badge text-bg-light border fw-normal" style="font-size:var(--text-xs);">
                <span class="d-inline-flex align-items-center gap-1">
                    <span class="d-inline-block rounded-circle" style="width:8px;height:8px; background: var(--bs-{{ $server->connection_status === 'connected' ? 'success' : ($server->connection_status === 'failed' ? 'danger' : 'secondary') }});"></span>
                    {{ ucfirst($server->connection_status ?? 'untested') }}
                </span>
            </span>
        </div>
    </x-adminlte-card>

    <form method="POST" action="{{ route('admin.servers.update', $server) }}" id="serverEditForm">
        @csrf
        @method('PUT')

        <x-adminlte-card icon="bi bi-server" title="Edit Server — {{ $server->name }}">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" value="{{ old('name', $server->name) }}" class="form-control @error('name') is-invalid @enderror" placeholder="e.g. Web-01" required>
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">IP address <span class="text-danger">*</span></label>
                    <input type="text" name="ip_address" value="{{ old('ip_address', $server->ip_address) }}" class="form-control @error('ip_address') is-invalid @enderror" placeholder="e.g. 192.168.1.10" required>
                    @error('ip_address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="row g-3 mt-1">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Max accounts <span class="text-muted fw-normal">(0 = unlimited)</span></label>
                    <input type="number" name="max_accounts" value="{{ old('max_accounts', $server->max_accounts) }}" min="0" step="1" class="form-control @error('max_accounts') is-invalid @enderror">
                    @error('max_accounts')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Status <span class="text-danger">*</span></label>
                    <select name="status" class="form-select @error('status') is-invalid @enderror">
                        <option value="active" @selected(old('status', $server->status) === 'active')>Active</option>
                        <option value="inactive" @selected(old('status', $server->status) === 'inactive')>Inactive</option>
                    </select>
                    @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="row g-3 mt-1">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Server Group <span class="text-muted fw-normal">(optional)</span></label>
                    @php
                        $currentGroupIds = isset($selectedGroupId) && $selectedGroupId !== null && $selectedGroupId !== ''
                            ? [(string) $selectedGroupId]
                            : (isset($selectedGroupIds) ? array_map('strval', (array) $selectedGroupIds) : (($server->groupMembers->pluck('server_group_id')->map(fn ($v) => (string) $v)->all() ?? [])));
                    @endphp
                    <select name="server_group_id" class="form-select @error('server_group_id') is-invalid @enderror">
                        <option value="">— No group —</option>
                        @foreach ($groupOptions as $group)
                            <option value="{{ $group->id }}" @selected(in_array((string) $group->id, array_map('strval', $currentGroupIds), true) || (string) old('server_group_id') === (string) $group->id)>{{ $group->name }}</option>
                        @endforeach
                    </select>
                    @if ($groupOptions->isEmpty())
                        <div class="form-text">No groups for type <code>{{ $typeSlug }}</code>.</div>
                    @else
                        <div class="form-text">Only groups with type <code>{{ $typeSlug }}</code> are shown.</div>
                    @endif
                    @error('server_group_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            @if ($hasSchema)
                <hr class="my-4">
                <h6 class="fw-semibold mb-3" style="font-size:var(--text-sm); letter-spacing:var(--tracking-tight);">
                    <i class="bi bi-gear me-1"></i> {{ $typeLabel }} connection
                </h6>
                <div class="row g-3">
                    @foreach ($schemaFields as $field)
                        @php
                            $key = $field['key'] ?? '';
                            $label = $field['label'] ?? Str::title(str_replace('_',' ', $key));
                            $type = $field['type'] ?? 'text';
                            $required = (bool) ($field['required'] ?? false);
                            // Edit: do not require password - leave blank to keep existing
                            if ($type === 'password') { $required = false; }
                            $help = $field['help'] ?? $field['description'] ?? '';
                            $options = $field['options'] ?? [];
                            $isCheckbox = $type === 'checkbox';
                            $fieldId = 'field_'.$key;
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
                            $value = old($key, $serverVal);
                            if ($isCheckbox) {
                                if ($value === null && isset($field['default'])) $value = $field['default'];
                            }
                            $placeholder = $type === 'password' ? 'Leave blank to keep existing' : '';
                            if ($placeholder === '' && $key === 'api_url') $placeholder = 'https://whm.example.net:2087';
                            if ($placeholder === '' && $key === 'host') $placeholder = 'hv01.example.local';
                        @endphp
                        @if ($isCheckbox)
                            <div class="col-md-6">
                                <div class="form-check mt-3">
                                    <input type="hidden" name="{{ $key }}" value="0">
                                    <input type="checkbox" class="form-check-input" id="{{ $fieldId }}" name="{{ $key }}" value="1" @checked((string) $value === '1' || $value === true || $value === 1)>
                                    <label class="form-check-label fw-medium" for="{{ $fieldId }}">{{ $label }}</label>
                                    @if ($help)<div class="form-text">{{ $help }}</div>@endif
                                </div>
                            </div>
                        @elseif ($type === 'select' && !empty($options))
                            <div class="col-md-6">
                                <label for="{{ $fieldId }}" class="form-label fw-medium">{{ $label }}</label>
                                <select id="{{ $fieldId }}" name="{{ $key }}" class="form-select @error($key) is-invalid @enderror">
                                    <option value="">— Select —</option>
                                    @foreach ($options as $optVal => $optLabel)
                                        <option value="{{ $optVal }}" @selected((string) $value === (string) $optVal)>{{ $optLabel }}</option>
                                    @endforeach
                                </select>
                                @if ($help)<div class="form-text">{{ $help }}</div>@endif
                                @error($key)<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            </div>
                        @elseif ($type === 'password')
                            <div class="col-md-6">
                                <label for="{{ $fieldId }}" class="form-label fw-medium">{{ $label }}</label>
                                <input type="password" id="{{ $fieldId }}" name="{{ $key }}" value="" class="form-control @error($key) is-invalid @enderror" placeholder="Leave blank to keep existing" autocomplete="new-password">
                                @if ($help)<div class="form-text">{{ $help }}</div>@else<div class="form-text">Leave blank to keep existing.</div>@endif
                                @error($key)<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        @elseif ($type === 'number')
                            <div class="col-md-6">
                                <label for="{{ $fieldId }}" class="form-label fw-medium">{{ $label }}</label>
                                <input type="number" id="{{ $fieldId }}" name="{{ $key }}" value="{{ $value }}" class="form-control @error($key) is-invalid @enderror" placeholder="{{ $placeholder }}">
                                @if ($help)<div class="form-text">{{ $help }}</div>@endif
                                @error($key)<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        @else
                            <div class="col-md-6">
                                <label for="{{ $fieldId }}" class="form-label fw-medium">{{ $label }}</label>
                                <input type="text" id="{{ $fieldId }}" name="{{ $key }}" value="{{ $value }}" class="form-control @error($key) is-invalid @enderror" placeholder="{{ $placeholder }}">
                                @if ($help)<div class="form-text">{{ $help }}</div>@endif
                                @error($key)<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif

            @if(($typeSlug ?? '') === 'hyperv')
                @include('admin.servers.partials._winrm-guide', ['serverType' => $typeSlug, 'server' => $server])
            @endif

            <div class="mt-4">
                <div id="connectionPreview" class="d-none">
                    <div class="alert mb-0" role="alert" id="connectionAlert"></div>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2 mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i> Update Server
                </button>
                <button type="button" class="btn btn-outline-secondary" id="testConnectionBtn">
                    <span class="btn-label"><i class="bi bi-wifi me-1"></i> Test Connection</span>
                    <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                </button>
                <a href="{{ route('admin.servers.show', $server) }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </x-adminlte-card>
    </form>
@stop

@push('js')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('serverEditForm');
    const btn = document.getElementById('testConnectionBtn');
    const preview = document.getElementById('connectionPreview');
    const alertBox = document.getElementById('connectionAlert');
    if (!form || !btn) return;
    const url = @json(route('admin.servers.test-connection', $server));
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || @json(csrf_token());

    function setAlert(ok, message, latency) {
        preview.classList.remove('d-none');
        alertBox.className = 'alert mb-0 ' + (ok ? 'alert-success' : 'alert-danger');
        const latencyText = (latency !== undefined && latency !== null) ? ' · ' + latency + ' ms' : '';
        alertBox.innerHTML = '<div class="d-flex align-items-start gap-2">'
            + '<i class="bi ' + (ok ? 'bi-check-circle' : 'bi-exclamation-triangle') + ' mt-1"></i>'
            + '<div><strong>' + (ok ? 'Connected' : 'Failed') + '</strong> — ' + (message || (ok ? 'Connection succeeded.' : 'Connection failed.')) + '<span class="text-muted small">' + latencyText + '</span></div>'
            + '</div>';
        if (window.toastr) {
            if (ok) toastr.success(message || 'Connection succeeded.');
            else toastr.error(message || 'Connection failed.');
        }
    }

    btn.addEventListener('click', async function () {
        const spinner = btn.querySelector('.spinner-border');
        const label = btn.querySelector('.btn-label');
        btn.disabled = true;
        if (spinner) spinner.classList.remove('d-none');
        if (label) label.classList.add('opacity-50');
        preview.classList.add('d-none');

        const formData = new FormData(form);
        const payload = {};
        for (const [k, v] of formData.entries()) {
            payload[k] = v;
        }

        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                setAlert(false, data.message || data.error || 'Test failed (' + res.status + ').');
                return;
            }
            const ok = !!data.ok;
            setAlert(ok, data.message || data.error || (ok ? 'Connection succeeded.' : 'Connection failed.'), data.latencyMs ?? data.latency_ms ?? data.latency);
        } catch (e) {
            setAlert(false, (e && e.message) ? e.message : 'Network error during test.');
        } finally {
            btn.disabled = false;
            if (spinner) spinner.classList.add('d-none');
            if (label) label.classList.remove('opacity-50');
        }
    });
});
</script>
@endpush
