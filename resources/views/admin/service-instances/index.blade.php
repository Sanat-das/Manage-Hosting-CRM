@extends('adminlte::page')
@section('title', 'Service Instances')
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">Service Instances</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item active">Service Instances</li></ol></div></div>
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
