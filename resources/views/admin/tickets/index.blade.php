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

    <x-adminlte.partials.datatable
        icon="bi bi-ticket"
        title="All Tickets"
        :search-value="$search"
        search-placeholder="Search ticket #, subject, customer..."
        :status-options="$statuses"
        :status-value="$status"
        :status-multiple="true"
        :columns="[
            ['label' => 'Ticket #', 'sort' => 'ticket_no'],
            ['label' => 'Subject', 'sort' => 'subject'],
            ['label' => 'Customer', 'sort' => 'customer'],
            ['label' => 'Department', 'sort' => 'department'],
            ['label' => 'Priority', 'sort' => 'priority'],
            ['label' => 'Replies'],
            ['label' => 'Status', 'sort' => 'status'],
            ['label' => 'Last Reply', 'sort' => 'last_reply_at'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$tickets"
    >
        <x-slot name="tools">
            <form method="GET" action="{{ url()->current() }}" class="d-inline-flex align-items-center gap-2 me-2">
                <input type="hidden" name="search" value="{{ $search }}">
                @foreach ($status as $s)
                    <input type="hidden" name="status[]" value="{{ $s }}">
                @endforeach
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
                @foreach ($status as $s)
                    <input type="hidden" name="status[]" value="{{ $s }}">
                @endforeach
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
            <div class="form-check form-switch d-inline-flex align-items-center mb-0 ms-1" title="Reload this page every 30 seconds">
                <input class="form-check-input" type="checkbox" role="switch" id="tickets-auto-refresh">
                <label class="form-check-label small text-muted ms-1" for="tickets-auto-refresh">Auto-refresh</label>
            </div>
        </x-slot>

        @forelse ($tickets as $ticket)
            <tr>
                <td><a href="{{ route('admin.tickets.show', $ticket) }}"><strong>{{ $ticket->ticket_no }}</strong></a></td>
                <td><a href="{{ route('admin.tickets.show', $ticket) }}" class="table-link">{{ $ticket->subject }}</a></td>
                <td>
                    @if ($ticket->customer)
                        {{ $ticket->customer->full_name }}
                    @elseif ($ticket->guest_email)
                        <span class="badge text-bg-warning me-1">Guest</span>{{ $ticket->guest_name ?? $ticket->guest_email }}
                        <small class="text-muted d-block">{{ $ticket->guest_email }}</small>
                    @else
                        —
                    @endif
                </td>
                <td><span class="badge text-bg-info">{{ ucfirst($ticket->department) }}</span></td>
                <td><x-adminlte.partials.status-badge :status="$ticket->priority" /></td>
                <td class="text-center">{{ $ticket->replies_count }}</td>
                <td><x-adminlte.partials.status-badge :status="$ticket->status" /></td>
                <td class="text-muted">{{ $ticket->last_reply_at?->diffForHumans() ?? '—' }}</td>
                <td class="text-end"><span class="text-muted">—</span></td>
            </tr>
        @empty
            <tr>
                <td colspan="9" class="text-center text-muted py-4">
                    No tickets found.
                </td>
            </tr>
        @endforelse
    </x-adminlte.partials.datatable>

    @push('js')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var REFRESH_MS = 30000;
                var STORAGE_KEY = 'admin.tickets.autoRefresh';
                var toggle = document.getElementById('tickets-auto-refresh');
                var searchInput = document.querySelector('input[aria-label="Search ticket #, subject, customer..."]');
                var timer = null;

                if (!toggle) {
                    return;
                }

                toggle.checked = localStorage.getItem(STORAGE_KEY) !== '0';

                function shouldSkip() {
                    if (document.hidden) {
                        return true;
                    }
                    if (searchInput && document.activeElement === searchInput) {
                        return true;
                    }
                    return !!document.querySelector('.dropdown-menu.show, .modal.show');
                }

                function tick() {
                    if (shouldSkip()) {
                        return;
                    }
                    window.location.reload();
                }

                function start() {
                    stop();
                    timer = window.setInterval(tick, REFRESH_MS);
                }

                function stop() {
                    if (timer) {
                        window.clearInterval(timer);
                        timer = null;
                    }
                }

                toggle.addEventListener('change', function () {
                    localStorage.setItem(STORAGE_KEY, toggle.checked ? '1' : '0');
                    toggle.checked ? start() : stop();
                });

                if (toggle.checked) {
                    start();
                }
            });
        </script>
    @endpush
@stop
