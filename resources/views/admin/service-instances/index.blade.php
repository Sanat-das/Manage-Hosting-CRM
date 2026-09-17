@extends('adminlte::page')
@section('title', 'Service Instances')
@section('content_header')
    <x-ui.page-header title="Service Instances" subtitle="Manage service instances inventory" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Service Instances', 'active' => true],
    ]" />
@stop
@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif

    <x-adminlte.partials.datatable
        icon="bi bi-hdd-network"
        title="All Services"
        :search-value="$search"
        search-placeholder="Search username, domain, email..."
        :status-options="['active' => 'Active', 'suspended' => 'Suspended', 'cancelled' => 'Cancelled', 'terminated' => 'Terminated', 'pending' => 'Pending']"
        :status-value="$status"
        :columns="[
            ['label' => 'ID', 'sort' => 'id'],
            ['label' => 'Domain / Username', 'sort' => 'domain'],
            ['label' => 'Customer'],
            ['label' => 'Product', 'sort' => 'product'],
            ['label' => 'Server', 'sort' => 'server'],
            ['label' => 'Provision'],
            ['label' => 'Status', 'sort' => 'status'],
        ]"
        :pagination="$instances"
    >
        @forelse ($instances as $inst)
            <tr>
                <td><a href="{{ route('admin.service-instances.show', $inst) }}"><strong>#{{ $inst->id }}</strong></a></td>
                <td>{{ $inst->domain ?? $inst->username ?? '—' }}</td>
                <td>{{ $inst->customer?->full_name ?? '—' }}</td>
                <td>{{ $inst->catalogProduct?->name ?? '—' }}</td>
                <td>{{ $inst->server?->name ?? '—' }}</td>
                <td><x-adminlte.partials.status-badge :status="$inst->provision_status" /></td>
                <td><x-adminlte.partials.status-badge :status="$inst->status" /></td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-center text-muted py-4">No service instances found.</td></tr>
        @endforelse
    </x-adminlte.partials.datatable>
@stop
