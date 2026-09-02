@extends('adminlte::page')

@section('title', 'VLANs')

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">VLANs</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">VLANs</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif

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
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="text-center text-muted py-4">No VLANs found.</td></tr>
        @endforelse
    </x-adminlte.partials.datatable>
@stop
