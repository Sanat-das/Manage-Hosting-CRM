@extends('adminlte::page')
@section('title', 'Asset — '.$inventoryAsset->name)
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">{{ $inventoryAsset->name }}</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item"><a href="{{ route('admin.inventory-assets.index') }}">Inventory</a></li><li class="breadcrumb-item active">{{ $inventoryAsset->name }}</li></ol></div></div>
@stop
@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif
    <div class="d-flex justify-content-end mb-3">
        <a href="{{ route('admin.inventory-assets.edit', $inventoryAsset) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i> Edit</a>
    </div>
    <div class="row">
        <div class="col-md-8">
            <x-adminlte-card icon="bi bi-info-circle" title="Details">
                <table class="table table-sm table-borderless mb-0">
                    <tbody>
                        <tr><th class="text-muted w-25">Name</th><td>{{ $inventoryAsset->name }}</td></tr>
                        <tr><th class="text-muted">Type</th><td><span class="badge bg-info">{{ ucfirst($inventoryAsset->asset_type) }}</span></td></tr>
                        <tr><th class="text-muted">Serial</th><td>{{ $inventoryAsset->serial_number ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Model</th><td>{{ $inventoryAsset->model ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Manufacturer</th><td>{{ $inventoryAsset->manufacturer ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Datacenter</th><td>{{ $inventoryAsset->datacenter?->name ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Rack</th><td>{{ $inventoryAsset->rack?->name ?? '—' }}</td></tr>
                        <tr><th class="text-muted">U Position</th><td>{{ $inventoryAsset->u_position ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Purchase Date</th><td>{{ $inventoryAsset->purchase_date?->format('Y-m-d') ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Warranty Expiry</th><td>{{ $inventoryAsset->warranty_expiry?->format('Y-m-d') ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Status</th><td><x-adminlte.partials.status-badge :status="$inventoryAsset->status" /></td></tr>
                        <tr><th class="text-muted">Notes</th><td>{{ $inventoryAsset->notes ?? '—' }}</td></tr>
                    </tbody>
                </table>
            </x-adminlte-card>
        </div>
        <div class="col-md-4">
            @if ($inventoryAsset->children->count())
                <x-adminlte-card icon="bi bi-diagram-3" title="Child Assets">
                    @foreach ($inventoryAsset->children as $child)
                        <div><a href="{{ route('admin.inventory-assets.show', $child) }}">{{ $child->name }}</a> <span class="badge bg-secondary">{{ $child->asset_type }}</span></div>
                    @endforeach
                </x-adminlte-card>
            @endif
        </div>
    </div>
@stop
