@extends('adminlte::page')
@section('title', 'IP Subnet — '.$ipSubnet->name)
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">{{ $ipSubnet->name }}</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item"><a href="{{ route('admin.ip-subnets.index') }}">IP Subnets</a></li><li class="breadcrumb-item active">{{ $ipSubnet->name }}</li></ol></div></div>
@stop
@section('content')
    <div class="d-flex justify-content-end mb-3">
        <a href="{{ route('admin.ip-subnets.edit', $ipSubnet) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i> Edit</a>
    </div>
    <x-adminlte-card icon="bi bi-grid" title="Subnet Details">
        <div class="row">
            <div class="col-md-4"><strong>CIDR:</strong> <code>{{ $ipSubnet->subnet_cidr }}</code></div>
            <div class="col-md-4"><strong>Gateway:</strong> {{ $ipSubnet->gateway ?? '—' }}</div>
            <div class="col-md-4"><strong>Netmask:</strong> {{ $ipSubnet->netmask ?? '—' }}</div>
        </div>
        <div class="row mt-3">
            <div class="col-md-4"><strong>IP Version:</strong> IPv{{ $ipSubnet->ip_version }}</div>
            <div class="col-md-4"><strong>Network Type:</strong> {{ ucfirst($ipSubnet->network_type) }}</div>
            <div class="col-md-4"><strong>Status:</strong> <x-adminlte.partials.status-badge :status="$ipSubnet->status ?? 'active'" /></div>
        </div>
        <div class="row mt-3">
            <div class="col-md-4"><strong>Total Addresses:</strong> {{ $ipSubnet->total_addresses ?? '—' }}</div>
            <div class="col-md-4"><strong>Used:</strong> {{ $ipSubnet->used_addresses ?? 0 }}</div>
            <div class="col-md-4"><strong>Reserved:</strong> {{ $ipSubnet->reserved_count ?? 0 }}</div>
        </div>
        <div class="row mt-3">
            <div class="col-md-4"><strong>VLAN:</strong> {{ $ipSubnet->vlan?->name ?? '—' }}</div>
            <div class="col-md-4"><strong>Datacenter:</strong> {{ $ipSubnet->datacenter?->name ?? '—' }}</div>
        </div>
        @if ($ipSubnet->description)
            <hr>
            <p class="mb-0">{{ $ipSubnet->description }}</p>
        @endif
    </x-adminlte-card>

    <x-adminlte-card icon="bi bi-diagram-3" title="IP Addresses in this Subnet">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>IP Address</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse ($ipSubnet->ipAddresses as $ip)
                        <tr>
                            <td><code>{{ $ip->ip_address }}</code></td>
                            <td><x-adminlte.partials.status-badge :status="$ip->status ?? 'available'" /></td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="text-center text-muted py-4">No IP addresses assigned.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-adminlte-card>
@stop
