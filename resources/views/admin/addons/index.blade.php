@extends('adminlte::page')

@section('title', 'Add-ons')

@php
    $cycleLabels = ['one_time' => 'One Time', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'semi_annual' => 'Semi-Annual', 'annual' => 'Annual'];
@endphp

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Add-ons</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">Add-ons</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif
    @if (session('error'))
        <x-adminlte-alert theme="danger" dismissible>{{ session('error') }}</x-adminlte-alert>
    @endif

    <x-adminlte.partials.datatable
        icon="bi bi-plus-square"
        title="All Add-ons"
        :search-value="$search"
        search-placeholder="Search add-on name..."
        :status-options="['active' => 'Active', 'inactive' => 'Inactive']"
        :status-value="$status"
        :columns="[
            ['label' => 'Name', 'sort' => 'name'],
            ['label' => 'Product', 'sort' => 'product'],
            ['label' => 'Cycle', 'sort' => 'cycle'],
            ['label' => 'Setup fee', 'sort' => 'setup_fee', 'class' => 'text-end'],
            ['label' => 'Price', 'sort' => 'price', 'class' => 'text-end'],
            ['label' => 'Status', 'sort' => 'status'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$addons"
    >
        <x-slot name="tools">
            <form method="GET" action="{{ url()->current() }}" class="d-inline-flex align-items-center gap-2">
                <input type="hidden" name="search" value="{{ $search }}">
                <input type="hidden" name="status" value="{{ $status }}">
                <select name="product_id" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Filter by product">
                    <option value="">All products</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}" @selected((string) $productId === (string) $product->id)>{{ $product->name }}</option>
                    @endforeach
                </select>
            </form>
            @can('products.addons')
                <a href="{{ route('admin.addons.create') }}" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Add Add-on
                </a>
            @endcan
        </x-slot>

        @forelse ($addons as $addon)
            <tr>
                <td>
                    <strong>{{ $addon->name }}</strong>
                    @if ($addon->description)
                        <div class="text-muted small">{{ $addon->description }}</div>
                    @endif
                </td>
                <td>
                    @if ($addon->product)
                        {{ $addon->product->name }}
                    @else
                        <span class="badge text-bg-secondary">Global</span>
                    @endif
                </td>
                <td class="text-muted">{{ $cycleLabels[$addon->billing_cycle] ?? $addon->billing_cycle }}</td>
                <td class="text-end">{{ number_format($addon->setup_fee, 2) }}</td>
                <td class="text-end">{{ number_format($addon->price, 2) }}</td>
                <td>
                    <x-adminlte.partials.status-badge :status="$addon->status" />
                </td>
                <td class="text-end">
                        <div class="table-actions">
                            @can('products.addons')
                            <a href="{{ route('admin.addons.edit', $addon) }}"
                            class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit">
                            <i class="bi bi-pencil"></i>
                            </a>
                            <button type="button" class="btn btn-sm btn-outline-danger btn-icon" title="Delete" aria-label="Delete"
                            data-bs-toggle="modal" data-bs-target="#delete-addon-{{ $addon->id }}">
                            <i class="bi bi-trash"></i>
                            </button>
                            @endcan
                        </div>
                    </td>
            </tr>
        @empty
            <tr>
                <td colspan="7" class="text-center text-muted py-4">
                    No add-ons found.
                </td>
            </tr>
        @endforelse
    </x-adminlte.partials.datatable>

    @foreach ($addons as $addon)
        @can('products.addons')
            <x-adminlte.partials.confirm-modal
                :id="'delete-addon-' . $addon->id"
                title="Delete add-on"
                :message="'Delete ' . $addon->name . '? This cannot be undone.'"
                :action="route('admin.addons.destroy', $addon)"
                confirm-label="Delete add-on"
            />
        @endcan
    @endforeach
@stop
