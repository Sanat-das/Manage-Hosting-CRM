@extends('adminlte::page')

@section('title', 'Chat #' . $chat->id)

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Chat #{{ $chat->id }}</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.chat.index') }}">Live Chat</a></li>
                <li class="breadcrumb-item active">#{{ $chat->id }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    <div class="row">
        <div class="col-lg-8">
            <x-adminlte-card icon="bi bi-chat-dots" title="Conversation">
                @forelse ($chat->messages as $msg)
                    <div class="border rounded p-3 mb-3 {{ $msg->sender_type === 'staff' ? 'border-primary bg-light' : '' }}">
                        <div class="d-flex justify-content-between mb-1">
                            <strong>{{ $msg->sender_type === 'staff' ? 'Staff' : ($chat->name ?? 'Customer') }}</strong>
                            <small class="text-muted">{{ $msg->created_at?->format('M j, H:i') }}</small>
                        </div>
                        <div class="mb-0" style="white-space: pre-wrap;">{{ $msg->message }}</div>
                    </div>
                @empty
                    <p class="text-muted">No messages yet.</p>
                @endforelse
            </x-adminlte-card>
        </div>

        <div class="col-lg-4">
            <x-adminlte-card icon="bi bi-info-circle" title="Session Info">
                <table class="table table-sm table-borderless mb-0">
                    <tr><th class="text-muted">Name</th><td>{{ $chat->name ?? '—' }}</td></tr>
                    <tr><th class="text-muted">Email</th><td>{{ $chat->email ?? '—' }}</td></tr>
                    <tr><th class="text-muted">Department</th><td>{{ $chat->department ?? '—' }}</td></tr>
                    <tr><th class="text-muted">Status</th>
                        <td>
                            @php $badge = ['waiting'=>'warning','active'=>'success','closed'=>'secondary'][$chat->status] ?? 'secondary'; @endphp
                            <span class="badge bg-{{ $badge }}">{{ ucfirst($chat->status) }}</span>
                        </td>
                    </tr>
                    <tr><th class="text-muted">Rating</th><td>{{ $chat->rating ? str_repeat('⭐', $chat->rating) : '—' }}</td></tr>
                    <tr><th class="text-muted">Started</th><td>{{ $chat->started_at?->format('M j, Y H:i') ?? '—' }}</td></tr>
                    <tr><th class="text-muted">Ended</th><td>{{ $chat->ended_at?->format('M j, Y H:i') ?? '—' }}</td></tr>
                </table>
            </x-adminlte-card>
        </div>
    </div>
@stop
