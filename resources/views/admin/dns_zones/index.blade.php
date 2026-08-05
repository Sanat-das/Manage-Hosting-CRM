@extends('adminlte::page')
@section('title', 'DNS Zones')
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">DNS Zones</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item active">DNS Zones</li></ol></div></div>
@stop
@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif
    <x-adminlte-card icon="bi bi-globe" title="All DNS Zones">
        <div class="row mb-3">
            <div class="col-md-6">
                <form method="GET" action="{{ route('admin.dns-zones.index') }}" class="d-flex gap-2">
                    <x-adminlte-input name="search" placeholder="Search domain..." value="{{ request('search') }}" fgroup-class="flex-grow-1" />
                    <button type="submit" class="btn btn-sm btn-outline-primary mt-4">Search</button>
                </form>
            </div>
            <div class="col-md-6 text-end">
                <a href="{{ route('admin.dns-zones.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Zone</a>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Domain</th><th>Type</th><th>Records</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                    @forelse ($zones as $zone)
                        <tr>
                            <td><a href="{{ route('admin.dns-zones.show', $zone) }}"><strong>{{ $zone->domain }}</strong></a></td>
                            <td><span class="badge bg-info">{{ ucfirst($zone->type) }}</span></td>
                            <td>{{ $zone->records_count ?? $zone->records->count() }}</td>
                            <td><x-adminlte.partials.status-badge :status="$zone->status" /></td>
                            <td class="text-end">
                                <a href="{{ route('admin.dns-zones.records.index', $zone) }}" class="btn btn-sm btn-outline-info" title="Records"><i class="bi bi-list-nested"></i></a>
                                <a href="{{ route('admin.dns-zones.edit', $zone) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">No DNS zones found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $zones->links() }}
    </x-adminlte-card>
@stop
