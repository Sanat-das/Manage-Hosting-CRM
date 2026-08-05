@extends('adminlte::page')
@section('title', 'Inventory Assets')
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">Inventory Assets</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item active">Inventory</li></ol></div></div>
@stop
@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif
    <x-adminlte-card icon="bi bi-box-seam" title="All Assets">
        <div class="row mb-3">
            <div class="col-md-8">
                <form method="GET" action="{{ route('admin.inventory-assets.index') }}" class="d-flex gap-2 flex-wrap">
                    <x-adminlte-input name="search" placeholder="Search name, serial, model..." value="{{ request('search') }}" fgroup-class="flex-grow-1" />
                    <x-adminlte-select name="asset_type" label="Type">
                        <option value="">All Types</option>
                        @foreach (['server','switch','router','pdu','nic','storage','other'] as $t)
                            <option value="{{ $t }}" @selected(request('asset_type') === $t)>{{ ucfirst($t) }}</option>
                        @endforeach
                    </x-adminlte-select>
                    <x-adminlte-select name="status" label="Status">
                        <option value="">All</option>
                        @foreach (['active','maintenance','decommissioned','spare'] as $s)
                            <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>
                        @endforeach
                    </x-adminlte-select>
                    <button type="submit" class="btn btn-sm btn-outline-primary mt-4">Filter</button>
                </form>
            </div>
            <div class="col-md-4 text-end">
                <a href="{{ route('admin.inventory-assets.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Asset</a>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Name</th><th>Type</th><th>Serial</th><th>Model</th><th>Datacenter</th><th>Rack</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                    @forelse ($assets as $asset)
                        <tr>
                            <td><a href="{{ route('admin.inventory-assets.show', $asset) }}"><strong>{{ $asset->name }}</strong></a></td>
                            <td><span class="badge bg-info">{{ ucfirst($asset->asset_type) }}</span></td>
                            <td class="text-muted">{{ $asset->serial_number ?? '—' }}</td>
                            <td>{{ $asset->model ?? '—' }}</td>
                            <td>{{ $asset->datacenter?->name ?? '—' }}</td>
                            <td>{{ $asset->rack?->name ?? '—' }}</td>
                            <td><x-adminlte.partials.status-badge :status="$asset->status" /></td>
                            <td class="text-end">
                                <a href="{{ route('admin.inventory-assets.edit', $asset) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted py-4">No inventory assets found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $assets->links() }}
    </x-adminlte-card>
@stop
