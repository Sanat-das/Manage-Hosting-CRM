@extends('adminlte::page')

@section('title', 'Inventory & Capacity Report')

@section('content_header')
    <x-ui.page-header title="Inventory & Capacity Report" subtitle="Device, rack, license and IPAM utilization overview" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Reports'],['label' => 'Inventory & Capacity Report','active' => true]]" />
@stop

@section('content')
    @can('reports.export')
        <div class="mb-3">
            <a href="{{ route('admin.reports.export', ['type' => 'inventory', 'from' => $from ?? now()->startOfYear()->toDateString(), 'to' => $to ?? now()->toDateString()]) }}" class="btn btn-outline-success"><i class="bi bi-download me-1"></i> Export CSV</a>
        </div>
    @endcan

    <div class="row mb-4">
        <div class="col-md-6">
            <x-adminlte-card icon="bi bi-box-seam" title="Devices by Category">
                <table class="table table-sm mb-0">
                    <tbody>
                        @forelse ($assetsByCategory as $category => $count)
                            <tr><td class="text-capitalize">{{ str_replace('_', ' ', $category) }}</td><td class="text-end fw-bold">{{ $count }}</td></tr>
                        @empty
                            <x-ui.empty-table-row colSpan="2" title="No devices." />
                        @endforelse
                    </tbody>
                </table>
            </x-adminlte-card>
        </div>
        <div class="col-md-6">
            <x-adminlte-card icon="bi bi-pie-chart" title="Assets by Status">
                <table class="table table-sm mb-0">
                    <tbody>
                        @forelse ($assetsByStatus as $status => $count)
                            <tr><td class="text-capitalize">{{ str_replace('_', ' ', $status) }}</td><td class="text-end fw-bold">{{ $count }}</td></tr>
                        @empty
                            <x-ui.empty-table-row colSpan="2" title="No assets." />
                        @endforelse
                    </tbody>
                </table>
            </x-adminlte-card>
        </div>
    </div>

    <x-adminlte-card icon="bi bi-hdd-rack" title="Racks & Utilization">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Rack</th><th>Datacenter</th><th class="text-end">U Height</th><th class="text-end">Assets</th></tr></thead>
            <tbody>
                @forelse ($racks as $rack)
                    <tr>
                        <td><strong>{{ $rack->name }}</strong></td>
                        <td>{{ $rack->datacenter?->name ?? '—' }}</td>
                        <td class="text-end">{{ $rack->u_height }}U</td>
                        <td class="text-end fw-bold">{{ $rack->devices_count }}</td>
                    </tr>
                @empty
                    <x-ui.empty-table-row colSpan="4" title="No racks defined." />
                @endforelse
            </tbody>
        </table>
    </x-adminlte-card>

    <div class="row mb-4">
        <div class="col-md-4">
            <x-adminlte-card icon="bi bi-key" title="Licenses by Status">
                <table class="table table-sm mb-0">
                    <tbody>
                        @forelse ($licensesByStatus as $status => $count)
                            <tr><td class="text-capitalize">{{ $status }}</td><td class="text-end fw-bold">{{ $count }}</td></tr>
                        @empty
                            <x-ui.empty-table-row colSpan="2" title="No licenses." />
                        @endforelse
                    </tbody>
                </table>
            </x-adminlte-card>
        </div>
        <div class="col-md-4">
            <x-adminlte-card icon="bi bi-clock-history" title="Licenses Expiring (30 Days)">
                <table class="table table-sm mb-0">
                    <tbody>
                        @forelse ($licensesExpiring as $license)
                            <tr>
                                <td class="text-capitalize">{{ $license->license_type }}</td>
                                <td class="text-warning fw-bold text-end">{{ $license->expiry_date?->format('M j, Y') }}</td>
                            </tr>
                        @empty
                            <x-ui.empty-table-row colSpan="2" title="No licenses expiring soon." />
                        @endforelse
                    </tbody>
                </table>
            </x-adminlte-card>
        </div>
        <div class="col-md-4">
            <x-adminlte-card icon="bi bi-exclamation-triangle" title="Seats Exhausted">
                <table class="table table-sm mb-0">
                    <tbody>
                        @forelse ($licensesExhausted as $license)
                            <tr>
                                <td class="text-capitalize">{{ $license->license_type }}</td>
                                <td class="text-end fw-bold">{{ $license->seats_available }} / {{ $license->seats }}</td>
                            </tr>
                        @empty
                            <x-ui.empty-table-row colSpan="2" title="No exhausted licenses." />
                        @endforelse
                    </tbody>
                </table>
            </x-adminlte-card>
        </div>
    </div>

    <x-adminlte-card icon="bi bi-diagram-3" title="Subnets & VLANs">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Subnet</th><th>CIDR</th><th class="text-end">Used</th><th class="text-end">Capacity</th></tr></thead>
            <tbody>
                @forelse ($subnets as $subnet)
                    @php
                        $subnetUsed = (int) $subnet->assigned_count + (int) $subnet->reserved_count_live;
                    @endphp
                    <tr>
                        <td><strong>{{ $subnet->name }}</strong></td>
                        <td class="text-muted">{{ $subnet->subnet_cidr }}</td>
                        <td class="text-end fw-bold">{{ $subnetUsed }}</td>
                        <td class="text-end">{{ (int) $subnet->total_addresses }}</td>
                    </tr>
                @empty
                    <x-ui.empty-table-row colSpan="4" title="No subnets defined." />
                @endforelse
            </tbody>
        </table>
        <div class="text-muted mt-2">{{ $vlanCount }} VLAN{{ $vlanCount === 1 ? '' : 's' }} defined.</div>
    </x-adminlte-card>
@stop
