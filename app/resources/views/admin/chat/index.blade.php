@extends('adminlte::page')

@section('title', 'Live Chat')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Live Chat</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active">Live Chat</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    <div class="row mb-3">
        <div class="col-lg-3 col-6">
            <x-adminlte-small-box :title="$stats->get('waiting', 0)" text="Waiting" icon="bi bi-hourglass-split" theme="warning" />
        </div>
        <div class="col-lg-3 col-6">
            <x-adminlte-small-box :title="$stats->get('active', 0)" text="Active" icon="bi bi-chat-dots" theme="success" />
        </div>
        <div class="col-lg-3 col-6">
            <x-adminlte-small-box :title="$stats->get('closed', 0)" text="Closed" icon="bi bi-check-circle" theme="secondary" />
        </div>
    </div>

    <div class="mb-3">
        <a href="{{ route('admin.chat.index') }}" class="btn btn-sm {{ !$status ? 'btn-primary' : 'btn-outline-primary' }}">All</a>
        @foreach ($statuses as $s)
            <a href="{{ route('admin.chat.index', ['status' => $s]) }}" class="btn btn-sm {{ $status === $s ? 'btn-primary' : 'btn-outline-primary' }}">{{ ucfirst($s) }}</a>
        @endforeach
    </div>

    <x-adminlte-card icon="bi bi-chat-dots" title="Chat Sessions">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr><th>Session</th><th>Customer</th><th>Department</th><th>Status</th><th>Started</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($sessions as $session)
                    <tr>
                        <td><strong>#{{ $session->id }}</strong></td>
                        <td>{{ $session->name ?? $session->customer_id ?? '—' }}</td>
                        <td class="text-muted">{{ $session->department ?? '—' }}</td>
                        <td>
                            @php $badge = ['waiting'=>'warning','active'=>'success','closed'=>'secondary'][$session->status] ?? 'secondary'; @endphp
                            <span class="badge bg-{{ $badge }}">{{ ucfirst($session->status) }}</span>
                        </td>
                        <td class="text-muted small">{{ $session->started_at?->format('M j, H:i') ?? '—' }}</td>
                        <td class="text-end">
                            <a href="{{ route('admin.chat.show', $session) }}" class="btn btn-sm btn-outline-primary">View</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No chat sessions.</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $sessions->links() }}
    </x-adminlte-card>
@stop
