@extends('adminlte::page')

@section('title', 'Product Groups')

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Product Groups</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">Product Groups</li>
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
        icon="bi bi-collection"
        title="All Product Groups"
        :search-value="$search"
        search-placeholder="Search group name..."
        :columns="[
            ['label' => 'Name'],
            ['label' => 'Slug'],
            ['label' => 'Parent'],
            ['label' => 'Products', 'class' => 'text-end'],
            ['label' => 'Sort Order'],
            ['label' => 'Status'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$groups"
    >
        <x-slot name="tools">
            @can('products.groups')
                <a href="{{ route('admin.product-groups.create') }}" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Add Group
                </a>
            @endcan
        </x-slot>

        @forelse ($groups as $group)
            <tr>
                <td>
                    <a href="{{ route('admin.products.index', ['group_id' => $group->id]) }}"><strong>{{ $group->name }}</strong></a>
                    @if ($group->description)
                        <div class="text-muted small">{{ $group->description }}</div>
                    @endif
                </td>
                <td class="text-muted">{{ $group->slug }}</td>
                <td class="text-muted">{{ $group->parent?->name ?? '—' }}</td>
                <td class="text-end">{{ $group->products_count }}</td>
                <td class="text-muted">{{ $group->sort_order }}</td>
                <td>
                    <x-adminlte.partials.status-badge :status="$group->status" />
                </td>
                <td class="text-end">
                    @can('products.groups')
                        <a href="{{ route('admin.product-groups.edit', $group) }}"
                           class="btn btn-sm btn-outline-secondary" title="Edit">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <button type="button" class="btn btn-sm btn-outline-danger" title="Delete"
                                data-bs-toggle="modal" data-bs-target="#delete-group-{{ $group->id }}">
                            <i class="bi bi-trash"></i>
                        </button>
                    @endcan
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="7" class="text-center text-muted py-4">
                    No product groups found.
                </td>
            </tr>
        @endforelse
    </x-adminlte.partials.datatable>

    @foreach ($groups as $group)
        @can('products.groups')
            <x-adminlte.partials.confirm-modal
                :id="'delete-group-' . $group->id"
                title="Delete product group"
                :message="'Delete ' . $group->name . '? Groups that still contain products cannot be deleted.'"
                :action="route('admin.product-groups.destroy', $group)"
                confirm-label="Delete group"
            />
        @endcan
    @endforeach
@stop
