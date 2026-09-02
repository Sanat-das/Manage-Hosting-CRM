@extends('adminlte::page')

@section('title', 'Activity Log')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Activity Log</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active">Activity Log</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    <x-adminlte.partials.datatable
        icon="bi bi-clock-history"
        title="Activity Log"
        :search-value="$search"
        search-placeholder="Search actions, description..."
        status-field="action"
        status-placeholder="All actions"
        :status-options="$actions"
        :status-value="$action"
        :columns="[
            ['label' => 'Date', 'sort' => 'created_at'],
            ['label' => 'Action', 'sort' => 'action'],
            ['label' => 'User', 'sort' => 'user'],
            ['label' => 'Customer'],
            ['label' => 'Description', 'sort' => 'description'],
            ['label' => 'IP', 'sort' => 'ip_address'],
        ]"
        :pagination="$logs"
    >
        @forelse ($logs as $log)
            <tr>
                <td class="text-muted small text-nowrap">{{ $log->created_at?->format('M j, H:i') }}</td>
                <td><span class="badge text-bg-info">{{ $log->action }}</span></td>
                <td>{{ $log->user?->full_name ?? '—' }}</td>
                <td>{{ $log->customer?->full_name ?? '—' }}</td>
                <td>{{ Str::limit($log->description, 80) }}</td>
                <td class="text-muted small">{{ $log->ip_address ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-center text-muted py-4">No activity logged.</td></tr>
        @endforelse
    </x-adminlte.partials.datatable>
@stop
