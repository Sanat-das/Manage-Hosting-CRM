@extends('adminlte::page')

@section('title', 'Catalog Product — '.$product->name)

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">{{ $product->name }}</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.catalog-products.index') }}">Catalog Products</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $product->name }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif
    <div class="d-flex justify-content-end mb-3">
        <a href="{{ route('admin.catalog-products.edit', $product) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i> Edit</a>
    </div>

    <div class="row">
        <div class="col-md-8">
            <x-adminlte-card icon="bi bi-info-circle" title="Details">
                <table class="table table-sm table-borderless mb-0">
                    <tbody>
                        <tr><th class="text-muted w-25">SKU</th><td><code>{{ $product->sku }}</code></td></tr>
                        <tr><th class="text-muted">Name</th><td>{{ $product->name }}</td></tr>
                        <tr><th class="text-muted">Category</th><td>{{ $product->category?->name ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Type</th><td><span class="badge bg-info">{{ ucfirst($product->product_type) }}</span></td></tr>
                        <tr><th class="text-muted">Billing</th><td>{{ ucfirst(str_replace('_', ' ', $product->billing_model)) }}</td></tr>
                        <tr><th class="text-muted">Provisioning</th><td>{{ $product->provisioning_method ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Description</th><td>{{ $product->description ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Status</th><td><x-adminlte.partials.status-badge :status="$product->status" /></td></tr>
                        <tr><th class="text-muted">Version</th><td>{{ $product->version }}</td></tr>
                    </tbody>
                </table>
            </x-adminlte-card>
        </div>
        <div class="col-md-4">
            <x-adminlte-card icon="bi bi-gear" title="Options">
                @if ($product->require_domain)
                    <span class="badge bg-warning me-1">Requires Domain</span>
                @endif
                @if ($product->show_in_order)
                    <span class="badge bg-success me-1">Visible in Order</span>
                @endif
                @if ($product->only_admin)
                    <span class="badge bg-danger">Admin Only</span>
                @endif
            </x-adminlte-card>
        </div>
    </div>
@stop
