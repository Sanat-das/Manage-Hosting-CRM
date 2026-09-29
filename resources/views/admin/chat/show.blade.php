@extends('adminlte::page')

@section('title', 'Chat #' . $chat->id)

@section('content_header')
    <x-ui.page-header title="Chat #{{ $chat->id }}" subtitle="View chat details and history" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Live Chat', 'url' => route('admin.chat.index')],
        ['label' => '#' . $chat->id, 'active' => true],
    ]" />
@stop

@section('content')
    <div class="row">
        <div class="col-lg-8">
            <x-adminlte-card icon="bi bi-chat-dots" title="Conversation">
                @forelse ($chat->messages as $msg)
                    <div class="border rounded p-3 mb-3 {{ $msg->sender_type === 'staff' ? 'border-primary bg-body-tertiary' : '' }}">
                        <div class="d-flex justify-content-between mb-1">
                            <strong>{{ $msg->sender_type === 'staff' ? 'Staff' : ($chat->name ?? 'Customer') }}</strong>
                            <small class="text-muted">{{ $msg->created_at?->format('M j, H:i') }}</small>
                        </div>
                        <div class="mb-0" style="white-space: pre-wrap;">{{ $msg->message }}</div>
                    </div>
                @empty
                    <x-adminlte.partials.empty-state icon="bi bi-chat-dots" title="No messages yet." size="sm" />
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
                            <x-adminlte.partials.status-badge :status="$chat->status" />
                        </td>
                    </tr>
                    <tr>
                        <th class="text-muted">Rating</th>
                        <td>
                            {{-- Directives stay on their own lines: a directive
                                 glued to a preceding word character is not
                                 compiled and would render as literal text. --}}
                            @if ($chat->rating)
                                <span class="visually-hidden">{{ $chat->rating }} out of 5</span>
                                @for ($star = 1; $star <= 5; $star++)
                                    <i class="bi bi-star{{ $star <= (int) $chat->rating ? '-fill text-warning' : ' text-muted' }}" aria-hidden="true"></i>
                                @endfor
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                    <tr><th class="text-muted">Started</th><td>{{ $chat->started_at?->format('M j, Y H:i') ?? '—' }}</td></tr>
                    <tr><th class="text-muted">Ended</th><td>{{ $chat->ended_at?->format('M j, Y H:i') ?? '—' }}</td></tr>
                </table>
            </x-adminlte-card>
        </div>
    </div>
@stop
