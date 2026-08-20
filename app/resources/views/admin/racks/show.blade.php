@extends('adminlte::page')

@section('title', 'Rack — '.$rack->name)

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">{{ $rack->name }}</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.racks.index') }}">Racks</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $rack->name }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif

    <div class="d-flex justify-content-end mb-3">
        <a href="{{ route('admin.racks.edit', $rack) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i> Edit</a>
    </div>

    <div class="row">
        <div class="col-md-6">
            <x-adminlte-card icon="bi bi-info-circle" title="Details">
                <table class="table table-sm table-borderless mb-0">
                    <tbody>
                        <tr><th class="text-muted w-25">Name</th><td>{{ $rack->name }}</td></tr>
                        <tr><th class="text-muted">Datacenter</th><td>{{ $rack->datacenter?->name ?? '—' }}</td></tr>
                        <tr><th class="text-muted">U Height</th><td>{{ $rack->u_height ?? '—' }}U</td></tr>
                        <tr><th class="text-muted">U Available</th><td>{{ $rack->u_available ?? '—' }}U</td></tr>
                        <tr><th class="text-muted">Power</th><td>{{ $rack->power_capacity_watts ? $rack->power_capacity_watts . ' W' : '—' }}</td></tr>
                        <tr><th class="text-muted">Status</th><td><x-adminlte.partials.status-badge :status="$rack->status" /></td></tr>
                    </tbody>
                </table>
            </x-adminlte-card>
        </div>
        <div class="col-md-6">
            <x-adminlte-card icon="bi bi-hdd-stack" title="Inventory Assets">
                @forelse ($rack->inventoryAssets as $asset)
                    <div class="d-flex justify-content-between">
                        <span>{{ $asset->name ?? $asset->serial_number }}</span>
                        <x-adminlte.partials.status-badge :status="$asset->status ?? 'active'" />
                    </div>
                @empty
                    <p class="text-muted mb-0">No inventory assets in this rack.</p>
                @endforelse
            </x-adminlte-card>
        </div>
    </div>
@stop
