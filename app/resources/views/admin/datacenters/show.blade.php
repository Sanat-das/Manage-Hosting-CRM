@extends('adminlte::page')
@section('title', 'Datacenter — '.$datacenter->name)
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">{{ $datacenter->name }}</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item"><a href="{{ route('admin.datacenters.index') }}">Datacenters</a></li><li class="breadcrumb-item active">{{ $datacenter->name }}</li></ol></div></div>
@stop
@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif
    <div class="d-flex justify-content-end mb-3">
        <a href="{{ route('admin.datacenters.edit', $datacenter) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i> Edit</a>
    </div>
    <x-adminlte-card icon="bi bi-building" title="Datacenter Details">
        <div class="row">
            <div class="col-md-4"><strong>Name:</strong> {{ $datacenter->name }}</div>
            <div class="col-md-4"><strong>Code:</strong> {{ $datacenter->code ?? '—' }}</div>
            <div class="col-md-4"><strong>Status:</strong> <x-adminlte.partials.status-badge :status="$datacenter->status ?? 'active'" /></div>
        </div>
        <div class="row mt-3">
            <div class="col-md-4"><strong>Address:</strong> {{ $datacenter->address ?? '—' }}</div>
            <div class="col-md-4"><strong>City:</strong> {{ $datacenter->city ?? '—' }}</div>
            <div class="col-md-4"><strong>State:</strong> {{ $datacenter->state ?? '—' }}</div>
        </div>
        <div class="row mt-3">
            <div class="col-md-4"><strong>Country:</strong> {{ $datacenter->country ?? '—' }}</div>
            <div class="col-md-4"><strong>Timezone:</strong> {{ $datacenter->timezone ?? '—' }}</div>
        </div>
    </x-adminlte-card>

    <x-adminlte-card icon="bi bi-hdd-rack" title="Racks in {{ $datacenter->name }}">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Name</th><th>U Height</th><th>U Available</th><th>Power</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse ($datacenter->racks as $rack)
                        <tr>
                            <td><a href="{{ route('admin.racks.show', $rack) }}"><strong>{{ $rack->name }}</strong></a></td>
                            <td>{{ $rack->u_height ?? '—' }}U</td>
                            <td>{{ $rack->u_available ?? '—' }}U</td>
                            <td>{{ $rack->power_capacity_watts ? $rack->power_capacity_watts . ' W' : '—' }}</td>
                            <td><x-adminlte.partials.status-badge :status="$rack->status" /></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">No racks in this datacenter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-adminlte-card>

    <x-adminlte-card icon="bi bi-grid" title="Subnets in {{ $datacenter->name }}">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Name</th><th>CIDR</th><th>Type</th></tr></thead>
                <tbody>
                    @forelse ($datacenter->subnets as $subnet)
                        <tr>
                            <td><a href="{{ route('admin.ip-subnets.show', $subnet) }}"><strong>{{ $subnet->name }}</strong></a></td>
                            <td><code>{{ $subnet->subnet_cidr }}</code></td>
                            <td><span class="badge bg-info">{{ ucfirst($subnet->network_type) }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-center text-muted py-4">No subnets in this datacenter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-adminlte-card>
@stop
