@extends('adminlte::page')

@section('title', 'Products')

@php
    $cycleLabels = \App\Models\Product::BILLING_CYCLES;
@endphp

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Products</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">Products</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    <x-adminlte.partials.flash-alert />

    <x-adminlte.partials.datatable
        icon="bi bi-box"
        title="All Products"
        :search-value="$search"
        search-placeholder="Search product name..."
        :status-options="['active' => 'Active', 'inactive' => 'Inactive']"
        :status-value="$status"
        :columns="[
            ['label' => 'Name', 'sort' => 'name'],
            ['label' => 'Price', 'sort' => 'price', 'class' => 'text-end'],
            ['label' => 'Cycle', 'sort' => 'billing_cycle'],
            ['label' => 'Orders', 'class' => 'text-end'],
            ['label' => 'Status', 'sort' => 'status'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$products"
    >
        <x-slot name="tools">
            <form method="GET" action="{{ url()->current() }}" class="d-inline-flex align-items-center gap-2">
                <input type="hidden" name="search" value="{{ $search }}">
                <input type="hidden" name="status" value="{{ $status }}">
                <select name="group_id" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Filter by group">
                    <option value="">All groups</option>
                    @foreach ($groups as $group)
                        <option value="{{ $group->id }}" @selected((string) $groupId === (string) $group->id)>{{ $group->name }}</option>
                    @endforeach
                </select>
            </form>
            @can('products.create')
                <a href="{{ route('admin.products.create') }}" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Add Product
                </a>
            @endcan
        </x-slot>

        @forelse ($products as $product)
            <tr>
                <td>
                    <a href="{{ route('admin.products.show', $product) }}"><strong>{{ $product->name }}</strong></a>
                    @if ($product->group)
                        <div class="text-muted small">{{ $product->group->name }}</div>
                    @endif
                </td>
                <td class="text-end">₹{{ number_format($product->price, 2) }}</td>
                <td class="text-muted">{{ $cycleLabels[$product->billing_cycle] ?? $product->billing_cycle }}</td>
                <td class="text-end">{{ $product->orders_count }}</td>
                <td>
                    <x-adminlte.partials.status-badge :status="$product->status" />
                </td>
                <td class="text-end">
                        <div class="table-actions">
                            @can('products.edit')
                            <a href="{{ route('admin.products.edit', $product) }}"
                            class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit">
                            <i class="bi bi-pencil"></i>
                            </a>
                            @endcan
                            @can('products.delete')
                            <button type="button" class="btn btn-sm btn-outline-danger btn-icon" title="Delete" aria-label="Delete"
                            data-bs-toggle="modal" data-bs-target="#delete-product-{{ $product->id }}">
                            <i class="bi bi-trash"></i>
                            </button>
                            @endcan
                        </div>
                    </td>
            </tr>
        @empty
            <x-ui.empty-table-row
                :col-span="6"
                icon="bi bi-box"
                title="No products found"
                message="Try adjusting your search or filters, or create a new product."
            />
        @endforelse
    </x-adminlte.partials.datatable>

    @foreach ($products as $product)
        @can('products.delete')
            <x-adminlte.partials.confirm-modal
                :id="'delete-product-' . $product->id"
                title="Delete product"
                :message="'Delete ' . $product->name . '? This permanently removes the product, its pricing ladder, options and add-ons.'"
                :action="route('admin.products.destroy', $product)"
                confirm-label="Delete product"
            />
        @endcan
    @endforeach
@stop
