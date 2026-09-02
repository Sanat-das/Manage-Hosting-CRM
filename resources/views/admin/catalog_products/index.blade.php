@extends('adminlte::page')

@section('title', 'Catalog Products')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Catalog Products</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">Catalog Products</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif

    <x-adminlte.partials.datatable
        icon="bi bi-box-seam"
        title="All Catalog Products"
        :search-value="$search"
        search-placeholder="Search SKU or name..."
        :status-options="['active' => 'Active', 'inactive' => 'Inactive']"
        :status-value="$status"
        :columns="[
            ['label' => 'SKU', 'sort' => 'sku'],
            ['label' => 'Name', 'sort' => 'name'],
            ['label' => 'Category', 'sort' => 'category'],
            ['label' => 'Type', 'sort' => 'product_type'],
            ['label' => 'Billing', 'sort' => 'billing_model'],
            ['label' => 'Status', 'sort' => 'status'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$products"
    >
        <x-slot name="tools">
            <a href="{{ route('admin.catalog-products.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Product</a>
        </x-slot>

        @forelse ($products as $product)
            <tr>
                <td><code>{{ $product->sku }}</code></td>
                <td><a href="{{ route('admin.catalog-products.show', $product) }}"><strong>{{ $product->name }}</strong></a></td>
                <td>{{ $product->category?->name ?? '—' }}</td>
                <td><span class="badge text-bg-info">{{ ucfirst($product->product_type) }}</span></td>
                <td>{{ ucfirst(str_replace('_', ' ', $product->billing_model)) }}</td>
                <td><x-adminlte.partials.status-badge :status="$product->status" /></td>
                <td class="text-end">
                    <div class="table-actions">
                        <a href="{{ route('admin.catalog-products.edit', $product) }}" class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit"><i class="bi bi-pencil"></i></a>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-center text-muted py-4">No catalog products found.</td></tr>
        @endforelse
    </x-adminlte.partials.datatable>
@stop
