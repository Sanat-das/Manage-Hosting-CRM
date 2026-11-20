@extends('adminlte::page')

@section('title', 'Device Types')

@section('content_header')
    <x-ui.page-header title="Device Types" subtitle="Manage device models from manufacturers" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Device Types', 'active' => true],
    ]" />
@stop

@section('content')
    @php($canManage = auth()->user()?->can('devices.manage') ?? false)

    <x-adminlte.partials.flash-alert />

    <x-adminlte.partials.datatable
        icon="bi bi-cpu"
        title="All Device Types"
        :search-value="$search"
        search-placeholder="Search manufacturer, model, part number..."
        :columns="[
            ['label' => 'Manufacturer', 'sort' => 'manufacturer'],
            ['label' => 'Model', 'sort' => 'model'],
            ['label' => 'Category', 'sort' => 'category'],
            ['label' => 'Part Number', 'sort' => 'part_number'],
            ['label' => 'U Height', 'sort' => 'u_height'],
            ['label' => 'Power (W)'],
            ['label' => 'Devices', 'class' => 'text-center'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$deviceTypes"
    >
        <x-slot name="tools">
            @if ($canManage)
                <a href="{{ route('admin.device-types.create') }}" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Add Device Type
                </a>
            @endif
        </x-slot>

        @forelse ($deviceTypes as $deviceType)
            <tr>
                <td>{{ $deviceType->manufacturer?->name ?? '—' }}</td>
                <td><strong>{{ $deviceType->model }}</strong></td>
                <td><span class="badge text-bg-info">{{ \App\Models\DeviceType::CATEGORIES[$deviceType->category] ?? $deviceType->category }}</span></td>
                <td class="text-muted">{{ $deviceType->part_number ?? '—' }}</td>
                <td>{{ $deviceType->u_height }}U</td>
                <td>{{ $deviceType->power_draw_watts ?? '—' }}</td>
                <td class="text-center">
                    <a href="{{ route('admin.device-types.show', $deviceType) }}" class="badge text-bg-light border text-decoration-none" title="View devices of this type">{{ $deviceType->devices_count }}</a>
                </td>
                <td class="text-end">
                    @if ($canManage)
                        <div class="table-actions">
                            <a href="{{ route('admin.device-types.edit', $deviceType) }}" class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit"><i class="bi bi-pencil"></i></a>
                            <button type="button" class="btn btn-sm btn-outline-danger btn-icon" title="Delete" aria-label="Delete"
                                    data-bs-toggle="modal" data-bs-target="#delete-device-type-{{ $deviceType->id }}"><i class="bi bi-trash"></i></button>
                        </div>
                    @endif
                </td>
            </tr>
        @empty
            <x-ui.empty-table-row colSpan="8" title="No device types found." />
        @endforelse
    </x-adminlte.partials.datatable>

    @if ($canManage)
        @foreach ($deviceTypes as $deviceType)
            <x-adminlte.partials.confirm-modal
                :id="'delete-device-type-' . $deviceType->id"
                title="Delete device type"
                :message="'Delete ' . $deviceType->manufacturer?->name . ' ' . $deviceType->model . '? ' . $deviceType->devices_count . ' device(s) will lose their link to this type. Assets linked to it keep their record but lose the model.'"
                :action="route('admin.device-types.destroy', $deviceType)"
                confirm-label="Delete device type"
            />
        @endforeach
    @endif
@stop
