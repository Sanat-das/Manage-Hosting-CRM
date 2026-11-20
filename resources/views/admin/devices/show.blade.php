@extends('adminlte::page')
@section('title', 'Asset — '.$device->asset_tag)
@section('content_header')
    <x-ui.page-header title="{{ $device->asset_tag }}" subtitle="View device details" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Devices','url' => route('admin.devices.index')],['label' => $device->asset_tag,'active' => true]]" />
@stop
@section('content')
    <x-adminlte.partials.flash-alert />
    @can('devices.manage')
        <div class="d-flex justify-content-end mb-3">
            <a href="{{ route('admin.devices.edit', $device) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i> Edit</a>
        </div>
    @endcan

    {{-- Single-column stack: Details, then IP history. --}}
    <div class="row">
        <div class="col-12">
            <x-adminlte-card icon="bi bi-info-circle" title="Details">
                <table class="table table-sm table-borderless mb-0">
                    <tbody>
                        <tr><th class="text-muted w-25">Asset Tag</th><td>{{ $device->asset_tag }}</td></tr>
                        <tr><th class="text-muted">Category</th><td><span class="badge text-bg-info">{{ $device->deviceType !== null ? (\App\Models\DeviceType::CATEGORIES[$device->deviceType->category] ?? $device->deviceType->category) : '—' }}</span></td></tr>
                        <tr><th class="text-muted">Serial</th><td>{{ $device->serial_number ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Model</th><td>{{ $device->model ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Manufacturer</th><td>{{ $device->manufacturer ?? '—' }}</td></tr>
                        <tr>
                            <th class="text-muted">Device Type</th>
                            <td>
                                @if ($device->deviceType !== null)
                                    <a href="{{ route('admin.device-types.show', $device->deviceType) }}">{{ $device->deviceType->manufacturer?->name ?? '—' }} — {{ $device->deviceType->model }}</a>
                                    @if ($device->deviceType->u_height !== null)
                                        <span class="text-muted">· {{ $device->deviceType->u_height }}U</span>
                                    @endif
                                    @if ($device->deviceType->power_draw_watts !== null)
                                        <span class="text-muted">· {{ $device->deviceType->power_draw_watts }} W</span>
                                    @endif
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                        <tr><th class="text-muted">Vendor</th><td>{{ $device->vendor ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Datacenter</th><td>{{ $device->datacenter?->name ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Rack</th><td>{{ $device->rack?->name ?? '—' }}</td></tr>
                        <tr><th class="text-muted">U Position</th><td>{{ $device->rack_u_position ?? '—' }}</td></tr>
                        @if ($device->face === 'rear')
                            <tr><th class="text-muted">Mounting face</th><td>Rear</td></tr>
                        @endif
                        <tr><th class="text-muted">Purchase Date</th><td>{{ $device->purchase_date?->format('Y-m-d') ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Purchase Cost</th><td>@if ($device->purchase_cost !== null)<x-adminlte.partials.currency :value="$device->purchase_cost" />@else — @endif</td></tr>
                        <tr><th class="text-muted">Warranty Expiry</th><td>{{ $device->warranty_expiry?->format('Y-m-d') ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Status</th><td><x-adminlte.partials.status-badge :status="$device->status" /></td></tr>
                        <tr>
                            <th class="text-muted">Tags</th>
                            <td>
                                @forelse ($device->tags as $tag)
                                    <span class="badge text-white me-1" style="background-color: {{ $tag->color }};">{{ $tag->name }}</span>
                                @empty
                                    —
                                @endforelse
                            </td>
                        </tr>
                        <tr><th class="text-muted">Notes</th><td>{{ $device->notes ?? '—' }}</td></tr>
                    </tbody>
                </table>
            </x-adminlte-card>
        </div>
    </div>

    @if (in_array($device->deviceType?->category, \App\Models\Device::PORT_BEARING_TYPES, true) || $ports->isNotEmpty())
        @include('admin.devices._ports')
    @endif

    {{-- Inventory Items: components installed in this device. --}}
    <div class="row">
        <div class="col-12">
            <x-adminlte-card icon="bi bi-bricks" title="Inventory Items">
                <x-slot name="tools">
                    <span class="badge text-bg-light border me-2">{{ $inventoryItems->count() }} {{ \Illuminate\Support\Str::plural('item', $inventoryItems->count()) }}</span>
                    @can('devices.manage')
                        <a href="{{ route('admin.inventory-items.create', ['device_id' => $device->id]) }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Item</a>
                    @endcan
                </x-slot>
                @if ($inventoryItems->isEmpty())
                    <p class="text-muted mb-0">No inventory items recorded.</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Serial</th>
                                    <th>SKU</th>
                                    <th>Asset Tag</th>
                                    <th>Status</th>
                                    <th>Discovered</th>
                                    @can('devices.manage')
                                        <th class="text-end">Actions</th>
                                    @endcan
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($inventoryItems as $item)
                                    <tr>
                                        <td>
                                            <a href="{{ route('admin.inventory-items.show', $item) }}"><strong>{{ $item->name }}</strong></a>
                                            @if ($item->manufacturer)
                                                <div class="small text-muted">{{ $item->manufacturer->name }}</div>
                                            @endif
                                        </td>
                                        <td class="text-muted">{{ $item->serial_number ?? '—' }}</td>
                                        <td>{{ $item->sku ?? '—' }}</td>
                                        <td>{{ $item->asset_tag ?? '—' }}</td>
                                        <td><x-adminlte.partials.status-badge :status="$item->status" /></td>
                                        <td>
                                            @if ($item->discovered)
                                                <span class="badge text-bg-info">Yes</span>
                                            @else
                                                <span class="badge text-bg-light border">No</span>
                                            @endif
                                        </td>
                                        @can('devices.manage')
                                            <td class="text-end">
                                                <div class="table-actions">
                                                    <a href="{{ route('admin.inventory-items.edit', $item) }}" class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit"><i class="bi bi-pencil"></i></a>
                                                    <button type="button" class="btn btn-sm btn-outline-danger btn-icon" title="Delete" aria-label="Delete"
                                                            data-bs-toggle="modal" data-bs-target="#delete-inventory-item-{{ $item->id }}"><i class="bi bi-trash"></i></button>
                                                </div>
                                            </td>
                                        @endcan
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-adminlte-card>
        </div>
    </div>
    @can('devices.manage')
        @foreach ($inventoryItems as $item)
            <x-adminlte.partials.confirm-modal
                :id="'delete-inventory-item-' . $item->id"
                title="Delete inventory item"
                :message="'Delete ' . $item->name . '? This cannot be undone.'"
                :action="route('admin.inventory-items.destroy', $item)"
                confirm-label="Delete item"
            />
        @endforeach
    @endcan

    <div class="row">
        <div class="col-12">
            <x-adminlte-card icon="bi bi-hdd-network" title="IP Addresses">
                @if ($device->ipAddresses->isEmpty())
                    <x-adminlte.partials.empty-state icon="bi bi-hdd-network" title="No IP addresses linked.">
                        @if (in_array($device->deviceType?->category, \App\Models\Device::IP_TRACKING_TYPES, true))
                            @can('devices.manage')
                                <a href="{{ route('admin.devices.edit', $device) }}">Attach IP addresses</a>
                            @endcan
                        @endif
                    </x-adminlte.partials.empty-state>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>IP Address</th>
                                    <th>Status</th>
                                    <th>Subnet</th>
                                    <th>VLAN</th>
                                    <th>Last Seen</th>
                                    @can('devices.manage')
                                        <th class="text-end">Actions</th>
                                    @endcan
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($device->ipAddresses as $ip)
                                    <tr>
                                        <td><a href="{{ route('admin.ip-addresses.show', $ip) }}"><code>{{ $ip->ip_address }}</code></a></td>
                                        <td>
                                            <x-adminlte.partials.status-badge :status="$ip->type" variant="subtle" />
                                            @if ($ip->status !== $ip->type)
                                                <x-adminlte.partials.status-badge :status="$ip->status" variant="subtle" />
                                            @endif
                                        </td>
                                        <td class="text-muted">{{ $ip->subnet?->name ?? '—' }}</td>
                                        <td class="text-muted">{{ $ip->subnet?->vlan ? ($ip->subnet->vlan->name ?: 'VLAN '.$ip->subnet->vlan->vlan_id).' ('.$ip->subnet->vlan->vlan_id.')' : '—' }}</td>
                                        <td class="text-muted">{{ $ip->last_seen_at?->format('Y-m-d H:i') ?? 'never' }}</td>
                                        @can('devices.manage')
                                            <td class="text-end">
                                                <button type="button" class="btn btn-sm btn-outline-danger" title="Unlink IP" aria-label="Unlink IP"
                                                        data-bs-toggle="modal" data-bs-target="#detach-ip-{{ $ip->id }}"><i class="bi bi-x-lg"></i></button>
                                            </td>
                                        @endcan
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-adminlte-card>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <x-adminlte-card icon="bi bi-clock-history" title="Recent IP allocation history">
                @if ($recentIpHistory->isEmpty())
                    <x-adminlte.partials.empty-state icon="bi bi-clock-history" title="No IP history yet." />
                @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Changed at</th>
                                    <th>IP</th>
                                    <th>Action</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($recentIpHistory as $entry)
                                    <tr>
                                        <td>{{ $entry->changed_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                        <td><code>{{ $entry->ipAddress?->ip_address ?? $entry->ip_address_snapshot }}</code></td>
                                        <td><x-adminlte.partials.status-badge :status="$entry->action" variant="subtle" /></td>
                                        <td class="text-muted">{{ $entry->notes ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-adminlte-card>
        </div>
    </div>

    @if ($history->isNotEmpty())
        <div class="row">
            <div class="col-12">
                <x-adminlte-card icon="bi bi-clock" title="History">
                    @php
                        $formatChangeValue = function (mixed $value): string {
                            if (is_array($value)) {
                                return implode(', ', $value) ?: '—';
                            }

                            if ($value === null || $value === '') {
                                return '—';
                            }

                            return (string) $value;
                        };
                    @endphp
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Changed at</th>
                                    <th>By</th>
                                    <th>Action</th>
                                    <th>Changes</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($history as $entry)
                                    @php
                                        $rawDetails = is_array($entry->details) ? $entry->details : json_decode((string) ($entry->details ?? ''), true);
                                        $details = is_array($rawDetails) ? $rawDetails : [];
                                        $actionBadge = ['created' => 'success', 'updated' => 'info', 'deleted' => 'danger', 'tags_updated' => 'warning'][$entry->action] ?? 'secondary';
                                    @endphp
                                    <tr>
                                        <td>{{ $entry->created_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                        <td>{{ $entry->user?->name ?? 'System' }}</td>
                                        <td><span class="badge text-bg-{{ $actionBadge }}">{{ str_replace('_', ' ', $entry->action) }}</span>@if ($entry->entity_type === 'inventory_item')<span class="badge text-bg-secondary ms-1" title="Inventory item">item</span>@endif</td>
                                        <td>
                                            @if (array_key_exists('snapshot', $details))
                                                <span class="text-muted small">Snapshot recorded before delete.</span>
                                            @else
                                                <ul class="small mb-0 ps-3">
                                                    @foreach ($details as $field => $change)
                                                        @if ($entry->action === 'created')
                                                            <li><code>{{ $field }}</code>: {{ $formatChangeValue($change['new'] ?? null) }}</li>
                                                        @else
                                                            <li><code>{{ $field }}</code>: {{ $formatChangeValue($change['old'] ?? null) }} → {{ $formatChangeValue($change['new'] ?? null) }}</li>
                                                        @endif
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-adminlte-card>
            </div>
        </div>
    @endif

    {{-- Unlink-IP confirm modals, rendered outside the IP card. --}}
    @can('devices.manage')
        @foreach ($device->ipAddresses as $ip)
            <x-adminlte.partials.confirm-modal
                :id="'detach-ip-' . $ip->id"
                title="Unlink IP"
                :message="'Unlink ' . $ip->ip_address . ' from this asset? The IP stays in IP Manager.'"
                :action="route('admin.devices.detach-ip', [$device, $ip])"
                confirm-label="Unlink"
            />
        @endforeach
    @endcan
@stop

