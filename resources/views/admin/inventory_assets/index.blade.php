@extends('adminlte::page')
@section('title', 'Inventory Assets')
@section('content_header')
    <x-ui.page-header title="Inventory Assets" subtitle="Overview and management of inventory assets" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Inventory Assets','active' => true]]" />
@stop
@section('content')
    @php($canManage = auth()->user()?->can('inventory.manage') ?? false)

    <x-adminlte.partials.flash-alert />

    <x-adminlte.partials.datatable
        icon="bi bi-box-seam"
        title="All Assets"
        :search-value="$search"
        search-placeholder="Search tag, serial, model..."
        :status-options="collect($statuses)->mapWithKeys(fn ($s) => [$s => ucfirst(str_replace('_', ' ', $s))])->all()"
        :status-value="$status"
        :export-url="route('admin.inventory-assets.export')"
        :show-checkboxes="$canManage"
        :columns="[
            ['label' => 'Asset Tag', 'sort' => 'asset_tag'],
            ['label' => 'Type', 'sort' => 'asset_type'],
            ['label' => 'Serial', 'sort' => 'serial_number'],
            ['label' => 'Model', 'sort' => 'model'],
            ['label' => 'Datacenter', 'sort' => 'datacenter'],
            ['label' => 'Rack', 'sort' => 'rack'],
            ['label' => 'Status', 'sort' => 'status'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$assets"
    >
        <x-slot name="tools">
            @can('asset-relationships.view')
                <a href="{{ route('admin.inventory-tree.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-diagram-3 me-1"></i> Dependency Tree</a>
                <a href="{{ route('admin.asset-relationships.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-link-45deg me-1"></i> Relationships</a>
            @endcan
            @can('ports.view')
                <a href="{{ route('admin.port-connections.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-plug me-1"></i> Connections</a>
            @endcan
            @if ($canManage)
                <a href="{{ route('admin.inventory-assets.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Asset</a>
            @endif
        </x-slot>

        <x-slot name="filters">
            <div class="d-flex align-items-center gap-1">
                <label for="filter-asset-type" class="form-label small fw-medium mb-0">Type</label>
                <select id="filter-asset-type" name="asset_type" class="form-select form-select-sm w-auto" onchange="this.form.submit()" aria-label="Filter by asset type">
                    <option value="">All Types</option>
                    @foreach ($assetTypes as $t)
                        <option value="{{ $t }}" @selected(request('asset_type') === $t)>{{ ucfirst(str_replace('_', ' ', $t)) }}</option>
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
        </x-slot>

        @if ($canManage)
            <x-slot name="bulkActions">
                <form method="POST" action="{{ route('admin.inventory-assets.bulk-status') }}" id="inventory-bulk-status-form" class="d-inline-flex align-items-center gap-2">
                    @csrf
                    <select name="status" class="form-select form-select-sm w-auto" aria-label="Bulk status" required>
                        <option value="">Set status…</option>
                        @foreach ($statuses as $s)
                            <option value="{{ $s }}">{{ ucfirst(str_replace('_', ' ', $s)) }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn btn-sm btn-primary">Apply</button>
                </form>
            </x-slot>
        @endif

        @forelse ($assets as $asset)
            <tr>
                @if ($canManage)
                    <td class="text-center">
                        <input type="checkbox" class="form-check-input" name="ids[]" value="{{ $asset->id }}"
                               form="inventory-bulk-status-form" data-grid-bulk aria-label="Select {{ $asset->asset_tag }}">
                    </td>
                @endif
                <td><a href="{{ route('admin.inventory-assets.show', $asset) }}"><strong>{{ $asset->asset_tag }}</strong></a></td>
                <td><span class="badge text-bg-info">{{ ucfirst($asset->asset_type) }}</span></td>
                <td class="text-muted">{{ $asset->serial_number ?? '—' }}</td>
                <td>{{ $asset->model ?? '—' }}</td>
                <td>{{ $asset->datacenter?->name ?? '—' }}</td>
                <td>{{ $asset->rack?->name ?? '—' }}</td>
                <td><x-adminlte.partials.status-badge :status="$asset->status" /></td>
                <td class="text-end">
                    @if ($canManage)
                        <div class="table-actions">
                            <a href="{{ route('admin.inventory-assets.edit', $asset) }}" class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit"><i class="bi bi-pencil"></i></a>
                            <button type="button" class="btn btn-sm btn-outline-danger btn-icon" title="Delete" aria-label="Delete"
                                    data-bs-toggle="modal" data-bs-target="#delete-inventory-asset-{{ $asset->id }}"><i class="bi bi-trash"></i></button>
                        </div>
                    @endif
                </td>
            </tr>
        @empty
            <x-ui.empty-table-row colSpan="{{ $canManage ? 9 : 8 }}" title="No inventory assets found." />
        @endforelse
    </x-adminlte.partials.datatable>

    @if ($canManage)
        @foreach ($assets as $asset)
            <x-adminlte.partials.confirm-modal
                :id="'delete-inventory-asset-' . $asset->id"
                title="Delete inventory asset"
                :message="'Delete ' . $asset->asset_tag . '? This cannot be undone.'"
                :action="route('admin.inventory-assets.destroy', $asset)"
                confirm-label="Delete asset"
            />
        @endforeach
    @endif
@stop
