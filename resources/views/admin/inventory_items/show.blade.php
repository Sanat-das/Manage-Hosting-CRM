@extends('adminlte::page')
@section('title', $item->name)
@section('content_header')
    <x-ui.page-header title="{{ $item->name }}" subtitle="View inventory item details" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Inventory Items','url' => route('admin.inventory-items.index')],['label' => $item->name,'active' => true]]" />
@stop
@section('content')
    <x-adminlte.partials.flash-alert />
    @can('devices.manage')
        <div class="d-flex justify-content-end mb-3">
            <a href="{{ route('admin.inventory-items.edit', $item) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i> Edit</a>
        </div>
    @endcan

    <x-adminlte-card icon="bi bi-bricks" title="Inventory Items">
        <table class="table table-sm table-borderless mb-0">
            <tbody>
                <tr>
                    <th class="text-muted w-25">Device</th>
                    <td>
                        @if ($item->device)
                            <a href="{{ route('admin.devices.show', $item->device) }}"><strong>{{ $item->device->asset_tag }}</strong></a>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                </tr>
                <tr>
                    <th class="text-muted">Parent Item</th>
                    <td>
                        @if ($item->parent)
                            <a href="{{ route('admin.inventory-items.show', $item->parent) }}">{{ $item->parent->name }}</a>
                        @else
                            —
                        @endif
                    </td>
                </tr>
                <tr><th class="text-muted">Label</th><td>{{ $item->label ?? '—' }}</td></tr>
                <tr><th class="text-muted">SKU</th><td>{{ $item->sku ?? '—' }}</td></tr>
                <tr><th class="text-muted">Part Number</th><td>{{ $item->part_number ?? '—' }}</td></tr>
                <tr><th class="text-muted">Serial Number</th><td>{{ $item->serial_number ?? '—' }}</td></tr>
                <tr><th class="text-muted">Asset Tag</th><td>{{ $item->asset_tag ?? '—' }}</td></tr>
                <tr><th class="text-muted">Manufacturer</th><td>{{ $item->manufacturer?->name ?? '—' }}</td></tr>
                <tr><th class="text-muted">Status</th><td><x-adminlte.partials.status-badge :status="$item->status" /></td></tr>
                <tr>
                    <th class="text-muted">Discovered</th>
                    <td>
                        @if ($item->discovered)
                            <span class="badge text-bg-info">Yes</span>
                        @else
                            <span class="badge text-bg-light border">No</span>
                        @endif
                    </td>
                </tr>
                <tr><th class="text-muted">Description</th><td>{{ $item->description ?? '—' }}</td></tr>
                <tr><th class="text-muted">Notes</th><td>{{ $item->notes ?? '—' }}</td></tr>
                <tr><th class="text-muted">Created</th><td>{{ $item->created_at?->format('M j, Y H:i') ?? '—' }}</td></tr>
                <tr><th class="text-muted">Updated</th><td>{{ $item->updated_at?->format('M j, Y H:i') ?? '—' }}</td></tr>
            </tbody>
        </table>
    </x-adminlte-card>

    <x-adminlte-card icon="bi bi-bricks" title="Child Items">
        @if ($children->isEmpty())
            <p class="text-muted mb-0">No child items.</p>
        @else
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Device</th>
                            <th>Serial</th>
                            <th>SKU</th>
                            <th>Status</th>
                            <th>Discovered</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($children as $child)
                            <tr>
                                <td><a href="{{ route('admin.inventory-items.show', $child) }}"><strong>{{ $child->name }}</strong></a></td>
                                <td>
                                    @if ($child->device)
                                        <a href="{{ route('admin.devices.show', $child->device) }}">{{ $child->device->asset_tag }}</a>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-muted">{{ $child->serial_number ?? '—' }}</td>
                                <td>{{ $child->sku ?? '—' }}</td>
                                <td><x-adminlte.partials.status-badge :status="$child->status" /></td>
                                <td>
                                    @if ($child->discovered)
                                        <span class="badge text-bg-info">Yes</span>
                                    @else
                                        <span class="badge text-bg-light border">No</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-adminlte-card>
@stop
