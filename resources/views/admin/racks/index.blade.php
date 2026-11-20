@extends('adminlte::page')

@section('title', 'Racks')

@section('content_header')
    <x-ui.page-header title="Racks" subtitle="Manage racks inventory" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Racks', 'active' => true],
    ]" />
@stop

@section('content')
    @php($canManage = auth()->user()?->can('racks.manage') ?? false)

    <x-adminlte.partials.flash-alert />

    <x-adminlte.partials.datatable
        icon="bi bi-hdd-stack"
        title="All Racks"
        :search-value="$search"
        search-placeholder="Search rack name..."
        :columns="[
            ['label' => 'ID', 'sort' => 'id'],
            ['label' => 'Name', 'sort' => 'name'],
            ['label' => 'Datacenter', 'sort' => 'datacenter'],
            ['label' => 'U Height', 'sort' => 'u_height'],
            ['label' => 'Assets'],
            ['label' => 'Power (W)', 'sort' => 'power'],
            ['label' => 'Status', 'sort' => 'status'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$racks"
    >
        <x-slot name="tools">
            <form method="GET" action="{{ url()->current() }}" class="d-inline-flex align-items-center gap-2 me-2">
                <input type="hidden" name="search" value="{{ $search }}">
                @if(request('sort'))<input type="hidden" name="sort" value="{{ request('sort') }}">@endif
                @if(request('direction'))<input type="hidden" name="direction" value="{{ request('direction') }}">@endif
                <select name="datacenter_id" class="form-select form-select-sm w-auto" onchange="this.form.submit()" aria-label="Filter by datacenter">
                    <option value="">All Datacenters</option>
                    @foreach ($datacenters as $dc)
                        <option value="{{ $dc->id }}" @selected((string) request('datacenter_id') === (string) $dc->id)>{{ $dc->name }}</option>
                    @endforeach
                </select>
            </form>
            <a href="{{ route('admin.racks.create') }}" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg me-1"></i> Add Rack
            </a>
        </x-slot>

        @forelse ($racks as $rack)
            <tr>
                <td>{{ $rack->id }}</td>
                <td><a href="{{ route('admin.racks.show', $rack) }}"><strong>{{ $rack->name }}</strong></a></td>
                <td>{{ $rack->datacenter?->name ?? '—' }}</td>
                <td>{{ $rack->u_height ?? '—' }}</td>
                <td>{{ $rack->devices_count }}</td>
                <td>{{ $rack->power_capacity_watts ?? '—' }}</td>
                <td><x-adminlte.partials.status-badge :status="$rack->status" /></td>
                <td class="text-end">
                    <div class="table-actions">
                        <a href="{{ route('admin.racks.edit', $rack) }}" class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit"><i class="bi bi-pencil"></i></a>
                        @if ($canManage)
                            <button type="button" class="btn btn-sm btn-outline-danger btn-icon" title="Delete" aria-label="Delete"
                                    data-bs-toggle="modal" data-bs-target="#delete-rack-{{ $rack->id }}"><i class="bi bi-trash"></i></button>
                        @endif
                    </div>
                </td>
            </tr>
        @empty
            <x-ui.empty-table-row colSpan="8" title="No racks found." />
        @endforelse
    </x-adminlte.partials.datatable>

    @if ($canManage)
        @foreach ($racks as $rack)
            @php($impactCounts = [
                'Devices in this rack' => (int) ($rack->devices_count ?? 0),
            ])
            <x-adminlte.partials.confirm-modal
                :id="'delete-rack-' . $rack->id"
                title="Delete rack"
                :message="'Delete ' . $rack->name . '? Devices in it are NOT deleted — they keep a dangling rack_id (no FK is enforced).'"
                :action="route('admin.racks.destroy', $rack)"
                confirm-label="Delete rack">
                @include('admin.partials._typed_confirm', ['name' => $rack->name, 'counts' => $impactCounts])
            </x-adminlte.partials.confirm-modal>
        @endforeach
    @endif
@stop
