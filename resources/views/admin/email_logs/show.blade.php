@extends('adminlte::page')

@section('title', 'Email Log Detail')

@section('content_header')
    <x-ui.page-header title="Email Log" subtitle="View email log details" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Email Logs','url' => route('admin.email-logs.index')],['label' => 'Detail','active' => true]]" />
@stop

@section('content')
    <div class="row">
        <div class="col-lg-6">
            <x-adminlte-card icon="bi bi-info-circle" title="Email Info">
                <table class="table table-sm table-borderless">
                    <tr><th class="w-25 text-muted">To</th><td>{{ $log->to_email ?? 'â€”' }}</td></tr>
                    <tr><th class="text-muted">Subject</th><td><strong>{{ $log->subject ?? 'â€”' }}</strong></td></tr>
                    <tr><th class="text-muted">Status</th>
                        <td>
                            <x-adminlte.partials.status-badge :status="$log->status" />
                        </td>
                    </tr>
                    <tr><th class="text-muted">Sent at</th><td>{{ $log->created_at?->format('M j, Y H:i') ?? 'â€”' }}</td></tr>
                </table>
            </x-adminlte-card>
        </div>
        <div class="col-lg-6">
            <x-adminlte-card icon="bi bi-code-slash" title="Body">
                <div style="white-space: pre-wrap; background: var(--color-bg-subtle, var(--bs-tertiary-bg, #f8fafc)); padding: 1rem; border-radius: var(--radius-md); max-height: 400px; overflow-y: auto; border: 1px solid var(--color-border);">{{ $log->body ?? 'â€”' }}</div>
            </x-adminlte-card>
        </div>
    </div>
@stop

