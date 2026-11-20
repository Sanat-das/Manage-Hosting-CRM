@extends('adminlte::page')
@section('title', 'Device Type — '.($deviceType->manufacturer?->name ?? '—').' '.$deviceType->model)
@section('content_header')
    <x-ui.page-header title="Device Type — {{ $deviceType->manufacturer?->name ?? '—' }} {{ $deviceType->model }}" subtitle="View device type details" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Device Types','url' => route('admin.device-types.index')],['label' => 'View','active' => true]]" />
@stop
@section('content')
    <x-adminlte.partials.flash-alert />

    <div class="row">
        <div class="col-12">
            <x-adminlte-card icon="bi bi-cpu" title="Device Type">
                <x-slot name="tools">
                    @can('devices.manage')
                        <div class="d-flex gap-2">
                            <a href="{{ route('admin.device-types.edit', $deviceType) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i> Edit</a>
                            <button type="button" class="btn btn-sm btn-outline-danger"
                                    data-bs-toggle="modal" data-bs-target="#delete-device-type-{{ $deviceType->id }}"><i class="bi bi-trash me-1"></i> Delete</button>
                        </div>
                    @endcan
                </x-slot>
                <table class="table table-sm table-borderless mb-0">
                    <tbody>
                        <tr><th class="text-muted w-25">Manufacturer</th><td>{{ $deviceType->manufacturer?->name ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Model</th><td><strong>{{ $deviceType->model }}</strong></td></tr>
                        <tr><th class="text-muted">Category</th><td><span class="badge text-bg-info">{{ \App\Models\DeviceType::CATEGORIES[$deviceType->category] ?? $deviceType->category }}</span></td></tr>
                        <tr><th class="text-muted">Slug</th><td><code>{{ $deviceType->slug }}</code></td></tr>
                        <tr><th class="text-muted">Part Number</th><td>{{ $deviceType->part_number ?? '—' }}</td></tr>
                        <tr><th class="text-muted">U Height</th><td>{{ $deviceType->u_height }}U</td></tr>
                        <tr><th class="text-muted">Power Draw (W)</th><td>{{ $deviceType->power_draw_watts ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Full Depth</th><td>{{ $deviceType->is_full_depth ? 'Yes' : 'No' }}</td></tr>
                        <tr><th class="text-muted">Description</th><td>{{ $deviceType->description ?? '—' }}</td></tr>
                    </tbody>
                </table>
            </x-adminlte-card>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <x-adminlte-card icon="bi bi-box-seam" title="Devices">
                <x-slot name="tools">
                    <span class="badge text-bg-light border">{{ $devices->total() }} {{ \Illuminate\Support\Str::plural('device', $devices->total()) }}</span>
                </x-slot>
                @if ($devices->isEmpty())
                    <p class="text-muted mb-0">No devices use this device type yet.</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Asset Tag</th>
                                    <th>Manufacturer</th>
                                    <th>Model</th>
                                    <th>Status</th>
                                    <th>Rack / U</th>
                                    <th>Warranty Expiry</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($devices as $device)
                                    <tr>
                                        <td><a href="{{ route('admin.devices.show', $device) }}"><strong>{{ $device->asset_tag }}</strong></a></td>
                                        <td>{{ $device->manufacturer ?? '—' }}</td>
                                        <td>{{ $device->model ?? '—' }}</td>
                                        <td><x-adminlte.partials.status-badge :status="$device->status" /></td>
                                        <td>
                                            {{ $device->rack?->name ?? '—' }}@if ($device->rack_u_position) · U{{ $device->rack_u_position }}@endif
                                        </td>
                                        <td>{{ $device->warranty_expiry?->format('Y-m-d') ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3">{{ $devices->links() }}</div>
                @endif
            </x-adminlte-card>
        </div>
    </div>

    @can('devices.manage')
        <x-adminlte.partials.confirm-modal
            :id="'delete-device-type-' . $deviceType->id"
            title="Delete device type"
            :message="'Delete ' . $deviceType->manufacturer?->name . ' ' . $deviceType->model . '? ' . $devices->total() . ' device(s) will lose their link to this type. Assets keep their record but lose the model.'"
            :action="route('admin.device-types.destroy', $deviceType)"
            confirm-label="Delete device type"
        />
    @endcan
@stop
