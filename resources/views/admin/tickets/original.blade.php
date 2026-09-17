@extends('adminlte::page')

@section('title', 'Original message — ' . $ticket->ticket_no)

@section('content_header')
    <x-ui.page-header title="Original message" subtitle="View original ticket message source" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Tickets', 'url' => route('admin.tickets.index')],
        ['label' => $ticket->ticket_no, 'url' => route('admin.tickets.show', $ticket)],
        ['label' => 'Original', 'active' => true],
    ]" />
@stop

@section('content')
    <x-adminlte-card icon="bi bi-file-earmark-text" title="{{ $ticket->ticket_no }}">
        @if (!$isRaw)
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-1"></i>
                This is reconstructed from stored ticket data, not the exact raw
                MIME message that was sent — staff replies are not persisted
                whole. Recipients, subject, and body match what went out; other
                transport-level headers (received chain, DKIM signature, etc.)
                are not available here.
            </div>
        @endif

        <pre class="p-3 bg-body-secondary rounded" style="white-space: pre-wrap; word-break: break-word;">{{ $source }}</pre>

        <a href="{{ route('admin.tickets.show', $ticket) }}" class="btn btn-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to ticket
        </a>
    </x-adminlte-card>
@stop
