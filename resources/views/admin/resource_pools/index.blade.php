@extends('adminlte::page')
@section('title', 'Resource Pools')
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">Resource Pools</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item active">Resource Pools</li></ol></div></div>
@stop
@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif

    <x-adminlte.partials.datatable
        icon="bi bi-collection"
        title="All Resource Pools"
        :search-value="$search"
        search-placeholder="Search name, type, unit..."
        :columns="[
            ['label' => 'Name', 'sort' => 'name'],
            ['label' => 'Type', 'sort' => 'pool_type'],
            ['label' => 'Server', 'sort' => 'server'],
            ['label' => 'Capacity', 'sort' => 'capacity'],
            ['label' => 'Status', 'sort' => 'status'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$pools"
    >
        <x-slot name="tools">
            <a href="{{ route('admin.resource-pools.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Pool</a>
        </x-slot>

        @forelse ($pools as $pool)
            <tr>
                <td><a href="{{ route('admin.resource-pools.show', $pool) }}"><strong>{{ $pool->name }}</strong></a></td>
                <td>{{ $pool->pool_type }}</td>
                <td>{{ $pool->server?->name ?? '—' }}</td>
                <td>{{ $pool->total_capacity ?? '—' }} {{ $pool->unit ?? '' }}</td>
                <td><x-adminlte.partials.status-badge :status="$pool->status" /></td>
                <td class="text-end">
                    <div class="table-actions">
                        <a href="{{ route('admin.resource-pools.edit', $pool) }}" class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit"><i class="bi bi-pencil"></i></a>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-center text-muted py-4">No resource pools found.</td></tr>
        @endforelse
    </x-adminlte.partials.datatable>
@stop
