@extends('adminlte::page')

@section('title', 'Module Logs')

@section('content_header')
    <x-ui.page-header title="Module Logs" subtitle="Module events and failures" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Module Logs','active' => true]]" />
@stop

@section('content')
    <x-adminlte.partials.flash-alert />
    <x-adminlte.partials.datatable
        icon="bi bi-puzzle"
        title="Module Logs"
        :search-value="$search"
        search-placeholder="Search event..."
        status-field="status"
        status-placeholder="All statuses"
        :status-options="$statuses"
        :status-value="$status"
        :columns="[
            ['label' => 'Date', 'sort' => 'created_at'],
            ['label' => 'Module'],
            ['label' => 'Event', 'sort' => 'event'],
            ['label' => 'Status', 'sort' => 'status'],
            ['label' => 'Service Instance'],
            ['label' => 'Error'],
        ]"
        :pagination="$logs"
    >
        @forelse ($logs as $log)
            <tr>
                <td class="text-muted small text-nowrap">{{ $log->created_at?->format('M j, H:i') }}</td>
                <td>{{ $log->module?->name ?? '—' }}</td>
                <td>{{ $log->event }}</td>
                <td><x-adminlte.partials.status-badge :status="$log->status" /></td>
                <td class="text-muted small">{{ $log->service_instance_id ?? '—' }}</td>
                <td class="text-muted small" @if ($log->error) title="{{ $log->error }}" @endif>{{ $log->error ? Str::limit($log->error, 60) : '—' }}</td>
            </tr>
        @empty
            <x-ui.empty-table-row colSpan="6" title="No module log entries." />
        @endforelse
    </x-adminlte.partials.datatable>
@stop
