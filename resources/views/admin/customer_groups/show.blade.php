@extends('adminlte::page')

@section('title', $group->name)

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">{{ $group->name }}</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.customer-groups.index') }}">Customer Groups</a></li>
                <li class="breadcrumb-item active">{{ $group->name }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif

    <div class="d-flex justify-content-end mb-3">
        <a href="{{ route('admin.customer-groups.edit', $group) }}" class="btn btn-outline-primary me-2"><i class="bi bi-pencil me-1"></i> Edit</a>
        <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#delete-customer-group-modal">
            <i class="bi bi-trash me-1"></i> Delete
        </button>
    </div>

    <x-adminlte.partials.confirm-modal
        id="delete-customer-group-modal"
        title="Delete customer group"
        :message="'Delete ' . $group->name . '? This cannot be undone.'"
        :action="route('admin.customer-groups.destroy', $group)"
        confirm-label="Delete group"
    />

    <x-adminlte-card icon="bi bi-folder" title="{{ $group->name }}">
        <table class="table table-sm table-borderless">
            <tr><th class="w-25 text-muted">Name</th><td><strong>{{ $group->name }}</strong></td></tr>
            <tr><th class="text-muted">Description</th><td>{{ $group->description ?? '—' }}</td></tr>
            <tr><th class="text-muted">Parent</th><td>{{ $group->parent?->name ?? '—' }}</td></tr>
            <tr><th class="text-muted">Status</th>
                <td>{{ $group->status === 'active' ? '✅ Active' : '⏸️ Inactive' }}</td>
            </tr>
            <tr><th class="text-muted">Products</th><td>{{ $group->products_count ?? $group->products()->count() }}</td></tr>
        </table>
    </x-adminlte-card>

    @if ($group->products->isNotEmpty())
        <x-adminlte-card icon="bi bi-box" title="Products in this Group">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Product</th><th>Price</th><th>Status</th></tr></thead>
                <tbody>
                    @foreach ($group->products as $product)
                        <tr>
                            <td><strong>{{ $product->name }}</strong></td>
                            <td>{{ number_format($product->price ?? 0, 2) }}</td>
                            <td><x-adminlte.partials.status-badge :status="$product->status" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-adminlte-card>
    @endif
@stop
