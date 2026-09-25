@extends('adminlte::page')

@section('title', 'Add Server')

@section('content_header')
    <x-ui.page-header title="Add Server" :subtitle="isset($serverType) ? 'Configure '.($moduleName ?? $serverType) : 'Provision a new server'" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Servers', 'url' => route('admin.servers.index')],
        ['label' => 'Choose Type', 'url' => route('admin.servers.create-type')],
        ['label' => 'Add Server', 'active' => true],
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
    @endphp

    {{-- Selected type badge --}}
    @if (isset($serverType) && $serverType)
        <x-adminlte-card class="mb-3">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2">
                    <span class="text-muted small">Server type</span>
                    <span class="badge text-bg-primary rounded-pill" style="font-size:var(--text-xs); font-weight:500; padding:0.35em 0.7em;">
                        {{ $moduleName ?? $serverType }} <span class="opacity-75">· {{ $serverType }}</span>
                    </span>
                    <span class="text-muted small">Type cannot be changed after creation.</span>
                </div>
                <a href="{{ route('admin.servers.create-type') }}" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-repeat me-1"></i> Change
                </a>
            </div>
        </x-adminlte-card>
    @endif

    @if (! isset($serverType) || ! $serverType)
        <x-adminlte-alert theme="warning">
            No server type selected. <a href="{{ route('admin.servers.create-type') }}" class="alert-link">Choose a server type</a> to continue.
        </x-adminlte-alert>
    @else
    <form method="POST" action="{{ route('admin.servers.store') }}" id="serverCreateForm" class="needs-validation" novalidate>
        @csrf
        <input type="hidden" name="server_type" value="{{ $serverType }}">

        <x-adminlte-card icon="bi bi-server" title="New Server — {{ $moduleName ?? $serverType }}">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" value="{{ old('name') }}" class="form-control @error('name') is-invalid @enderror" placeholder="e.g. Web-01" required>
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                @php $isHyperV = ($serverType ?? '') === 'hyperv'; @endphp
                <div class="col-md-6">
                    <label class="form-label fw-medium">IP address @if(!$isHyperV)<span class="text-danger">*</span>@endif</label>
                    <input type="text" name="ip_address" value="{{ old('ip_address') }}" class="form-control @error('ip_address') is-invalid @enderror" placeholder="e.g. 192.168.1.10" @if(!$isHyperV) required @endif>
                    @if($isHyperV)<div class="form-text">For Hyper-V you can fill either this or Host below.</div>@endif
                    @error('ip_address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="row g-3 mt-1">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Max accounts <span class="text-muted fw-normal">(0 = unlimited)</span></label>
                    <input type="number" name="max_accounts" value="{{ old('max_accounts', 0) }}" min="0" step="1" class="form-control @error('max_accounts') is-invalid @enderror">
                    @error('max_accounts')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Status <span class="text-danger">*</span></label>
                    <select name="status" class="form-select @error('status') is-invalid @enderror">
                        <option value="active" @selected(old('status', 'active') === 'active')>Active</option>
                        <option value="inactive" @selected(old('status') === 'inactive')>Inactive</option>
                    </select>
                    @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            {{-- Server Group filtered to same allowed_server_type --}}
            <div class="row g-3 mt-1">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Server Group <span class="text-muted fw-normal">(optional)</span></label>
                    <select name="server_group_id" class="form-select @error('server_group_id') is-invalid @enderror">
                        <option value="">— No group —</option>
                        @foreach ($groupOptions as $group)
                            <option value="{{ $group->id }}" @selected((string) old('server_group_id') === (string) $group->id)>{{ $group->name }}</option>
                        @endforeach
                    </select>
                    @if ($groupOptions->isEmpty())
                        <div class="form-text">No groups for type <code>{{ $serverType }}</code>. Create one in Server Groups with allowed type <code>{{ $serverType }}</code>.</div>
                    @else
                        <div class="form-text">Only groups with type <code>{{ $serverType }}</code> are shown.</div>
                    @endif
                    @error('server_group_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            {{-- Type-specific fields from serverConfigSchema --}}
            @if ($hasSchema)
                <hr class="my-4">
                <h6 class="fw-semibold mb-3" style="font-size:var(--text-sm); letter-spacing:var(--tracking-tight);">
                    <i class="bi bi-gear me-1"></i> {{ $moduleName ?? $serverType }} connection
                </h6>
                <div class="row g-3">
                    @foreach ($schemaFields as $field)
                        @php
                            $key = $field['key'] ?? '';
                            $label = $field['label'] ?? Str::title(str_replace('_',' ', $key));
                            $type = $field['type'] ?? 'text';
                            $required = (bool) ($field['required'] ?? false);
                            $default = $field['default'] ?? '';
                            $help = $field['help'] ?? $field['description'] ?? '';
                            $encrypted = (bool) ($field['encrypted'] ?? false);
                            $options = $field['options'] ?? [];
                            $value = old($key, $default);
                            // checkbox old handling
                            $isCheckbox = $type === 'checkbox';
                            $fieldId = 'field_'.$key;
                            $placeholder = match($key) {
                                'api_url' => 'https://whm.example.net:2087',
                                'host' => 'e.g. hv01.example.local or 192.168.1.50',
                                'port' => '5985',
                                'username' => $serverType === 'hyperv' ? 'DOMAIN\\Administrator' : 'root',
                                'password', 'api_key' => '',
                                'api_username' => 'root',
                                default => $help ?: '',
                            };
                        @endphp
                        @if ($isCheckbox)
                            <div class="col-md-6">
                                <div class="form-check mt-3">
                                    <input type="hidden" name="{{ $key }}" value="0">
                                    <input type="checkbox" class="form-check-input" id="{{ $fieldId }}" name="{{ $key }}" value="1" @checked((string) $value === '1' || $value === true || $value === 1)>
                                    <label class="form-check-label fw-medium" for="{{ $fieldId }}">
                                        {{ $label }}
                                        @if ($required)<span class="text-danger">*</span>@endif
                                    </label>
                                    @if ($help)<div class="form-text">{{ $help }}</div>@endif
                                </div>
                            </div>
                        @elseif ($type === 'select' && !empty($options))
                            <div class="col-md-6">
                                <label for="{{ $fieldId }}" class="form-label fw-medium">
                                    {{ $label }} @if ($required)<span class="text-danger">*</span>@endif
                                </label>
                                <select id="{{ $fieldId }}" name="{{ $key }}" class="form-select @error($key) is-invalid @enderror" @if($required) required @endif>
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
                                <label for="{{ $fieldId }}" class="form-label fw-medium">
                                    {{ $label }} @if ($required)<span class="text-danger">*</span>@endif
                                    @if ($encrypted)<span class="badge text-bg-light border fw-normal ms-1" style="font-size:var(--text-xs);">encrypted</span>@endif
                                </label>
                                <input type="password" id="{{ $fieldId }}" name="{{ $key }}" value="{{ old($key) }}" class="form-control @error($key) is-invalid @enderror" placeholder="••••••••" @if($required) required @endif autocomplete="new-password">
                                @if ($help)<div class="form-text">{{ $help }}</div>@endif
                                @error($key)<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        @elseif ($type === 'number')
                            <div class="col-md-6">
                                <label for="{{ $fieldId }}" class="form-label fw-medium">
                                    {{ $label }} @if ($required)<span class="text-danger">*</span>@endif
                                </label>
                                <input type="number" id="{{ $fieldId }}" name="{{ $key }}" value="{{ $value }}" class="form-control @error($key) is-invalid @enderror" placeholder="{{ $placeholder }}" @if($required) required @endif>
                                @if ($help)<div class="form-text">{{ $help }}</div>@endif
                                @error($key)<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        @else
                            <div class="col-md-6">
                                <label for="{{ $fieldId }}" class="form-label fw-medium">
                                    {{ $label }} @if ($required)<span class="text-danger">*</span>@endif
                                </label>
                                <input type="{{ $type === 'password' ? 'password' : 'text' }}" id="{{ $fieldId }}" name="{{ $key }}" value="{{ $value }}" class="form-control @error($key) is-invalid @enderror" placeholder="{{ $placeholder }}" @if($required) required @endif>
                                @if ($help)<div class="form-text">{{ $help }}</div>@endif
                                @error($key)<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif

            {{-- Config help: WinRM guide for hyperv --}}
            @if(($serverType ?? '') === 'hyperv')
                @include('admin.servers.partials._winrm-guide')
                <div class="form-text mt-2">After saving, you can choose a provisioning template VM on the Edit page.</div>
            @endif

            {{-- Connection preview area + actions --}}
            <div class="mt-4">
                <div id="connectionPreview" class="d-none">
                    <div class="alert mb-0" role="alert" id="connectionAlert"></div>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2 mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i> Save Server
                </button>
                <button type="button" class="btn btn-outline-secondary" id="testConnectionBtn">
                    <span class="btn-label"><i class="bi bi-wifi me-1"></i> Test Connection</span>
                    <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                </button>
                <a href="{{ route('admin.servers.index') }}" class="btn btn-outline-secondary">Cancel</a>
                <span class="text-muted small align-self-center ms-2" id="testConnectionHint">Non-blocking — you can save even if the test fails.</span>
            </div>
        </x-adminlte-card>
    </form>
    @endif
@stop

@push('js')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('serverCreateForm');
    const btn = document.getElementById('testConnectionBtn');
    const preview = document.getElementById('connectionPreview');
    const alertBox = document.getElementById('connectionAlert');
    if (!form || !btn) return;
    const url = @json(route('admin.servers.test-connection-dry'));
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || @json(csrf_token());

    function setAlert(ok, message, latency) {
        preview.classList.remove('d-none');
        alertBox.className = 'alert mb-0 ' + (ok ? 'alert-success' : 'alert-danger');
        const latencyText = (latency !== undefined && latency !== null) ? ' · ' + latency + ' ms' : '';
        alertBox.innerHTML = '<div class="d-flex align-items-start gap-2">'
            + '<i class="bi ' + (ok ? 'bi-check-circle' : 'bi-exclamation-triangle') + ' mt-1"></i>'
            + '<div><strong>' + (ok ? 'Connected' : 'Failed') + '</strong> — ' + (message || (ok ? 'Connection succeeded.' : 'Connection failed.')) + '<span class="text-muted small">' + latencyText + '</span></div>'
            + '</div>';
        // toast as well via AdminLTE if available
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
        // Include unchecked checkboxes as 0 already via hidden inputs
        const payload = {};
        for (const [k, v] of formData.entries()) {
            // FormData will have duplicate keys for checkbox hidden+checkbox; last wins
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
