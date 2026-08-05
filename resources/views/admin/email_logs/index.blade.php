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
    <x-adminlte-card icon="bi bi-funnel" title="Filters">
        <form method="GET" class="d-flex gap-2 align-items-end">
            <x-adminlte-input name="search" label="Search" placeholder="To / subject..." value="{{ request('search') }}" />
            <x-adminlte-select name="status" label="Status">
                <option value="">All</option>
                @foreach ($statuses as $s)
                    <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>
                @endforeach
            </x-adminlte-select>
            <button class="btn btn-primary"><i class="bi bi-search me-1"></i> Filter</button>
        </form>
    </x-adminlte-card>

    <x-adminlte-card icon="bi bi-envelope-check" title="Sent Emails">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr><th>Date</th><th>To</th><th>Subject</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td class="text-muted small" style="white-space:nowrap;">{{ $log->created_at?->format('M j, H:i') }}</td>
                        <td>{{ $log->to_email ?? '—' }}</td>
                        <td>{{ Str::limit($log->subject, 60) }}</td>
                        <td>
                            @php $badge = ['sent'=>'success','queued'=>'info','failed'=>'danger','pending'=>'warning'][$log->status] ?? 'secondary'; @endphp
                            <span class="badge bg-{{ $badge }}">{{ ucfirst($log->status) }}</span>
                        </td>
                        <td class="text-end">
                            <a href="{{ route('admin.email-logs.show', $log) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">No email logs.</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $logs->links() }}
    </x-adminlte-card>
@stop
