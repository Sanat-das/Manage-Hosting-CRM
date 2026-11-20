@extends('adminlte::page')

@section('title', 'Manufacturers')

@section('content_header')
    <x-ui.page-header title="Manufacturers" subtitle="Manage hardware manufacturers" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Manufacturers', 'active' => true],
    ]" />
@stop

@section('content')
    @php($canManage = auth()->user()?->can('devices.manage') ?? false)

    <x-adminlte.partials.flash-alert />

    <x-adminlte.partials.datatable
        icon="bi bi-building"
        title="All Manufacturers"
        :search-value="$search"
        search-placeholder="Search name, slug, description..."
        :columns="[
            ['label' => 'Name', 'sort' => 'name'],
            ['label' => 'Slug', 'sort' => 'slug'],
            ['label' => 'Description', 'sort' => 'description'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$manufacturers"
    >
        <x-slot name="tools">
            @if ($canManage)
                <a href="{{ route('admin.manufacturers.create') }}" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Add Manufacturer
                </a>
            @endif
        </x-slot>

        @forelse ($manufacturers as $manufacturer)
            <tr>
                <td><strong>{{ $manufacturer->name }}</strong></td>
                <td><code>{{ $manufacturer->slug }}</code></td>
                <td class="text-muted">{{ $manufacturer->description ?? '—' }}</td>
                <td class="text-end">
                    @if ($canManage)
                        <div class="table-actions">
                            <a href="{{ route('admin.manufacturers.edit', $manufacturer) }}" class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit"><i class="bi bi-pencil"></i></a>
                            <button type="button" class="btn btn-sm btn-outline-danger btn-icon" title="Delete" aria-label="Delete"
                                    data-bs-toggle="modal" data-bs-target="#delete-manufacturer-{{ $manufacturer->id }}"><i class="bi bi-trash"></i></button>
                        </div>
                    @endif
                </td>
            </tr>
        @empty
            <x-ui.empty-table-row colSpan="4" title="No manufacturers found." />
        @endforelse
    </x-adminlte.partials.datatable>

    @if ($canManage)
        @foreach ($manufacturers as $manufacturer)
            @php($impactCounts = [
                'Device types (deleted with it)' => (int) ($manufacturer->device_types_count ?? 0),
                'Devices losing device-type link' => (int) ($manufacturer->devices_affected ?? 0),
                'Inventory items losing manufacturer link' => (int) ($manufacturer->items_affected ?? 0),
            ])
            <x-adminlte.partials.confirm-modal
                :id="'delete-manufacturer-' . $manufacturer->id"
                title="Delete manufacturer"
                :message="'Delete ' . $manufacturer->name . '? Its device types are deleted with it (FK cascade). Devices and inventory items keep their records but lose the link (FK nullOnDelete).'"
                :action="route('admin.manufacturers.destroy', $manufacturer)"
                confirm-label="Delete manufacturer">
                @include('admin.partials._typed_confirm', ['name' => $manufacturer->name, 'counts' => $impactCounts])
            </x-adminlte.partials.confirm-modal>
        @endforeach
    @endif
@stop
