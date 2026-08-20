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

    <x-adminlte-card icon="bi bi-box-seam" title="All Catalog Products">
        <div class="text-end mb-3">
            <a href="{{ route('admin.catalog-products.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Product</a>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr><th>SKU</th><th>Name</th><th>Category</th><th>Type</th><th>Billing</th><th>Status</th><th class="text-end">Actions</th></tr>
                </thead>
                <tbody>
                    @forelse ($products as $product)
                        <tr>
                            <td><code>{{ $product->sku }}</code></td>
                            <td><a href="{{ route('admin.catalog-products.show', $product) }}"><strong>{{ $product->name }}</strong></a></td>
                            <td>{{ $product->category?->name ?? '—' }}</td>
                            <td><span class="badge bg-info">{{ ucfirst($product->product_type) }}</span></td>
                            <td>{{ ucfirst(str_replace('_', ' ', $product->billing_model)) }}</td>
                            <td><x-adminlte.partials.status-badge :status="$product->status" /></td>
                            <td class="text-end"><a href="{{ route('admin.catalog-products.edit', $product) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">No catalog products found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $products->links() }}
    </x-adminlte-card>
@stop
