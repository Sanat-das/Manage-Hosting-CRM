@extends('adminlte::page')

@section('title', 'My Tickets')

@section('content_header')
    <x-ui.page-header title="My Tickets" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Dashboard', 'url' => route('client.dashboard')],
        ['label' => 'Tickets', 'active' => true],
    ]" />
@stop

@section('content')
    <div class="mb-3">
        <a href="{{ route('client.tickets.index') }}" class="btn btn-sm {{ !$status ? 'btn-primary' : 'btn-outline-primary' }}">All</a>
        <a href="{{ route('client.tickets.index', ['status' => 'open']) }}" class="btn btn-sm {{ $status === 'open' ? 'btn-warning' : 'btn-outline-warning' }}">Open</a>
        <a href="{{ route('client.tickets.index', ['status' => 'closed']) }}" class="btn btn-sm {{ $status === 'closed' ? 'btn-secondary' : 'btn-outline-secondary' }}">Closed</a>
    </div>

    <x-adminlte.partials.datatable
        icon="bi bi-life-preserver"
        title="Support Tickets"
        :search-value="$search"
        search-placeholder="Search ticket number or subject..."
        :columns="[
            ['label' => 'Ticket #', 'sort' => 'ticket_no'],
            ['label' => 'Subject', 'sort' => 'subject'],
            ['label' => 'Department', 'sort' => 'department'],
            ['label' => 'Priority', 'sort' => 'priority'],
            ['label' => 'Status', 'sort' => 'status'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$tickets"
    >
        <x-slot name="tools">
            <a href="{{ route('client.tickets.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> New Ticket</a>
        </x-slot>

                @forelse ($tickets as $ticket)
                    <tr>
                        <td><strong>{{ $ticket->ticket_no }}</strong></td>
                        <td>{{ $ticket->subject }}</td>
                        <td class="text-muted">{{ \App\Services\TicketService::departmentLabel($ticket->department) ?: 'General' }}</td>
                        <td>
                            <x-adminlte.partials.status-badge :status="$ticket->priority" />
                        </td>
                        <td>
                            <x-adminlte.partials.status-badge :status="$ticket->status" />
                        </td>
                        <td class="text-end">
                            <div class="table-actions">
                                <a href="{{ route('client.tickets.show', $ticket) }}" class="btn btn-sm btn-outline-primary btn-icon" title="View" aria-label="View"><i class="bi bi-eye"></i></a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-table-row colSpan="6" icon="bi bi-life-preserver" title="No support tickets." actionLabel="New Ticket" :actionUrl="route('client.tickets.create')" />
                @endforelse
    </x-adminlte.partials.datatable>
@stop
