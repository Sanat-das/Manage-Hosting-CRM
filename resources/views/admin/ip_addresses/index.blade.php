@extends('adminlte::page')

@section('title', 'IP Addresses')

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">IP Addresses</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">IP Addresses</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif
    @if ($errors->has('assign') || $errors->has('delete')) <x-adminlte-alert theme="danger" dismissible>{{ $errors->first('assign') ?? $errors->first('delete') }}</x-adminlte-alert> @endif

    <x-adminlte.partials.datatable
        icon="bi bi-geo-alt"
        title="All IP Addresses"
        :search-value="$search"
        search-placeholder="Search IP, PTR, subnet..."
        :columns="[
            ['label' => 'IP Address', 'sort' => 'ip_address'],
            ['label' => 'Version', 'sort' => 'ip_version'],
            ['label' => 'Subnet', 'sort' => 'subnet'],
            ['label' => 'VLAN', 'sort' => 'vlan'],
            ['label' => 'PTR', 'sort' => 'ptr_record'],
            ['label' => 'Status', 'sort' => 'status'],
            ['label' => 'Assigned To', 'sort' => 'assigned_to'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$addresses"
    >
        <x-slot name="tools">
            <form method="GET" action="{{ url()->current() }}" class="d-inline-flex align-items-center gap-2 me-2">
                <input type="hidden" name="search" value="{{ $search }}">
                @if(request('sort'))<input type="hidden" name="sort" value="{{ request('sort') }}">@endif
                @if(request('direction'))<input type="hidden" name="direction" value="{{ request('direction') }}">@endif
                @if(request('vlan_id') !== null && request('vlan_id') !== '')<input type="hidden" name="vlan_id" value="{{ request('vlan_id') }}">@endif
                <select name="subnet_id" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Filter by subnet">
                    <option value="">All Subnets</option>
                    @foreach ($subnets as $sub)
                        <option value="{{ $sub->id }}" @selected((string) request('subnet_id') === (string) $sub->id)>{{ $sub->subnet_cidr }} — {{ $sub->name }}</option>
                    @endforeach
                </select>
            </form>
            <form method="GET" action="{{ url()->current() }}" class="d-inline-flex align-items-center gap-2 me-2">
                <input type="hidden" name="search" value="{{ $search }}">
                @if(request('sort'))<input type="hidden" name="sort" value="{{ request('sort') }}">@endif
                @if(request('direction'))<input type="hidden" name="direction" value="{{ request('direction') }}">@endif
                @if(request('subnet_id') !== null && request('subnet_id') !== '')<input type="hidden" name="subnet_id" value="{{ request('subnet_id') }}">@endif
                <select name="vlan_id" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Filter by VLAN">
                    <option value="">All VLANs</option>
                    @foreach ($vlans as $vlan)
                        <option value="{{ $vlan->id }}" @selected((string) request('vlan_id') === (string) $vlan->id)>{{ $vlan->name }} (ID {{ $vlan->vlan_id }})</option>
                    @endforeach
                </select>
            </form>
            <a href="{{ route('admin.ip-addresses.create') }}" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Add IP Address
            </a>
        </x-slot>

        @forelse ($addresses as $ip)
            <tr>
                <td><a href="{{ route('admin.ip-addresses.show', $ip) }}"><strong>{{ $ip->ip_address }}</strong></a></td>
                <td><span class="badge text-bg-info">IPv{{ $ip->ip_version }}</span></td>
                <td title="{{ $ip->subnet?->subnet_cidr ? $ip->subnet->subnet_cidr.' ('.$ip->subnet->name.')' : '' }}">{{ $ip->subnet?->subnet_cidr ? $ip->subnet->subnet_cidr.' ('.$ip->subnet->name.')' : '—' }}</td>
                <td>
                    @if ($ip->subnet?->vlan)
                        <span class="badge text-bg-secondary text-truncate d-inline-block" style="max-width:125px">{{ $ip->subnet->vlan->name }} ({{ $ip->subnet->vlan->vlan_id }})</span>
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </td>
                <td class="text-muted small text-center">{{ $ip->ptr_record ? '•' : '—' }}</td>
                <td><x-adminlte.partials.status-badge :status="$ip->status" /></td>
                <td>
                    @if ($ip->assigned_to_type)
                        @if ($ip->assigned_to_type === 'App\Models\HostingAccount' && $ip->assignedTo)
                            <a href="{{ route('admin.hosting.show', $ip->assignedTo) }}" class="text-decoration-none" title="View hosting account {{ $ip->assignedTo->domain }}">
                                <span class="badge text-bg-primary">HostingAccount #{{ $ip->assigned_to_id }}</span>
                                <div class="small text-primary text-truncate" style="max-width:110px">{{ $ip->assignedTo->domain ?? 'somenath.vm' }}</div>
                            </a>
                        @else
                            <span class="badge text-bg-primary">{{ class_basename($ip->assigned_to_type) }} #{{ $ip->assigned_to_id }}</span>
                            @if ($ip->assignedTo)
                                <div class="small text-muted text-truncate" style="max-width:110px">{{ $ip->assignedTo->domain ?? '' }}</div>
                            @endif
                        @endif
                    @else
                        <span class="text-muted small">— Unassigned</span>
                    @endif
                </td>
                <td class="text-end">
                    <div class="table-actions">
                        @if ($ip->assigned_to_type)
                            <button type="button" class="btn btn-sm btn-outline-warning btn-icon" title="Release / Unassign" aria-label="Release / Unassign"
                                    data-bs-toggle="modal" data-bs-target="#release-ip-{{ $ip->id }}"><i class="bi bi-box-arrow-right"></i></button>
                        @else
                            <a href="{{ route('admin.ip-addresses.show', $ip) }}" class="btn btn-sm btn-outline-primary btn-icon" title="Assign to hosting account" aria-label="Assign to hosting account"><i class="bi bi-link-45deg"></i></a>
                        @endif
                        <a href="{{ route('admin.ip-addresses.edit', $ip) }}" class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit"><i class="bi bi-pencil"></i></a>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="8" class="text-center text-muted py-4">No IP addresses found.</td></tr>
        @endforelse
    </x-adminlte.partials.datatable>

    @foreach ($addresses as $ip)
        @if ($ip->assigned_to_type)
            <x-adminlte.partials.confirm-modal
                :id="'release-ip-' . $ip->id"
                title="Release IP address"
                :message="'Release ' . $ip->ip_address . '? The status will change to available.'"
                method="POST"
                :action="route('admin.ip-addresses.release', $ip)"
                confirm-label="Release IP"
                confirm-theme="warning"
            />
        @endif
    @endforeach
@stop
