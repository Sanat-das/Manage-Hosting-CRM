@extends('adminlte::page')

@section('title', 'Add Server Group')

@section('content_header')
    <x-ui.page-header title="Add Server Group" subtitle="Provision a new server group" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Server Groups', 'url' => route('admin.server-groups.index')],
        ['label' => 'Add Server Group', 'active' => true],
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
        title="New Server Group"
        :action="route('admin.server-groups.store')"
        submit-label="Save Server Group"
        :cancel-url="route('admin.server-groups.index')"
    >
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="name" label="Name" placeholder="e.g. Web Cluster A"
                                  value="{{ old('name') }}" required />
            </div>
            <div class="col-md-4">
                <x-adminlte-select name="load_balancing" label="Load balancing">
                    @foreach (['round_robin' => 'Round Robin', 'least_loaded' => 'Least Loaded', 'failover' => 'Failover'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('load_balancing', 'round_robin') === $value)>{{ $label }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-2">
                <x-adminlte-select name="status" label="Status">
                    <option value="active" @selected(old('status', 'active') === 'active')>Active</option>
                    <option value="inactive" @selected(old('status') === 'inactive')>Inactive</option>
                </x-adminlte-select>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <label for="allowed_server_type" class="form-label">Server type <span class="text-muted">(Any = mixed)</span></label>
                <select name="allowed_server_type" id="allowed_server_type" class="form-select" data-filter-members>
                    <option value="">Any (no restriction)</option>
                    @foreach ($serverTypeOptions as $opt)
                        <option value="{{ $opt['value'] }}" @selected(old('allowed_server_type') === $opt['value'])>
                            {{ $opt['label'] }} — {{ $opt['group'] }}
                        </option>
                    @endforeach
                </select>
                @error('allowed_server_type')<div class="text-danger small">{{ $message }}</div>@enderror
                <div class="form-text">When set, only servers of this type can be added to the group. Products must match the same type.</div>
            </div>
            <div class="col-md-6">
                <x-adminlte-textarea name="description" label="Description" rows="2"
                                     placeholder="Optional description">{{ old('description') }}</x-adminlte-textarea>
            </div>
        </div>

        <x-adminlte-card title="Member servers" icon="bi bi-server" class="mt-3" body-class="p-3">
            <div id="members-hint" class="alert alert-info py-2 small d-none"></div>
            @forelse ($servers as $server)
                <div class="form-check member-row" data-server-type="{{ $server->server_type ?? '' }}">
                    <input class="form-check-input" type="checkbox" name="server_ids[]"
                           value="{{ $server->id }}" id="server-{{ $server->id }}"
                           @checked(in_array($server->id, old('server_ids', [])))>
                    <label class="form-check-label" for="server-{{ $server->id }}">
                        {{ $server->name }} ({{ $server->ip_address }})
                        <span class="badge bg-secondary">{{ $server->server_type ?? 'unknown' }}</span>
                        <x-adminlte.partials.status-badge :status="$server->status" />
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
            const show = !wanted || st === wanted;
            row.style.display = show ? '' : 'none';
            if (!show) {
                const cb = row.querySelector('input[type=checkbox]');
                if (cb && cb.checked) {
                    cb.checked = false;
                }
                hidden++;
            } else { visible++; }
        });
        if (hint) {
            if (wanted && hidden > 0) {
                hint.textContent = hidden + ' server(s) hidden — only "' + wanted + '" servers are shown for this group type. Uncheck hidden servers automatically deselected.';
                hint.classList.remove('d-none');
            } else if (wanted && visible === 0) {
                hint.textContent = 'No servers of type "' + wanted + '" exist yet. Create a server of that type first.';
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
