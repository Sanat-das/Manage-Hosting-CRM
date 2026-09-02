@extends('adminlte::page')

@section('title', 'Configurable Options')

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Configurable Options</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">Configurable Options</li>
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
        icon="bi bi-sliders"
        title="All Option Groups"
        :search-value="$search"
        search-placeholder="Search option group name..."
        :columns="[
            ['label' => 'Name', 'sort' => 'name'],
            ['label' => 'Type', 'sort' => 'type'],
            ['label' => 'Products'],
            ['label' => 'Values', 'class' => 'text-end'],
            ['label' => 'Sort Order', 'sort' => 'sort_order'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$groups"
    >
        <x-slot name="tools">
            <form method="GET" action="{{ url()->current() }}" class="d-inline-flex align-items-center gap-2">
                <input type="hidden" name="search" value="{{ $search }}">
                <select name="product_id" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Filter by product">
                    <option value="">All products</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}" @selected((string) $productId === (string) $product->id)>{{ $product->name }}</option>
                    @endforeach
                </select>
            </form>
            @can('products.options')
                <a href="{{ route('admin.product-options.create') }}" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Add Option Group
                </a>
            @endcan
        </x-slot>

        @forelse ($groups as $group)
            <tr>
                <td><strong>{{ $group->name }}</strong></td>
                <td><span class="badge text-bg-info">{{ ucfirst($group->type) }}</span></td>
                <td class="text-muted">
                    @if ($group->products->isNotEmpty())
                        {{ $group->products->pluck('name')->join(', ') }}
                    @else
                        —
                    @endif
                </td>
                <td class="text-end">{{ $group->values_count }}</td>
                <td class="text-muted">{{ $group->sort_order }}</td>
                <td class="text-end">
                        <div class="table-actions">
                            @can('products.options')
                            <a href="{{ route('admin.product-options.edit', $group) }}"
                            class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit">
                            <i class="bi bi-pencil"></i>
                            </a>
                            <button type="button" class="btn btn-sm btn-outline-danger btn-icon" title="Delete" aria-label="Delete"
                            data-bs-toggle="modal" data-bs-target="#delete-option-{{ $group->id }}">
                            <i class="bi bi-trash"></i>
                            </button>
                            @endcan
                        </div>
                    </td>
            </tr>
        @empty
            <tr>
                <td colspan="6" class="text-center text-muted py-4">
                    No option groups found.
                </td>
            </tr>
        @endforelse
    </x-adminlte.partials.datatable>

    @foreach ($groups as $group)
        @can('products.options')
            <x-adminlte.partials.confirm-modal
                :id="'delete-option-' . $group->id"
                title="Delete option group"
                :message="'Delete ' . $group->name . '? This permanently removes all of its values and their pricing.'"
                :action="route('admin.product-options.destroy', $group)"
                confirm-label="Delete group"
            />
        @endcan
    @endforeach
@stop
