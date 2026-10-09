@extends('adminlte::page')

@section('title', 'Email Log Detail')

@section('content_header')
    <x-ui.page-header title="Email Log" subtitle="View email log details" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Email Logs','url' => route('admin.email-logs.index')],['label' => 'Detail','active' => true]]" />
@stop

@section('content')
    <x-adminlte.partials.flash-alert />
    <div class="row">
        <div class="col-lg-6">
            <x-adminlte-card icon="bi bi-info-circle" title="Email Info">
                <table class="table table-sm table-borderless">
                    <tr><th class="w-25 text-muted">To</th><td>{{ $log->to_email ?? '—' }}</td></tr>
                    <tr><th class="text-muted">Subject</th><td><strong>{{ $log->subject ?? '—' }}</strong></td></tr>
                    <tr><th class="text-muted">Status</th>
                        <td>
                            <x-adminlte.partials.status-badge :status="$log->status" />
                        </td>
                    </tr>
                    <tr><th class="text-muted">Template</th><td>{{ $log->template_name ?? '—' }}</td></tr>
                    <tr><th class="text-muted">Customer</th>
                        <td>
                            @if ($log->customer)
                                <a href="{{ route('admin.customers.show', $log->customer) }}" class="text-decoration-none">{{ $log->customer->full_name }}</a>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                    <tr><th class="text-muted">From</th><td>{{ $log->from_email ?? '—' }}</td></tr>
                    <tr><th class="text-muted">Cc</th><td>{{ $log->cc_emails ?? '—' }}</td></tr>
                    <tr><th class="text-muted">Bcc</th><td>{{ $log->bcc_emails ?? '—' }}</td></tr>
                    <tr><th class="text-muted">Attempts</th><td>{{ $log->attempts ?? 0 }}</td></tr>
                    <tr><th class="text-muted">Log key</th><td><code>{{ $log->log_key ?? '—' }}</code></td></tr>
                    <tr><th class="text-muted">Sent at</th><td>{{ $log->created_at?->format('M j, Y H:i') ?? '—' }}</td></tr>
                </table>
                @if ($log->error)
                    <div class="mt-3">
                        <div class="text-muted small mb-1">Error</div>
                        <div class="alert alert-danger mb-0" style="white-space: pre-wrap;">{{ $log->error }}</div>
                    </div>
                @endif
                @can('email.manage')
                    <form method="POST" action="{{ route('admin.email-logs.resend', $log) }}" class="mt-3">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-arrow-repeat me-1"></i> Resend
                        </button>
                    </form>
                @endcan
            </x-adminlte-card>
        </div>
        <div class="col-lg-6">
            <x-adminlte-card icon="bi bi-code-slash" title="Body">
                <div style="white-space: pre-wrap; background: var(--color-bg-subtle, var(--bs-tertiary-bg)); padding: 1rem; border-radius: var(--radius-md); max-height: 400px; overflow-y: auto; border: 1px solid var(--color-border);">{{ $log->body ?? '—' }}</div>
            </x-adminlte-card>
        </div>
    </div>
@stop

