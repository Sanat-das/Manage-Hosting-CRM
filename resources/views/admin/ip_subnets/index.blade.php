@extends('adminlte::page')
@section('title', 'IP Subnets')
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">IP Subnets</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item active">IP Subnets</li></ol></div></div>
@stop
@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif
    @if ($errors->has('delete')) <x-adminlte-alert theme="danger" dismissible>{{ $errors->first('delete') }}</x-adminlte-alert> @endif

    <x-adminlte.partials.datatable
        icon="bi bi-grid"
        title="All Subnets"
        :search-value="$search"
        search-placeholder="Search subnet, CIDR, gateway..."
        :columns="[
            ['label' => 'Name', 'sort' => 'name'],
            ['label' => 'CIDR', 'sort' => 'subnet_cidr'],
            ['label' => 'Gateway', 'sort' => 'gateway'],
            ['label' => 'Version', 'sort' => 'ip_version'],
            ['label' => 'Type', 'sort' => 'network_type'],
            ['label' => 'Total IPs', 'sort' => 'total_addresses'],
            ['label' => 'VLAN', 'sort' => 'vlan'],
            ['label' => 'Datacenter', 'sort' => 'datacenter'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$subnets"
    >
        <x-slot name="tools">
            <form method="GET" action="{{ url()->current() }}" class="d-inline-flex align-items-center gap-2 me-2">
                <input type="hidden" name="search" value="{{ $search }}">
                @if(request()->query('sort'))
                    <input type="hidden" name="sort" value="{{ request()->query('sort') }}">
                    <input type="hidden" name="direction" value="{{ request()->query('direction') }}">
                @endif
                @if(request()->filled('ip_version'))
                    <input type="hidden" name="ip_version" value="{{ request()->query('ip_version') }}">
                @endif
                <select name="datacenter_id" class="form-select form-select-sm w-auto" onchange="this.form.submit()" aria-label="Filter by datacenter">
                    <option value="">All Datacenters</option>
                    @foreach ($datacenters as $dc)
                        <option value="{{ $dc->id }}" @selected((string) request()->query('datacenter_id') === (string) $dc->id)>{{ $dc->name }}</option>
                    @endforeach
                </select>
            </form>
            <a href="{{ route('admin.ip-subnets.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Subnet</a>
        </x-slot>

        @forelse ($subnets as $subnet)
            <tr>
                <td><a href="{{ route('admin.ip-subnets.show', $subnet) }}"><strong>{{ $subnet->name }}</strong></a></td>
                <td><code>{{ $subnet->subnet_cidr }}</code></td>
                <td>{{ $subnet->gateway ?? '—' }}</td>
                <td>IPv{{ $subnet->ip_version }}</td>
                <td><span class="badge text-bg-info">{{ ucfirst($subnet->network_type) }}</span></td>
                <td>{{ $subnet->total_addresses ?? '—' }}</td>
                <td>{{ $subnet->vlan?->name ?? '—' }}</td>
                <td>{{ $subnet->datacenter?->name ?? '—' }}</td>
                <td class="text-end">
                    <div class="table-actions">
                        <a href="{{ route('admin.ip-subnets.edit', $subnet) }}" class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit"><i class="bi bi-pencil"></i></a>
                        <button type="button" class="btn btn-sm btn-outline-danger btn-icon" title="Delete" aria-label="Delete"
                                data-bs-toggle="modal" data-bs-target="#delete-subnet-{{ $subnet->id }}"><i class="bi bi-trash"></i></button>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="9" class="text-center text-muted py-4">No subnets found.</td></tr>
        @endforelse
    </x-adminlte.partials.datatable>

    @foreach ($subnets as $subnet)
        <x-adminlte.partials.confirm-modal
            :id="'delete-subnet-' . $subnet->id"
            title="Delete subnet"
            :message="'Delete subnet ' . $subnet->subnet_cidr . ' and all its ' . ($subnet->ipAddresses_count ?? $subnet->ipAddresses->count()) . ' IPs? This cannot be undone. Assigned IPs will block deletion.'"
            :action="route('admin.ip-subnets.destroy', $subnet)"
            confirm-label="Delete subnet"
        />
    @endforeach
@stop
