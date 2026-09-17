@extends('adminlte::page')
@section('title', 'Upgrade Path — '.$path->fromProduct->name.' → '.$path->toProduct->name)
@section('content_header')
    <x-ui.page-header title="{{ $path->fromProduct->name }} → {{ $path->toProduct->name }}" subtitle="View upgrade path details and history" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Product Upgrade Paths', 'url' => route('admin.product-upgrades.index')],
        ['label' => 'Show', 'active' => true],
    ]" />
@stop
@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif
    <div class="d-flex justify-content-end mb-3"><a href="{{ route('admin.product-upgrades.edit', $path) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i> Edit</a></div>
    <x-adminlte-card icon="bi bi-info-circle" title="Details">
        <table class="table table-sm table-borderless mb-0">
            <tbody>
                <tr><th class="text-muted w-25">From</th><td><a href="{{ route('admin.products.show', $path->fromProduct) }}">{{ $path->fromProduct->name }}</a> <span class="badge text-bg-info">{{ $path->fromProduct->group?->name ?? '' }}</span></td></tr>
                <tr><th class="text-muted">To</th><td><a href="{{ route('admin.products.show', $path->toProduct) }}">{{ $path->toProduct->name }}</a> <span class="badge text-bg-info">{{ $path->toProduct->group?->name ?? '' }}</span></td></tr>
                <tr><th class="text-muted">Enabled</th><td>
                    @if ($path->enabled) <span class="badge text-bg-success">Enabled</span> @else <span class="badge text-bg-secondary">Disabled</span> @endif
                </td></tr>
            </tbody>
        </table>
    </x-adminlte-card>
@stop
