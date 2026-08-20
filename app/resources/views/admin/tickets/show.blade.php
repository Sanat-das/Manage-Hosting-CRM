@extends('adminlte::page')

@section('title', $ticket->ticket_no . ' — ' . $ticket->subject)

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">{{ $ticket->ticket_no }}</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.tickets.index') }}">Tickets</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $ticket->ticket_no }}</li>
            </ol>
        </div>
    </div>
@stop

@php
    $priorityColors = ['low' => 'secondary', 'medium' => 'warning', 'high' => 'danger', 'urgent' => 'dark'];
@endphp

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif
    @if (session('error'))
        <x-adminlte-alert theme="danger" dismissible>{{ session('error') }}</x-adminlte-alert>
    @endif

    {{-- Ticket header --}}
    <x-adminlte-card>
        <div class="d-flex flex-wrap align-items-center gap-3">
            <div class="flex-grow-1">
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <h4 class="mb-0">{{ $ticket->subject }}</h4>
                    <x-adminlte.partials.status-badge :status="$ticket->status" />
                    <span class="badge bg-{{ $priorityColors[$ticket->priority] ?? 'secondary' }}">{{ ucfirst($ticket->priority) }}</span>
                    <span class="badge bg-info">{{ ucfirst($ticket->department) }}</span>
                </div>
                <div class="text-muted mt-1">
                    Customer: <strong>{{ $ticket->customer->full_name ?? '—' }}</strong>
                    @if ($ticket->customer->user)
                        <span class="text-muted">({{ $ticket->customer->user->email }})</span>
                    @endif
                    @if ($ticket->assignedTo)
                        <span class="mx-2">|</span> Assigned to: <strong>{{ $ticket->assignedTo->full_name }}</strong>
                    @endif
                    <span class="mx-2">|</span> Created: {{ $ticket->created_at?->format('M j, Y H:i') }}
                </div>
            </div>
            <div class="d-flex gap-2">
                @if ($ticket->status !== 'closed')
                    @can('tickets.edit')
                        <form method="POST" action="{{ route('admin.tickets.close', $ticket) }}" class="d-inline">
                            @csrf
                            <button class="btn btn-sm btn-outline-warning" title="Close ticket">
                                <i class="bi bi-x-circle me-1"></i> Close
                            </button>
                        </form>
                    @endcan
                @else
                    @can('tickets.edit')
                        <form method="POST" action="{{ route('admin.tickets.reopen', $ticket) }}" class="d-inline">
                            @csrf
                            <button class="btn btn-sm btn-outline-success" title="Reopen ticket">
                                <i class="bi bi-arrow-counterclockwise me-1"></i> Reopen
                            </button>
                        </form>
                    @endcan
                @endif
                @can('tickets.assign')
                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal"
                            data-bs-target="#reassign-modal">
                        <i class="bi bi-person-gear me-1"></i> Reassign
                    </button>
                @endcan
            </div>
        </div>
    </x-adminlte-card>

    <div class="row">
        {{-- Conversation timeline --}}
        <div class="col-lg-8">
            <x-adminlte-card icon="bi bi-chat-left-text" title="Conversation">
                @forelse ($replies as $reply)
                    @php
                        $isInternal = str_starts_with($reply->message, $internalPrefix);
                        $isStaff = $reply->is_staff;
                    @endphp
                    <div class="border-bottom pb-3 mb-3 {{ $isInternal ? 'bg-warning bg-opacity-10 p-2 rounded' : '' }}">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <div>
                                <strong>{{ $reply->user?->full_name ?? 'Unknown' }}</strong>
                                @if ($isStaff)
                                    <span class="badge bg-primary ms-1">Staff</span>
                                @endif
                                @if ($isInternal)
                                    <span class="badge bg-warning text-dark ms-1">Internal Note</span>
                                @endif
                            </div>
                            <small class="text-muted">{{ $reply->created_at?->diffForHumans() }}</small>
                        </div>
                        <div class="{{ $isInternal ? 'text-muted fst-italic' : '' }}">
                            {!! nl2br(e($isInternal ? Str::after($reply->message, $internalPrefix . ' ') : $reply->message)) !!}
                        </div>
                    </div>
                @empty
                    <p class="text-muted mb-0">No replies yet.</p>
                @endforelse
            </x-adminlte-card>

            {{-- Reply form --}}
            @if ($ticket->status !== 'closed')
                @can('tickets.edit')
                    <x-adminlte-card icon="bi bi-reply" title="Reply">
                        <form method="POST" action="{{ route('admin.tickets.reply', $ticket) }}">
                            @csrf
                            <x-adminlte-textarea name="message" label="Reply message" rows="4"
                                                 placeholder="Type your reply..." required>{{ old('message') }}</x-adminlte-textarea>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-send me-1"></i> Send Reply
                            </button>
                        </form>
                    </x-adminlte-card>
                @endcan
            @endif
        </div>

        {{-- Sidebar --}}
        <div class="col-lg-4">
            {{-- Internal note --}}
            @can('tickets.edit')
                <x-adminlte-card icon="bi bi-sticky" title="Internal Note">
                    <form method="POST" action="{{ route('admin.tickets.note', $ticket) }}">
                        @csrf
                        <x-adminlte-textarea name="note" label="" rows="3"
                                             placeholder="Add a staff-only note..." required>{{ old('note') }}</x-adminlte-textarea>
                        <button type="submit" class="btn btn-sm btn-warning">
                            <i class="bi bi-sticky me-1"></i> Add Note
                        </button>
                    </form>
                </x-adminlte-card>
            @endcan

            {{-- Ticket info --}}
            <x-adminlte-card title="Details">
                <table class="table table-sm table-borderless mb-0">
                    <tbody>
                        <tr><th class="text-muted w-25">Status</th><td><x-adminlte.partials.status-badge :status="$ticket->status" /></td></tr>
                        <tr><th class="text-muted">Priority</th><td><span class="badge bg-{{ $priorityColors[$ticket->priority] ?? 'secondary' }}">{{ ucfirst($ticket->priority) }}</span></td></tr>
                        <tr><th class="text-muted">Department</th><td>{{ $departments[$ticket->department] ?? ucfirst($ticket->department) }}</td></tr>
                        <tr><th class="text-muted">Assigned</th><td>{{ $ticket->assignedTo?->full_name ?? 'Unassigned' }}</td></tr>
                        <tr><th class="text-muted">Created</th><td>{{ $ticket->created_at?->format('M j, Y H:i') }}</td></tr>
                        <tr><th class="text-muted">Last reply</th><td>{{ $ticket->last_reply_at?->diffForHumans() ?? '—' }}</td></tr>
                    </tbody>
                </table>
            </x-adminlte-card>
        </div>
    </div>

    {{-- Reassign modal --}}
    @can('tickets.assign')
        <div class="modal fade" id="reassign-modal" tabindex="-1" aria-labelledby="reassign-modal-label" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="{{ route('admin.tickets.assign', $ticket) }}">
                        @csrf
                        <div class="modal-header">
                            <h5 class="modal-title" id="reassign-modal-label">Reassign Ticket</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <x-adminlte-select name="assigned_to" label="Assign to">
                                <option value="">Unassigned</option>
                                @foreach ($staff as $member)
                                    <option value="{{ $member->id }}" @selected($ticket->assigned_to === $member->id)>
                                        {{ $member->full_name }}
                                    </option>
                                @endforeach
                            </x-adminlte-select>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Save</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endcan
@stop
