@extends('adminlte::page')
@section('title', 'Inventory Items')
@section('content_header')
    <x-ui.page-header title="Inventory Items" subtitle="Components installed inside devices" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Inventory Items','active' => true]]" />
@stop
@section('content')
    @php
        $canManage = auth()->user()?->can('devices.manage') ?? false;

        $columns = [
            ['label' => 'Name', 'sort' => 'name'],
            ['label' => 'Device'],
            ['label' => 'Serial', 'sort' => 'serial_number'],
            ['label' => 'SKU', 'sort' => 'sku'],
            ['label' => 'Manufacturer'],
            ['label' => 'Status', 'sort' => 'status'],
            ['label' => 'Discovered'],
        ];
        if ($canManage) {
            $columns[] = ['label' => 'Actions', 'class' => 'text-end'];
        }
    @endphp

    <x-adminlte.partials.flash-alert />

    @if ($devices->isEmpty())
        <x-adminlte.partials.empty-state icon="bi bi-bricks" title="No devices yet" message="Inventory items are installed inside a device. Create a device first." :action-label="$canManage ? 'Add Device' : null" :action-url="$canManage ? route('admin.devices.create') : null" />
    @else
        <x-adminlte.partials.datatable
            icon="bi bi-bricks"
            title="All Inventory Items"
            :search-value="$search"
            search-placeholder="Search name, serial, tag, SKU..."
            :columns="$columns"
            :pagination="$items"
        >
            <x-slot name="tools">
                @if ($canManage)
                    <a href="{{ route('admin.inventory-items.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Item</a>
                @endif
            </x-slot>

            <x-slot name="filters">
                <div class="d-flex align-items-center gap-1">
                    <label for="filter-device" class="form-label small fw-medium mb-0">Device</label>
                    <select id="filter-device" name="device_id" class="form-select form-select-sm w-auto" onchange="this.form.submit()" aria-label="Filter by device">
                        <option value="">All devices</option>
                        @foreach ($devices as $device)
                            <option value="{{ $device->id }}" @selected((string) request('device_id') === (string) $device->id)>{{ $device->asset_tag }}{{ $device->rack_u_position ? ' · U'.$device->rack_u_position : '' }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="d-flex align-items-center gap-1">
                    <label for="filter-status" class="form-label small fw-medium mb-0">Status</label>
                    <select id="filter-status" name="status" class="form-select form-select-sm w-auto" onchange="this.form.submit()" aria-label="Filter by status">
                        <option value="">All statuses</option>
                        @foreach ($statuses as $s)
                            <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst(str_replace('_', ' ', $s)) }}</option>
                        @endforeach
                    </select>
                </div>
            </x-slot>

            @forelse ($items as $item)
                <tr>
                    <td><a href="{{ route('admin.inventory-items.show', $item) }}"><strong>{{ $item->name }}</strong></a></td>
                    <td>
                        @if ($item->device)
                            <a href="{{ route('admin.devices.show', $item->device) }}">{{ $item->device->asset_tag }}</a>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td class="text-muted">{{ $item->serial_number ?? '—' }}</td>
                    <td>{{ $item->sku ?? '—' }}</td>
                    <td>{{ $item->manufacturer?->name ?? '—' }}</td>
                    <td><x-adminlte.partials.status-badge :status="$item->status" /></td>
                    <td>
                        @if ($item->discovered)
                            <span class="badge text-bg-info">Yes</span>
                        @else
                            <span class="badge text-bg-light border">No</span>
                        @endif
                    </td>
                    @if ($canManage)
                        <td class="text-end">
                            <div class="table-actions">
                                <a href="{{ route('admin.inventory-items.edit', $item) }}" class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit"><i class="bi bi-pencil"></i></a>
                                <button type="button" class="btn btn-sm btn-outline-danger btn-icon" title="Delete" aria-label="Delete"
                                        data-bs-toggle="modal" data-bs-target="#delete-inventory-item-{{ $item->id }}"><i class="bi bi-trash"></i></button>
                            </div>
                        </td>
                    @endif
                </tr>
            @empty
                <x-ui.empty-table-row colSpan="{{ count($columns) }}" title="No inventory items found." />
            @endforelse
        </x-adminlte.partials.datatable>
    @endif

    @if ($canManage)
        @foreach ($items as $item)
            <x-adminlte.partials.confirm-modal
                :id="'delete-inventory-item-' . $item->id"
                title="Delete inventory item"
                :message="'Delete ' . $item->name . '? This cannot be undone.'"
                :action="route('admin.inventory-items.destroy', $item)"
                confirm-label="Delete item"
            />
        @endforeach
    @endif
@stop
