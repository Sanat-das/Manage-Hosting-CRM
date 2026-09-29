@extends('adminlte::page')
@section('title', 'DNS Zones')
@section('content_header')
    <x-ui.page-header title="DNS Zones" subtitle="Manage DNS zones inventory" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'DNS Zones', 'active' => true],
    ]" />
@stop
@section('content')
    <x-adminlte.partials.flash-alert />

    <x-adminlte.partials.datatable
        icon="bi bi-globe"
        title="All DNS Zones"
        :search-value="$search"
        search-placeholder="Search domain..."
        :columns="[
            ['label' => 'Domain', 'sort' => 'domain'],
            ['label' => 'Type', 'sort' => 'type'],
            ['label' => 'Records', 'sort' => 'records_count'],
            ['label' => 'Status', 'sort' => 'status'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$zones"
    >
        <x-slot name="tools">
            <a href="{{ route('admin.dns-zones.create') }}" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Add Zone
            </a>
        </x-slot>

        @forelse ($zones as $zone)
            <tr>
                <td><a href="{{ route('admin.dns-zones.show', $zone) }}"><strong>{{ $zone->name }}</strong></a></td>
                <td><span class="badge text-bg-info">{{ ucfirst((string) $zone->zone_type) }}</span></td>
                <td>{{ $zone->records_count ?? $zone->records->count() }}</td>
                <td><x-adminlte.partials.status-badge :status="$zone->status" /></td>
                <td class="text-end">
                    <div class="table-actions">
                        <a href="{{ route('admin.dns-zones.records.index', $zone) }}" class="btn btn-sm btn-outline-info btn-icon" title="Records" aria-label="Records"><i class="bi bi-list-nested"></i></a>
                        <a href="{{ route('admin.dns-zones.edit', $zone) }}" class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit"><i class="bi bi-pencil"></i></a>
                    </div>
                </td>
            </tr>
        @empty
            <x-ui.empty-table-row colSpan="5" icon="bi bi-globe" title="No DNS zones found." />
        @endforelse
    </x-adminlte.partials.datatable>
@stop
