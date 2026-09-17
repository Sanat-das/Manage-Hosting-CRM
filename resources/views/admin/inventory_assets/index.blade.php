@extends('adminlte::page')
@section('title', 'Inventory Assets')
@section('content_header')
    <x-ui.page-header title="Inventory Assets" subtitle="Overview and management of inventory assets" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Inventory Assets','active' => true]]" />
@stop
@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif

    <x-adminlte.partials.datatable
        icon="bi bi-box-seam"
        title="All Assets"
        :search-value="$search"
        search-placeholder="Search tag, serial, model..."
        :status-options="collect($statuses)->mapWithKeys(fn ($s) => [$s => ucfirst(str_replace('_', ' ', $s))])->all()"
        :status-value="$status"
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
            <form method="GET" action="{{ url()->current() }}" class="d-inline-flex align-items-center gap-2 me-2">
                <input type="hidden" name="search" value="{{ $search }}">
                @if(request('status'))<input type="hidden" name="status" value="{{ request('status') }}">@endif
                @if(request('sort'))<input type="hidden" name="sort" value="{{ request('sort') }}">@endif
                @if(request('direction'))<input type="hidden" name="direction" value="{{ request('direction') }}">@endif
                <select name="asset_type" class="form-select form-select-sm w-auto" onchange="this.form.submit()" aria-label="Filter by asset type">
                    <option value="">All Types</option>
                    @foreach ($assetTypes as $t)
                        <option value="{{ $t }}" @selected(request('asset_type') === $t)>{{ ucfirst(str_replace('_', ' ', $t)) }}</option>
                    @endforeach
                </select>
            </form>
            <a href="{{ route('admin.inventory-assets.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Asset</a>
        </x-slot>

        @forelse ($assets as $asset)
            <tr>
                <td><a href="{{ route('admin.inventory-assets.show', $asset) }}"><strong>{{ $asset->asset_tag }}</strong></a></td>
                <td><span class="badge text-bg-info">{{ ucfirst($asset->asset_type) }}</span></td>
                <td class="text-muted">{{ $asset->serial_number ?? 'â€”' }}</td>
                <td>{{ $asset->model ?? 'â€”' }}</td>
                <td>{{ $asset->datacenter?->name ?? 'â€”' }}</td>
                <td>{{ $asset->rack?->name ?? 'â€”' }}</td>
                <td><x-adminlte.partials.status-badge :status="$asset->status" /></td>
                <td class="text-end">
                    <div class="table-actions">
                        <a href="{{ route('admin.inventory-assets.edit', $asset) }}" class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit"><i class="bi bi-pencil"></i></a>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="8" class="text-center text-muted py-4">No inventory assets found.</td></tr>
        @endforelse
    </x-adminlte.partials.datatable>
@stop

