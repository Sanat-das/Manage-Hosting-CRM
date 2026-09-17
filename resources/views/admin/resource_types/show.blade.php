@extends('adminlte::page')
@section('title', 'Resource Type â€” '.$type->name)
@section('content_header')
    <x-ui.page-header title="{{ $type->name }}" subtitle="View resource type details" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Resource Types','url' => route('admin.resource-types.index')],['label' => $type->name,'active' => true]]" />
@stop
@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif
    <div class="d-flex justify-content-end mb-3"><a href="{{ route('admin.resource-types.edit', $type) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i> Edit</a></div>
    <x-adminlte-card icon="bi bi-info-circle" title="Details">
        <table class="table table-sm table-borderless mb-0">
            <tbody>
                <tr><th class="text-muted w-25">Name</th><td>{{ $type->name }}</td></tr>
                <tr><th class="text-muted">Slug</th><td><code>{{ $type->slug }}</code></td></tr>
                <tr><th class="text-muted">Category</th><td>{{ $type->category ?? 'â€”' }}</td></tr>
                <tr><th class="text-muted">Unit</th><td>{{ $type->unit ?? 'â€”' }}</td></tr>
                <tr><th class="text-muted">Description</th><td>{{ $type->description ?? 'â€”' }}</td></tr>
            </tbody>
        </table>
    </x-adminlte-card>
@stop

