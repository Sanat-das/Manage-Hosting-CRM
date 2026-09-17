@extends('adminlte::page')

@section('title', 'Product Bundles')

@section('content_header')
    <x-ui.page-header title="Product Bundles" subtitle="Browse and manage all product bundles" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Product Bundles', 'active' => true],
    ]" />
@stop

@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif
    @if (session('error')) <x-adminlte-alert theme="danger" dismissible>{{ session('error') }}</x-adminlte-alert> @endif

    <x-adminlte.partials.datatable
        icon="bi bi-box-seam"
        title="All Bundles"
        :search-value="$search"
        search-placeholder="Search bundle name..."
        :status-options="['active' => 'Active', 'inactive' => 'Inactive']"
        :status-value="$status"
        :columns="[
            ['label' => 'Name', 'sort' => 'name'],
            ['label' => 'Type'],
            ['label' => 'Components', 'sort' => 'components'],
            ['label' => 'Status', 'sort' => 'status'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$bundles"
    >
        <x-slot name="tools">
            <a href="{{ route('admin.product-bundles.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Bundle</a>
        </x-slot>

        @forelse ($bundles as $bundle)
            <tr>
                <td><a href="{{ route('admin.product-bundles.show', $bundle) }}"><strong>{{ $bundle->name }}</strong></a></td>
                <td><span class="badge text-bg-info">Bundle</span></td>
                <td>{{ $bundle->bundle_children_count }}</td>
                <td><x-adminlte.partials.status-badge :status="$bundle->status" /></td>
                <td class="text-end">
                    <div class="table-actions">
                        <a href="{{ route('admin.product-bundles.edit', $bundle) }}" class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit"><i class="bi bi-pencil"></i></a>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="text-center text-muted py-4">No bundles found.</td></tr>
        @endforelse
    </x-adminlte.partials.datatable>
@stop
