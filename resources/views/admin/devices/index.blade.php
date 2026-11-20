@extends('adminlte::page')
@section('title', 'Devices')
@section('content_header')
    <x-ui.page-header title="Devices" subtitle="Overview and management of devices" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Devices','active' => true]]" />
@stop
@section('content')
    @php($canManage = auth()->user()?->can('devices.manage') ?? false)

    <x-adminlte.partials.flash-alert />

    <x-adminlte.partials.datatable
        icon="bi bi-box-seam"
        title="All Devices"
        :search-value="$search"
        search-placeholder="Search tag, serial, model..."
        :status-options="collect($statuses)->mapWithKeys(fn ($s) => [$s => ucfirst(str_replace('_', ' ', $s))])->all()"
        :status-value="$status"
        :export-url="route('admin.devices.export')"
        :show-checkboxes="$canManage"
        :columns="[
            ['label' => 'Asset Tag', 'sort' => 'asset_tag'],
            ['label' => 'Category', 'sort' => 'category'],
            ['label' => 'Device Type'],
            ['label' => 'Serial', 'sort' => 'serial_number'],
            ['label' => 'Model', 'sort' => 'model'],
            ['label' => 'Datacenter', 'sort' => 'datacenter'],
            ['label' => 'Rack', 'sort' => 'rack'],
            ['label' => 'Status', 'sort' => 'status'],
            ['label' => 'Tags'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$assets"
    >
        <x-slot name="tools">
            @can('ports.view')
                <a href="{{ route('admin.port-connections.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-plug me-1"></i> Connections</a>
            @endcan
            @if ($canManage)
                <a href="{{ route('admin.devices.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Device</a>
            @endif
        </x-slot>

        <x-slot name="filters">
            <div class="d-flex align-items-center gap-1">
                <label for="filter-category" class="form-label small fw-medium mb-0">Category</label>
                <select id="filter-category" name="category" class="form-select form-select-sm w-auto" onchange="this.form.submit()" aria-label="Filter by category">
                    <option value="">All Categories</option>
                    @foreach ($categories as $value => $label)
                        <option value="{{ $value }}" @selected(request('category') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="d-flex align-items-center gap-1">
                <label for="filter-datacenter" class="form-label small fw-medium mb-0">Datacenter</label>
                <select id="filter-datacenter" name="datacenter_id" class="form-select form-select-sm w-auto" onchange="this.form.submit()" aria-label="Filter by datacenter">
                    <option value="">All datacenters</option>
                    @foreach ($datacenters as $datacenter)
                        <option value="{{ $datacenter->id }}" @selected((string) request('datacenter_id') === (string) $datacenter->id)>{{ $datacenter->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="d-flex align-items-center gap-1">
                <label for="filter-rack" class="form-label small fw-medium mb-0">Rack</label>
                <select id="filter-rack" name="rack_id" class="form-select form-select-sm w-auto" onchange="this.form.submit()" aria-label="Filter by rack">
                    <option value="">All racks</option>
                    @foreach ($racks as $rack)
                        <option value="{{ $rack->id }}" @selected((string) request('rack_id') === (string) $rack->id)>{{ $rack->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="d-flex align-items-center gap-1">
                <label for="filter-tag" class="form-label small fw-medium mb-0">Tag</label>
                <select id="filter-tag" name="tag" class="form-select form-select-sm w-auto" onchange="this.form.submit()" aria-label="Filter by tag">
                    <option value="">All tags</option>
                    @foreach ($tags as $tag)
                        <option value="{{ $tag->id }}" @selected((string) request('tag') === (string) $tag->id)>{{ $tag->name }}</option>
                    @endforeach
                </select>
            </div>
        </x-slot>

        @if ($canManage)
            <x-slot name="bulkActions">
                <form method="POST" action="{{ route('admin.devices.bulk-status') }}" id="devices-bulk-status-form" class="d-inline-flex align-items-center gap-2">
                    @csrf
                    <select name="status" class="form-select form-select-sm w-auto" aria-label="Bulk status" required>
                        <option value="">Set status…</option>
                        @foreach ($statuses as $s)
                            <option value="{{ $s }}">{{ ucfirst(str_replace('_', ' ', $s)) }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn btn-sm btn-primary">Apply</button>
                </form>
                @can('devices.manage')
                    <form method="POST" action="{{ route('admin.devices.bulk-edit') }}" id="devices-bulk-edit-form" class="d-inline-flex align-items-center gap-2 flex-wrap">
                        @csrf
                        <select name="status" class="form-select form-select-sm w-auto" aria-label="Bulk edit status">
                            <option value="">— no change —</option>
                            @foreach ($statuses as $s)
                                <option value="{{ $s }}">{{ ucfirst(str_replace('_', ' ', $s)) }}</option>
                            @endforeach
                        </select>
                        <select name="datacenter_id" class="form-select form-select-sm w-auto" aria-label="Bulk edit datacenter">
                            <option value="">— no change —</option>
                            @foreach ($datacenters as $datacenter)
                                <option value="{{ $datacenter->id }}">{{ $datacenter->name }}</option>
                            @endforeach
                        </select>
                        <select name="device_type_id" class="form-select form-select-sm w-auto" aria-label="Bulk edit device type">
                            <option value="">— no change —</option>
                            @foreach ($deviceTypes as $deviceType)
                                <option value="{{ $deviceType->id }}">{{ $deviceType->manufacturer?->name ?? '—' }} — {{ $deviceType->model }}</option>
                            @endforeach
                        </select>
                        <input type="date" name="warranty_expiry" class="form-control form-control-sm w-auto" aria-label="Bulk edit warranty expiry">
                        <button type="submit" class="btn btn-sm btn-primary">Apply to selected</button>
                    </form>
                @endcan
            </x-slot>
        @endif

        @forelse ($assets as $asset)
            <tr>
                @if ($canManage)
                    <td class="text-center">
                        <input type="checkbox" class="form-check-input" name="ids[]" value="{{ $asset->id }}"
                               form="devices-bulk-status-form" data-grid-bulk aria-label="Select {{ $asset->asset_tag }}">
                    </td>
                @endif
                <td><a href="{{ route('admin.devices.show', $asset) }}"><strong>{{ $asset->asset_tag }}</strong></a></td>
                <td><span class="badge text-bg-info">{{ $asset->deviceType !== null ? (\App\Models\DeviceType::CATEGORIES[$asset->deviceType->category] ?? $asset->deviceType->category) : '—' }}</span></td>
                <td>
                    @if ($asset->deviceType !== null)
                        <a href="{{ route('admin.device-types.show', $asset->deviceType) }}">{{ $asset->deviceType->manufacturer?->name ?? '—' }} — {{ $asset->deviceType->model }}</a>
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </td>
                <td class="text-muted">{{ $asset->serial_number ?? '—' }}</td>
                <td>{{ $asset->model ?? '—' }}</td>
                <td>{{ $asset->datacenter?->name ?? '—' }}</td>
                <td>{{ $asset->rack?->name ?? '—' }}</td>
                <td><x-adminlte.partials.status-badge :status="$asset->status" /></td>
                <td>
                    @forelse ($asset->tags as $tag)
                        <span class="badge text-white me-1" style="background-color: {{ $tag->color }};">{{ $tag->name }}</span>
                    @empty
                        <span class="text-muted">—</span>
                    @endforelse
                </td>
                <td class="text-end">
                    @if ($canManage)
                        <div class="table-actions">
                            <a href="{{ route('admin.devices.edit', $asset) }}" class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit"><i class="bi bi-pencil"></i></a>
                            <button type="button" class="btn btn-sm btn-outline-danger btn-icon" title="Delete" aria-label="Delete"
                                    data-bs-toggle="modal" data-bs-target="#delete-device-{{ $asset->id }}"><i class="bi bi-trash"></i></button>
                        </div>
                    @endif
                </td>
            </tr>
        @empty
            <x-ui.empty-table-row colSpan="{{ $canManage ? 11 : 10 }}" title="No devices found." />
        @endforelse
    </x-adminlte.partials.datatable>

    @if ($canManage)
        <script>
            (function () {
                var form = document.getElementById('devices-bulk-edit-form');
                if (! form) {
                    return;
                }
                // The row checkboxes are bound to the bulk-status form, so the
                // edit form submits cloned hidden ids[] inputs of its own.
                form.addEventListener('submit', function (event) {
                    var checked = document.querySelectorAll('input[name="ids[]"]:checked');
                    if (checked.length === 0) {
                        event.preventDefault();
                        alert('Select at least one device to edit.');
                        return;
                    }
                    form.querySelectorAll('input[data-bulk-edit-id]').forEach(function (el) {
                        el.remove();
                    });
                    checked.forEach(function (box) {
                        var hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = 'ids[]';
                        hidden.value = box.value;
                        hidden.setAttribute('data-bulk-edit-id', '1');
                        form.appendChild(hidden);
                    });
                });
            })();
        </script>
    @endif

    @if ($canManage)
        @foreach ($assets as $asset)
            <x-adminlte.partials.confirm-modal
                :id="'delete-device-' . $asset->id"
                title="Delete device"
                :message="'Delete ' . $asset->asset_tag . '? This cannot be undone.'"
                :action="route('admin.devices.destroy', $asset)"
                confirm-label="Delete asset"
            />
        @endforeach
    @endif
@stop
