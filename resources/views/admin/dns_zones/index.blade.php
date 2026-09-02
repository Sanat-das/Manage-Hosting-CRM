@extends('adminlte::page')
@section('title', 'DNS Zones')
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">DNS Zones</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item active">DNS Zones</li></ol></div></div>
@stop
@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif

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
            <tr><td colspan="5" class="text-center text-muted py-4">No DNS zones found.</td></tr>
        @endforelse
    </x-adminlte.partials.datatable>
@stop
