@extends('adminlte::page')

@section('title', 'Product Upgrade Paths')

@section('content_header')
    <x-ui.page-header title="Product Upgrade Paths" subtitle="Browse and manage all upgrade paths" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Product Upgrade Paths', 'active' => true],
    ]" />
@stop

@section('content')
    <x-adminlte.partials.flash-alert />

    <x-adminlte.partials.datatable
        icon="bi bi-arrow-up-right-circle"
        title="All Upgrade Paths"
        :search-value="$search"
        search-placeholder="Search product name..."
        status-placeholder="All paths"
        :status-options="['enabled' => 'Enabled', 'disabled' => 'Disabled']"
        :status-value="$status"
        :columns="[
            ['label' => 'From', 'sort' => 'from'],
            ['label' => ''],
            ['label' => 'To', 'sort' => 'to'],
            ['label' => 'Direction'],
            ['label' => 'Enabled', 'sort' => 'enabled'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$paths"
    >
        <x-slot name="tools">
            <a href="{{ route('admin.product-upgrades.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Upgrade Path</a>
        </x-slot>

        @forelse ($paths as $path)
            <tr>
                <td><a href="{{ route('admin.products.show', $path->fromProduct) }}"><strong>{{ $path->fromProduct->name }}</strong></a></td>
                <td class="text-muted"><i class="bi bi-arrow-right"></i></td>
                <td><a href="{{ route('admin.products.show', $path->toProduct) }}"><strong>{{ $path->toProduct->name }}</strong></a></td>
                <td>
                    @php($directionLabels = ['upgrade' => 'Upgrade', 'downgrade' => 'Downgrade', 'both' => 'Both'])
                    @if ($path->direction === 'upgrade')
                        <span class="badge text-bg-primary">{{ $directionLabels[$path->direction] }}</span>
                    @elseif ($path->direction === 'downgrade')
                        <span class="badge text-bg-warning">{{ $directionLabels[$path->direction] }}</span>
                    @else
                        <span class="badge text-bg-info">{{ $directionLabels[$path->direction] }}</span>
                    @endif
                </td>
                <td>
                    @if ($path->enabled)
                        <span class="badge text-bg-success">Enabled</span>
                    @else
                        <span class="badge text-bg-secondary">Disabled</span>
                    @endif
                </td>
                <td class="text-end">
                    <div class="table-actions">
                        <a href="{{ route('admin.product-upgrades.edit', $path) }}" class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit"><i class="bi bi-pencil"></i></a>
                    </div>
                </td>
            </tr>
        @empty
            <x-ui.empty-table-row colSpan="6" icon="bi bi-arrow-up-right-circle" title="No upgrade paths found." />
        @endforelse
    </x-adminlte.partials.datatable>
@stop
