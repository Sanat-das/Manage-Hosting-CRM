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
    {{-- Filters --}}
    <x-adminlte-card icon="bi bi-funnel" title="Filters">
        <form method="GET" class="d-flex gap-2 align-items-end">
            <x-adminlte-input name="search" label="Search" placeholder="Search actions..."
                value="{{ request('search') }}" />
            <x-adminlte-select name="action" label="Action Type">
                <option value="">All Actions</option>
                @foreach ($actions as $action)
                    <option value="{{ $action }}" @selected(request('action') === $action)>{{ $action }}</option>
                @endforeach
            </x-adminlte-select>
            <button class="btn btn-primary"><i class="bi bi-search me-1"></i> Filter</button>
        </form>
    </x-adminlte-card>

    <x-adminlte-card icon="bi bi-clock-history" title="Activity Log">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr><th>Date</th><th>Action</th><th>User</th><th>Customer</th><th>Description</th><th>IP</th></tr>
            </thead>
            <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td class="text-muted small" style="white-space: nowrap;">{{ $log->created_at?->format('M j, H:i') }}</td>
                        <td><span class="badge bg-info">{{ $log->action }}</span></td>
                        <td>{{ $log->user?->full_name ?? '—' }}</td>
                        <td>{{ $log->customer?->full_name ?? '—' }}</td>
                        <td>{{ Str::limit($log->description, 80) }}</td>
                        <td class="text-muted small">{{ $log->ip_address ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No activity logged.</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $logs->links() }}
    </x-adminlte-card>
@stop
