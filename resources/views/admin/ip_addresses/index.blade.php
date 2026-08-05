@extends('adminlte::page')

@section('title', 'IP Addresses')

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">IP Addresses</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">IP Addresses</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif

    <x-adminlte-card icon="bi bi-geo-alt" title="All IP Addresses">
        <div class="row mb-3">
            <div class="col-md-4">
                <form method="GET" action="{{ route('admin.ip-addresses.index') }}">
                    <x-adminlte-select name="subnet_id" label="Filter by Subnet">
                        <option value="">All Subnets</option>
                        @foreach ($subnets as $sub)
                            <option value="{{ $sub->id }}" @selected(request('subnet_id') == $sub->id)>{{ $sub->cidr }}</option>
                        @endforeach
                    </x-adminlte-select>
                    <button type="submit" class="btn btn-sm btn-outline-primary">Filter</button>
                </form>
            </div>
            <div class="col-md-8 text-end">
                <a href="{{ route('admin.ip-addresses.create') }}" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-lg me-1"></i> Add IP Address
                </a>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>IP Address</th>
                        <th>Version</th>
                        <th>Type</th>
                        <th>Subnet</th>
                        <th>PTR</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($addresses as $ip)
                        <tr>
                            <td><a href="{{ route('admin.ip-addresses.show', $ip) }}"><strong>{{ $ip->ip_address }}</strong></a></td>
                            <td><span class="badge bg-info">IPv{{ $ip->ip_version }}</span></td>
                            <td>{{ ucfirst($ip->type) }}</td>
                            <td>{{ $ip->subnet?->cidr ?? '—' }}</td>
                            <td class="text-muted">{{ $ip->ptr_record ?? '—' }}</td>
                            <td><x-adminlte.partials.status-badge :status="$ip->status ?? ($ip->assigned_to_service ? 'in-use' : 'available')" /></td>
                            <td class="text-end">
                                <a href="{{ route('admin.ip-addresses.edit', $ip) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">No IP addresses found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $addresses->links() }}
    </x-adminlte-card>
@stop
