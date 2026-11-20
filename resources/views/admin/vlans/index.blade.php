@extends('adminlte::page')

@section('title', 'VLANs')

@section('content_header')
    <x-ui.page-header title="VLANs" subtitle="Manage VLANs inventory" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'VLANs', 'active' => true],
    ]" />
@stop

@section('content')
    @php($canManage = auth()->user()?->can('vlans.manage') ?? false)

    <x-adminlte.partials.flash-alert />

    <x-adminlte.partials.datatable
        icon="bi bi-diagram-3"
        title="All VLANs"
        :search-value="$search"
        search-placeholder="Search VLAN, ID, datacenter..."
        :columns="[
            ['label' => 'Name', 'sort' => 'name'],
            ['label' => 'VLAN ID', 'sort' => 'vlan_id'],
            ['label' => 'Datacenter', 'sort' => 'datacenter'],
            ['label' => 'Description', 'sort' => 'description'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$vlans"
    >
        <x-slot name="tools">
            <a href="{{ route('admin.vlans.create') }}" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Add VLAN
            </a>
        </x-slot>

        @forelse ($vlans as $vlan)
            <tr>
                <td><a href="{{ route('admin.vlans.show', $vlan) }}"><strong>{{ $vlan->name }}</strong></a></td>
                <td><span class="badge text-bg-info">{{ $vlan->vlan_id }}</span></td>
                <td>{{ $vlan->datacenter?->name ?? '—' }}</td>
                <td class="text-muted">{{ $vlan->description ?? '—' }}</td>
                <td class="text-end">
                    <div class="table-actions">
                        <a href="{{ route('admin.vlans.edit', $vlan) }}" class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit"><i class="bi bi-pencil"></i></a>
                        @if ($canManage)
                            <button type="button" class="btn btn-sm btn-outline-danger btn-icon" title="Delete" aria-label="Delete"
                                    data-bs-toggle="modal" data-bs-target="#delete-vlan-{{ $vlan->id }}"><i class="bi bi-trash"></i></button>
                        @endif
                    </div>
                </td>
            </tr>
        @empty
            <x-ui.empty-table-row colSpan="5" title="No VLANs found." />
        @endforelse
    </x-adminlte.partials.datatable>

    @if ($canManage)
        @foreach ($vlans as $vlan)
            @php($impactCounts = [
                'IP subnets in this VLAN' => (int) ($vlan->subnets_count ?? 0),
            ])
            <x-adminlte.partials.confirm-modal
                :id="'delete-vlan-' . $vlan->id"
                title="Delete VLAN"
                :message="'Delete ' . $vlan->name . '? IP subnets in it are NOT deleted — they keep a dangling vlan_id (no FK is enforced).'"
                :action="route('admin.vlans.destroy', $vlan)"
                confirm-label="Delete VLAN">
                @include('admin.partials._typed_confirm', ['name' => $vlan->name, 'counts' => $impactCounts])
            </x-adminlte.partials.confirm-modal>
        @endforeach
    @endif
@stop
