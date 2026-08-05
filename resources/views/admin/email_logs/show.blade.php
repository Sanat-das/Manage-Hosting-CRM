@extends('adminlte::page')

@section('title', 'Email Log Detail')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Email Log</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.email-logs.index') }}">Email Logs</a></li>
                <li class="breadcrumb-item active">Detail</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    <div class="row">
        <div class="col-lg-6">
            <x-adminlte-card icon="bi bi-info-circle" title="Email Info">
                <table class="table table-sm table-borderless">
                    <tr><th class="w-25 text-muted">To</th><td>{{ $log->to_email ?? '—' }}</td></tr>
                    <tr><th class="text-muted">Subject</th><td><strong>{{ $log->subject ?? '—' }}</strong></td></tr>
                    <tr><th class="text-muted">Status</th>
                        <td>
                            @php $badge = ['sent'=>'success','queued'=>'info','failed'=>'danger','pending'=>'warning'][$log->status] ?? 'secondary'; @endphp
                            <span class="badge bg-{{ $badge }}">{{ ucfirst($log->status) }}</span>
                        </td>
                    </tr>
                    <tr><th class="text-muted">Sent at</th><td>{{ $log->created_at?->format('M j, Y H:i') ?? '—' }}</td></tr>
                </table>
            </x-adminlte-card>
        </div>
        <div class="col-lg-6">
            <x-adminlte-card icon="bi bi-code-slash" title="Body">
                <div style="white-space: pre-wrap; background: #f8f9fa; padding: 1rem; border-radius: 0.5rem; max-height: 400px; overflow-y: auto;">{{ $log->body ?? '—' }}</div>
            </x-adminlte-card>
        </div>
    </div>
@stop
