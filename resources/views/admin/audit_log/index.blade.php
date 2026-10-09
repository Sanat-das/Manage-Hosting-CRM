@extends('adminlte::page')

@section('title', 'Audit Trail')

@section('content_header')
    <x-ui.page-header title="Audit Trail" subtitle="Privileged actions recorded against entities" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Audit Trail','active' => true]]" />
@stop

@section('content')
    <x-adminlte.partials.flash-alert />
    <x-adminlte.partials.datatable
        icon="bi bi-journal-text"
        title="Audit Trail"
        :search-value="$search"
        search-placeholder="Search action, entity type..."
        status-field="action"
        status-placeholder="All actions"
        :status-options="$actions"
        :status-value="$action"
        :columns="[
            ['label' => 'Date', 'sort' => 'created_at'],
            ['label' => 'User', 'sort' => 'user'],
            ['label' => 'Action', 'sort' => 'action'],
            ['label' => 'Entity', 'sort' => 'entity_type'],
            ['label' => 'IP', 'sort' => 'ip_address'],
        ]"
        :pagination="$logs"
    >
        <x-slot name="filters">
            <div class="d-flex align-items-center gap-1">
                <label for="filter-entity-type" class="form-label small fw-medium mb-0">Entity</label>
                <select id="filter-entity-type" name="entity_type" class="form-select form-select-sm w-auto" onchange="this.form.submit()" aria-label="Filter by entity type">
                    <option value="">All entities</option>
                    @foreach ($entityTypes as $value => $label)
                        <option value="{{ $value }}" @selected($entityType === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </x-slot>

        @forelse ($logs as $log)
            <tr>
                <td class="text-muted small text-nowrap">{{ $log->created_at?->format('M j, H:i') }}</td>
                <td>{{ $log->user?->full_name ?? '—' }}</td>
                <td><span class="badge text-bg-info">{{ $log->action }}</span></td>
                <td>{{ $log->entity_type }}@if ($log->entity_id) <span class="text-muted">#{{ $log->entity_id }}</span>@endif</td>
                <td class="text-muted small">{{ $log->ip_address ?? '—' }}</td>
            </tr>
        @empty
            <x-ui.empty-table-row colSpan="5" title="No audit entries." />
        @endforelse
    </x-adminlte.partials.datatable>
@stop
