@extends('adminlte::page')
@section('title', 'Datacenters')
@section('content_header')
    <x-ui.page-header title="Datacenters" subtitle="Manage datacenters inventory" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Datacenters', 'active' => true],
    ]" />
@stop
@section('content')
    @php($canManage = auth()->user()?->can('datacenters.manage') ?? false)

    <x-adminlte.partials.flash-alert />

    <x-adminlte.partials.datatable
        icon="bi bi-building"
        title="All Datacenters"
        :search-value="$search"
        search-placeholder="Search name, code, location..."
        :status-options="$statuses"
        :status-value="$status"
        :columns="[
            ['label' => 'Name', 'sort' => 'name'],
            ['label' => 'Code', 'sort' => 'code'],
            ['label' => 'Location', 'sort' => 'location'],
            ['label' => 'Racks', 'sort' => 'racks'],
            ['label' => 'Status', 'sort' => 'status'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$datacenters"
    >
        <x-slot name="tools">
            <a href="{{ route('admin.datacenters.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Datacenter</a>
        </x-slot>

        @forelse ($datacenters as $dc)
            <tr>
                <td><a href="{{ route('admin.datacenters.show', $dc) }}"><strong>{{ $dc->name }}</strong></a></td>
                <td>{{ $dc->code ?? '—' }}</td>
                <td class="text-muted">{{ collect([$dc->city, $dc->state, $dc->country])->filter()->implode(', ') ?: '—' }}</td>
                <td>{{ $dc->racks_count }}</td>
                <td><x-adminlte.partials.status-badge :status="$dc->status ?? 'active'" /></td>
                <td class="text-end">
                    <div class="table-actions">
                        <a href="{{ route('admin.datacenters.edit', $dc) }}" class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit"><i class="bi bi-pencil"></i></a>
                        @if ($canManage)
                            <button type="button" class="btn btn-sm btn-outline-danger btn-icon" title="Delete" aria-label="Delete"
                                    data-bs-toggle="modal" data-bs-target="#delete-datacenter-{{ $dc->id }}"><i class="bi bi-trash"></i></button>
                        @endif
                    </div>
                </td>
            </tr>
        @empty
            <x-ui.empty-table-row colSpan="6" title="No datacenters found." />
        @endforelse
    </x-adminlte.partials.datatable>

    @if ($canManage)
        @foreach ($datacenters as $dc)
            @php($impactCounts = [
                'Racks in this datacenter' => (int) ($dc->racks_count ?? 0),
                'Devices in this datacenter' => (int) ($dc->devices_count ?? 0),
                'IP subnets in this datacenter' => (int) ($dc->subnets_count ?? 0),
            ])
            <x-adminlte.partials.confirm-modal
                :id="'delete-datacenter-' . $dc->id"
                title="Delete datacenter"
                :message="'Delete ' . $dc->name . '? Racks, devices and IP subnets are NOT deleted — they keep a dangling datacenter_id (no FK is enforced).'"
                :action="route('admin.datacenters.destroy', $dc)"
                confirm-label="Delete datacenter">
                @include('admin.partials._typed_confirm', ['name' => $dc->name, 'counts' => $impactCounts])
            </x-adminlte.partials.confirm-modal>
        @endforeach
    @endif
@stop
