@extends('adminlte::page')

@section('title', 'Support Tickets')

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Support Tickets</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">Tickets</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif
    @if (session('error'))
        <x-adminlte-alert theme="danger" dismissible>{{ session('error') }}</x-adminlte-alert>
    @endif

    <x-adminlte.partials.metric-cards :items="[
        ['title' => $stats->get('open', 0), 'text' => 'Open', 'icon' => 'bi bi-envelope-open', 'theme' => 'primary'],
        ['title' => $stats->get('pending', 0), 'text' => 'Pending', 'icon' => 'bi bi-hourglass-split', 'theme' => 'warning'],
        ['title' => $stats->get('resolved', 0), 'text' => 'Resolved', 'icon' => 'bi bi-check-circle', 'theme' => 'success'],
        ['title' => $stats->get('closed', 0), 'text' => 'Closed', 'icon' => 'bi bi-x-circle', 'theme' => 'secondary'],
    ]" />

    <x-adminlte.partials.datatable
        icon="bi bi-ticket"
        title="All Tickets"
        :search-value="$search"
        search-placeholder="Search ticket #, subject, customer..."
        :status-options="$statuses"
        :status-value="$status"
        :columns="[
            ['label' => 'Ticket #'],
            ['label' => 'Subject'],
            ['label' => 'Customer'],
            ['label' => 'Department'],
            ['label' => 'Priority'],
            ['label' => 'Replies'],
            ['label' => 'Status'],
            ['label' => 'Last Reply'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$tickets"
    >
        <x-slot name="tools">
            <form method="GET" action="{{ url()->current() }}" class="d-inline-flex align-items-center gap-2 me-2">
                <input type="hidden" name="search" value="{{ $search }}">
                <input type="hidden" name="status" value="{{ $status }}">
                <select name="department" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Filter by department">
                    <option value="">All departments</option>
                    @foreach ($departments as $key => $label)
                        <option value="{{ $key }}" @selected($department === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </form>
            <form method="GET" action="{{ url()->current() }}" class="d-inline-flex align-items-center gap-2 me-2">
                <input type="hidden" name="search" value="{{ $search }}">
                <input type="hidden" name="department" value="{{ $department }}">
                <input type="hidden" name="status" value="{{ $status }}">
                <select name="priority" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Filter by priority">
                    <option value="">All priorities</option>
                    @foreach ($priorities as $key => $label)
                        <option value="{{ $key }}" @selected($priority === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </form>
            @can('tickets.create')
                <a href="{{ route('admin.tickets.create') }}" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> New Ticket
                </a>
            @endcan
        </x-slot>

        @forelse ($tickets as $ticket)
            <tr>
                <td><strong>{{ $ticket->ticket_no }}</strong></td>
                <td>{{ $ticket->subject }}</td>
                <td>{{ $ticket->customer->full_name ?? '—' }}</td>
                <td><span class="badge bg-info">{{ ucfirst($ticket->department) }}</span></td>
                <td>
                    @php
                        $priorityColors = ['low' => 'secondary', 'medium' => 'warning', 'high' => 'danger', 'urgent' => 'dark'];
                    @endphp
                    <span class="badge bg-{{ $priorityColors[$ticket->priority] ?? 'secondary' }}">{{ ucfirst($ticket->priority) }}</span>
                </td>
                <td class="text-center">{{ $ticket->replies_count }}</td>
                <td><x-adminlte.partials.status-badge :status="$ticket->status" /></td>
                <td class="text-muted">{{ $ticket->last_reply_at?->diffForHumans() ?? '—' }}</td>
                <td class="text-end">
                    <a href="{{ route('admin.tickets.show', $ticket) }}"
                       class="btn btn-sm btn-outline-secondary" title="View">
                        <i class="bi bi-eye"></i>
                    </a>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="9" class="text-center text-muted py-4">
                    No tickets found.
                </td>
            </tr>
        @endforelse
    </x-adminlte.partials.datatable>
@stop
