@extends('adminlte::page')
@section('title', 'Resource Pool — '.$resourcePool->name)
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">{{ $resourcePool->name }}</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item"><a href="{{ route('admin.resource-pools.index') }}">Resource Pools</a></li><li class="breadcrumb-item active">{{ $resourcePool->name }}</li></ol></div></div>
@stop
@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif
    <div class="d-flex justify-content-end mb-3"><a href="{{ route('admin.resource-pools.edit', $resourcePool) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i> Edit</a></div>
    <x-adminlte-card icon="bi bi-info-circle" title="Details">
        <table class="table table-sm table-borderless mb-0">
            <tbody>
                <tr><th class="text-muted w-25">Name</th><td>{{ $resourcePool->name }}</td></tr>
                <tr><th class="text-muted">Type</th><td>{{ $resourcePool->pool_type }}</td></tr>
                <tr><th class="text-muted">Server</th><td>{{ $resourcePool->server?->name ?? '—' }}</td></tr>
                <tr><th class="text-muted">Capacity</th><td>{{ $resourcePool->total_capacity ?? '—' }} {{ $resourcePool->unit ?? '' }}</td></tr>
                <tr><th class="text-muted">Status</th><td><x-adminlte.partials.status-badge :status="$resourcePool->status" /></td></tr>
            </tbody>
        </table>
    </x-adminlte-card>
@stop
