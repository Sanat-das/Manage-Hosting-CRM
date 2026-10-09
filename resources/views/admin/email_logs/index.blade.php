@extends('adminlte::page')

@section('title', 'Email Logs')

@section('content_header')
    <x-ui.page-header title="Email Logs" subtitle="Overview and management of email logs" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Email Logs','active' => true]]" />
@stop

@section('content')
    <x-adminlte.partials.flash-alert />
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
                <td><a href="{{ route('admin.email-logs.show', $log) }}" class="text-decoration-none">{{ $log->to_email ?? '—' }}</a></td>
                <td>{{ Str::limit($log->subject, 60) }}</td>
                <td><x-adminlte.partials.status-badge :status="$log->status" /></td>
            </tr>
        @empty
            <x-ui.empty-table-row colSpan="4" title="No email logs." />
        @endforelse
    </x-adminlte.partials.datatable>
@stop

