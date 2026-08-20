@extends('adminlte::page')
@section('title', 'Resource Pools')
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">Resource Pools</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item active">Resource Pools</li></ol></div></div>
@stop
@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif
    <x-adminlte-card icon="bi bi-collection" title="All Resource Pools">
        <div class="text-end mb-3"><a href="{{ route('admin.resource-pools.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Pool</a></div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Name</th><th>Type</th><th>Server</th><th>Capacity</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                    @forelse ($pools as $pool)
                        <tr>
                            <td><a href="{{ route('admin.resource-pools.show', $pool) }}"><strong>{{ $pool->name }}</strong></a></td>
                            <td>{{ $pool->pool_type }}</td>
                            <td>{{ $pool->server?->name ?? '—' }}</td>
                            <td>{{ $pool->total_capacity ?? '—' }} {{ $pool->unit ?? '' }}</td>
                            <td><x-adminlte.partials.status-badge :status="$pool->status" /></td>
                            <td class="text-end"><a href="{{ route('admin.resource-pools.edit', $pool) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">No resource pools found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $pools->links() }}
    </x-adminlte-card>
@stop
