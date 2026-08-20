@extends('adminlte::page')

@section('title', $ticket->ticket_no)

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Ticket {{ $ticket->ticket_no }}</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('client.tickets.index') }}">Tickets</a></li>
                <li class="breadcrumb-item active">{{ $ticket->ticket_no }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    <div class="row">
        <div class="col-lg-8">
            {{-- Ticket header --}}
            <x-adminlte-card icon="bi bi-life-preserver" title="{{ $ticket->subject }}">
                <div class="d-flex justify-content-between mb-2">
                    <div>
                        @php $pbadge = ['low'=>'info','medium'=>'warning','high'=>'danger','urgent'=>'dark'][$ticket->priority] ?? 'secondary'; @endphp
                        <span class="badge bg-{{ $pbadge }}">{{ ucfirst($ticket->priority) }}</span>
                        <span class="text-muted ms-2">{{ $ticket->department ?? 'General' }}</span>
                    </div>
                    <div class="text-muted small">Created {{ $ticket->created_at?->format('M j, Y H:i') }}</div>
                </div>

                {{-- Thread --}}
                @forelse ($ticket->replies->sortByDesc('created_at') as $reply)
                    <div class="border rounded p-3 mb-3 {{ $reply->is_staff ? 'border-primary bg-light' : '' }}">
                        <div class="d-flex justify-content-between mb-1">
                            <strong>{{ $reply->is_staff ? 'Staff' : 'You' }}</strong>
                            <small class="text-muted">{{ $reply->created_at?->format('M j, H:i') }}</small>
                        </div>
                        <div class="mb-0" style="white-space: pre-wrap;">{{ $reply->message }}</div>
                    </div>
                @empty
                    <p class="text-muted">No replies yet.</p>
                @endforelse
            </x-adminlte-card>

            {{-- Reply form --}}
            @if (!in_array($ticket->status, ['closed']))
                <x-adminlte-card icon="bi bi-reply" title="Reply">
                    <form method="POST" action="{{ route('client.tickets.reply', $ticket) }}">
                        @csrf
                        <x-adminlte-textarea name="message" rows="4" placeholder="Type your reply..." required>{{ old('message') }}</x-adminlte-textarea>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i> Send Reply</button>
                    </form>
                </x-adminlte-card>
            @endif
        </div>

        <div class="col-lg-4">
            <x-adminlte-card icon="bi bi-info-circle" title="Ticket Info">
                <table class="table table-sm table-borderless mb-0">
                    <tr><th class="text-muted">Status</th>
                        <td>
                            @php $sbadge = ['open'=>'success','answered'=>'info','closed'=>'secondary'][$ticket->status] ?? 'secondary'; @endphp
                            <span class="badge bg-{{ $sbadge }}">{{ ucfirst($ticket->status) }}</span>
                        </td>
                    </tr>
                    <tr><th class="text-muted">Priority</th><td>{{ ucfirst($ticket->priority) }}</td></tr>
                    <tr><th class="text-muted">Department</th><td>{{ $ticket->department ?? 'General' }}</td></tr>
                    <tr><th class="text-muted">Assigned to</th><td>{{ $ticket->assignedTo?->full_name ?? 'Unassigned' }}</td></tr>
                    <tr><th class="text-muted">Last reply</th><td>{{ $ticket->last_reply_at?->format('M j, H:i') ?? '—' }}</td></tr>
                </table>
            </x-adminlte-card>
        </div>
    </div>
@stop
