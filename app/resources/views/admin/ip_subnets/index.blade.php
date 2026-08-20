@extends('adminlte::page')
@section('title', 'IP Subnets')
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">IP Subnets</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item active">IP Subnets</li></ol></div></div>
@stop
@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif
    <x-adminlte-card icon="bi bi-grid" title="All Subnets">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="{{ route('admin.ip-subnets.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Subnet</a>
            <form method="GET" class="d-flex gap-2">
                <select name="datacenter_id" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                    <option value="">All Datacenters</option>
                    @foreach ($datacenters as $dc)
                        <option value="{{ $dc->id }}" @selected(request('datacenter_id') == $dc->id)>{{ $dc->name }}</option>
                    @endforeach
                </select>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Name</th><th>CIDR</th><th>Gateway</th><th>Version</th><th>Type</th><th>Total IPs</th><th>VLAN</th><th>Datacenter</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                    @forelse ($subnets as $subnet)
                        <tr>
                            <td><a href="{{ route('admin.ip-subnets.show', $subnet) }}"><strong>{{ $subnet->name }}</strong></a></td>
                            <td><code>{{ $subnet->subnet_cidr }}</code></td>
                            <td>{{ $subnet->gateway ?? '—' }}</td>
                            <td>IPv{{ $subnet->ip_version }}</td>
                            <td><span class="badge bg-info">{{ ucfirst($subnet->network_type) }}</span></td>
                            <td>{{ $subnet->total_addresses ?? '—' }}</td>
                            <td>{{ $subnet->vlan?->name ?? '—' }}</td>
                            <td>{{ $subnet->datacenter?->name ?? '—' }}</td>
                            <td class="text-end">
                                <a href="{{ route('admin.ip-subnets.edit', $subnet) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-center text-muted py-4">No subnets found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $subnets->links() }}
    </x-adminlte-card>
@stop
