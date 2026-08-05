@extends('adminlte::page')

@section('title', 'My Tickets')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">My Tickets</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('client.dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item active">Tickets</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    <div class="d-flex justify-content-between mb-3">
        <div>
            <a href="{{ route('client.tickets.index') }}" class="btn btn-sm {{ !$status ? 'btn-primary' : 'btn-outline-primary' }}">All</a>
            <a href="{{ route('client.tickets.index', ['status' => 'open']) }}" class="btn btn-sm {{ $status === 'open' ? 'btn-warning' : 'btn-outline-warning' }}">Open</a>
            <a href="{{ route('client.tickets.index', ['status' => 'closed']) }}" class="btn btn-sm {{ $status === 'closed' ? 'btn-secondary' : 'btn-outline-secondary' }}">Closed</a>
        </div>
        <a href="{{ route('client.tickets.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> New Ticket</a>
    </div>

    <x-adminlte-card icon="bi bi-life-preserver" title="Support Tickets">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr><th>Ticket #</th><th>Subject</th><th>Department</th><th>Priority</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($tickets as $ticket)
                    <tr>
                        <td><strong>{{ $ticket->ticket_no }}</strong></td>
                        <td>{{ $ticket->subject }}</td>
                        <td class="text-muted">{{ $ticket->department ?? 'General' }}</td>
                        <td>
                            @php $pbadge = ['low'=>'info','medium'=>'warning','high'=>'danger','urgent'=>'dark'][$ticket->priority] ?? 'secondary'; @endphp
                            <span class="badge bg-{{ $pbadge }}">{{ ucfirst($ticket->priority) }}</span>
                        </td>
                        <td>
                            @php $sbadge = ['open'=>'success','answered'=>'info','closed'=>'secondary'][$ticket->status] ?? 'secondary'; @endphp
                            <span class="badge bg-{{ $sbadge }}">{{ ucfirst($ticket->status) }}</span>
                        </td>
                        <td class="text-end">
                            <a href="{{ route('client.tickets.show', $ticket) }}" class="btn btn-sm btn-outline-primary">View</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No support tickets.</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $tickets->links() }}
    </x-adminlte-card>
@stop
