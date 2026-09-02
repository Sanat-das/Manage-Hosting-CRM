@extends('adminlte::page')

@section('title', 'Email Logs')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Email Logs</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active">Email Logs</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    <x-adminlte.partials.datatable
        icon="bi bi-envelope-check"
        title="Sent Emails"
        :search-value="$search"
        search-placeholder="To / subject..."
        :status-options="$statuses"
        :status-value="$status"
        :columns="[
            ['label' => 'Date', 'sort' => 'created_at'],
            ['label' => 'To', 'sort' => 'to_email'],
            ['label' => 'Subject', 'sort' => 'subject'],
            ['label' => 'Status', 'sort' => 'status'],
        ]"
        :pagination="$logs"
    >
        @forelse ($logs as $log)
            <tr>
                <td class="text-muted small text-nowrap">{{ $log->created_at?->format('M j, H:i') }}</td>
                <td>{{ $log->to_email ?? '—' }}</td>
                <td>{{ Str::limit($log->subject, 60) }}</td>
                <td><x-adminlte.partials.status-badge :status="$log->status" /></td>
            </tr>
        @empty
            <tr><td colspan="4" class="text-center text-muted py-4">No email logs.</td></tr>
        @endforelse
    </x-adminlte.partials.datatable>
@stop
