@extends('adminlte::page')

@section('title', 'Edit Server Group — '.$serverGroup->name)

@section('content_header')
    <x-ui.page-header title="Edit Server Group" subtitle="Update server group configuration" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Server Groups', 'url' => route('admin.server-groups.index')],
        ['label' => $serverGroup->name, 'active' => true],
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

    <x-adminlte.partials.form-card
        icon="bi bi-collection"
        title="Edit Server Group"
        :action="route('admin.server-groups.update', $serverGroup)"
        method="PUT"
        submit-label="Update Server Group"
        :cancel-url="route('admin.server-groups.index')"
    >
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="name" label="Name" placeholder="e.g. Web Cluster A"
                                  value="{{ old('name', $serverGroup->name) }}" required />
            </div>
            <div class="col-md-4">
                <x-adminlte-select name="load_balancing" label="Load balancing">
                    @foreach (['round_robin' => 'Round Robin', 'least_loaded' => 'Least Loaded', 'failover' => 'Failover'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('load_balancing', $serverGroup->load_balancing) === $value)>{{ $label }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-2">
                <x-adminlte-select name="status" label="Status">
                    <option value="active" @selected(old('status', $serverGroup->status) === 'active')>Active</option>
                    <option value="inactive" @selected(old('status', $serverGroup->status) === 'inactive')>Inactive</option>
                </x-adminlte-select>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <label for="allowed_server_type" class="form-label">Server type <span class="text-muted">(Any = mixed)</span></label>
                <select name="allowed_server_type" id="allowed_server_type" class="form-select" data-filter-members>
                    <option value="">Any (no restriction)</option>
                    @foreach ($serverTypeOptions as $opt)
                        <option value="{{ $opt['value'] }}" @selected(old('allowed_server_type', $serverGroup->allowed_server_type) === $opt['value'])>
                            {{ $opt['label'] }} — {{ $opt['group'] }}
                        </option>
                    @endforeach
                </select>
                @error('allowed_server_type')<div class="text-danger small">{{ $message }}</div>@enderror
                <div class="form-text">When set, only servers of this type can be members. Changing the type is blocked while mismatched members exist — remove them first or set to Any.</div>
            </div>
            <div class="col-md-6">
                <x-adminlte-textarea name="description" label="Description" rows="2"
                                     placeholder="Optional description">{{ old('description', $serverGroup->description) }}</x-adminlte-textarea>
            </div>
        </div>

        @php
            $selected = old('server_ids', $selectedServerIds);
            if (!is_array($selected)) $selected = [];
            $currentType = old('allowed_server_type', $serverGroup->allowed_server_type);
        @endphp

        <x-adminlte-card title="Member servers" icon="bi bi-server" class="mt-3" body-class="p-3">
            <div id="members-hint" class="alert alert-info py-2 small d-none"></div>
            @forelse ($servers as $server)
                @php $mismatch = $currentType && $currentType !== '' && ($server->server_type ?? '') !== $currentType; @endphp
                <div class="form-check member-row" data-server-type="{{ $server->server_type ?? '' }}">
                    <input class="form-check-input" type="checkbox" name="server_ids[]"
                           value="{{ $server->id }}" id="server-{{ $server->id }}"
                           @checked(in_array($server->id, $selected))>
                    <label class="form-check-label" for="server-{{ $server->id }}">
                        {{ $server->name }} ({{ $server->ip_address }})
                        <span class="badge {{ $mismatch ? 'bg-warning text-dark' : 'bg-secondary' }}">{{ $server->server_type ?? 'unknown' }}</span>
                        <x-adminlte.partials.status-badge :status="$server->status" />
                        @if ($mismatch && in_array($server->id, $selected))
                            <span class="text-warning small ms-1">(mismatched — will be rejected on save)</span>
                        @endif
                    </label>
                </div>
            @empty
                <p class="text-muted mb-0">No servers registered yet.</p>
            @endforelse
        </x-adminlte-card>
    </x-adminlte.partials.form-card>
@stop

@push('js')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const typeSelect = document.querySelector('[data-filter-members]');
    const rows = document.querySelectorAll('.member-row');
    const hint = document.getElementById('members-hint');
    function applyFilter() {
        const wanted = typeSelect ? typeSelect.value : '';
        let hidden = 0, visible = 0;
        rows.forEach(function (row) {
            const st = row.getAttribute('data-server-type') || '';
            const cb = row.querySelector('input[type=checkbox]');
            const show = !wanted || st === wanted || (cb && cb.checked);
            // Keep already-checked mismatched rows visible but flagged; hide unchecked mismatched
            if (!wanted || st === wanted) {
                row.style.display = '';
                row.style.opacity = '1';
                visible++;
            } else {
                if (cb && cb.checked) {
                    row.style.display = '';
                    row.style.opacity = '0.6';
                    visible++;
                } else {
                    row.style.display = 'none';
                    hidden++;
                }
            }
        });
        if (hint) {
            if (wanted && hidden > 0) {
                hint.textContent = hidden + ' server(s) hidden — only "' + wanted + '" servers can be added. Deselect mismatched members before changing the group type.';
                hint.classList.remove('d-none');
            } else if (wanted && visible === 0) {
                hint.textContent = 'No servers of type "' + wanted + '" exist yet.';
                hint.classList.remove('d-none');
            } else {
                hint.classList.add('d-none');
            }
        }
    }
    if (typeSelect) {
        typeSelect.addEventListener('change', applyFilter);
        applyFilter();
    }
});
</script>
@endpush
