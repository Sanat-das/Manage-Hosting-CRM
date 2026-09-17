@extends('adminlte::page')
@section('title', 'Datacenters')
@section('content_header')
    <x-ui.page-header title="Datacenters" subtitle="Manage datacenters inventory" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Datacenters', 'active' => true],
    ]" />
@stop
@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif

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
                <td>{{ $dc->racks_count ?? $dc->racks->count() }}</td>
                <td><x-adminlte.partials.status-badge :status="$dc->status ?? 'active'" /></td>
                <td class="text-end">
                    <div class="table-actions">
                        <a href="{{ route('admin.datacenters.edit', $dc) }}" class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit"><i class="bi bi-pencil"></i></a>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-center text-muted py-4">No datacenters found.</td></tr>
        @endforelse
    </x-adminlte.partials.datatable>
@stop
