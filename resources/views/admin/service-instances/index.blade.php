@extends('adminlte::page')
@section('title', 'Service Instances')
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">Service Instances</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item active">Service Instances</li></ol></div></div>
@stop
@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif
    <x-adminlte-card icon="bi bi-hdd-network" title="All Services">
        <div class="row mb-3">
            <div class="col-md-6">
                <form method="GET" action="{{ route('admin.service-instances.index') }}" class="d-flex gap-2">
                    <x-adminlte-input name="search" placeholder="Search username, domain, email..." value="{{ request('search') }}" fgroup-class="flex-grow-1" />
                    <x-adminlte-select name="status" label="Status">
                        <option value="">All</option>
                        @foreach (['active','suspended','cancelled','terminated','pending'] as $s)
                            <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>
                        @endforeach
                    </x-adminlte-select>
                    <button type="submit" class="btn btn-sm btn-outline-primary mt-4">Filter</button>
                </form>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>ID</th><th>Domain / Username</th><th>Customer</th><th>Product</th><th>Server</th><th>Provision</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                    @forelse ($instances as $inst)
                        <tr>
                            <td><a href="{{ route('admin.service-instances.show', $inst) }}"><strong>#{{ $inst->id }}</strong></a></td>
                            <td>{{ $inst->domain ?? $inst->username ?? '—' }}</td>
                            <td>{{ $inst->customer?->full_name ?? '—' }}</td>
                            <td>{{ $inst->catalogProduct?->name ?? '—' }}</td>
                            <td>{{ $inst->server?->name ?? '—' }}</td>
                            <td><x-adminlte.partials.status-badge :status="$inst->provision_status" /></td>
                            <td><x-adminlte.partials.status-badge :status="$inst->status" /></td>
                            <td class="text-end"><a href="{{ route('admin.service-instances.show', $inst) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted py-4">No service instances found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $instances->links() }}
    </x-adminlte-card>
@stop
