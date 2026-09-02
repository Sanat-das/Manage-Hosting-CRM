@extends('adminlte::page')
@section('title', 'Search Results')
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">Search Results</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item active">Search</li></ol></div></div>
@stop
@section('content')
    <x-adminlte-card icon="bi bi-search" title="Search">
        <form method="GET" action="{{ route('admin.search.index') }}">
            <div class="input-group mb-3">
                <input type="text" name="q" class="form-control" placeholder="Search customers, services, invoices, tickets, products..." value="{{ $q }}">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i></button>
            </div>
        </form>
    </x-adminlte-card>

    @if ($q && strlen($q) >= 2)
        @php $total = ($results['customers']->count() ?? 0) + ($results['services']->count() ?? 0) + ($results['invoices']->count() ?? 0) + ($results['tickets']->count() ?? 0) + ($results['products']->count() ?? 0); @endphp

        @if ($total === 0)
            <x-adminlte-alert theme="info">No results found for "{{ $q }}".</x-adminlte-alert>
        @endif

        @if (isset($results['customers']) && $results['customers']->count())
            <x-adminlte-card icon="bi bi-people" title="Customers ({{ $results['customers']->count() }})">
                <ul class="list-group list-group-flush">
                    @foreach ($results['customers'] as $c)
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span><a href="{{ route('admin.customers.show', $c) }}" class="table-link"><strong>{{ $c->full_name }}</strong></a> — {{ $c->user?->email }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-adminlte-card>
        @endif

        @if (isset($results['services']) && $results['services']->count())
            <x-adminlte-card icon="bi bi-hdd-network" title="Services ({{ $results['services']->count() }})">
                <ul class="list-group list-group-flush">
                    @foreach ($results['services'] as $s)
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span><a href="{{ route('admin.service-instances.show', $s) }}" class="table-link"><strong>{{ $s->domain ?? $s->username }}</strong></a> — {{ $s->customer?->full_name ?? '—' }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-adminlte-card>
        @endif

        @if (isset($results['invoices']) && $results['invoices']->count())
            <x-adminlte-card icon="bi bi-receipt" title="Invoices ({{ $results['invoices']->count() }})">
                <ul class="list-group list-group-flush">
                    @foreach ($results['invoices'] as $inv)
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span><a href="{{ route('admin.invoices.show', $inv) }}" class="table-link"><strong>{{ $inv->invoice_no }}</strong></a> — ${{ number_format($inv->total, 2) }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-adminlte-card>
        @endif

        @if (isset($results['tickets']) && $results['tickets']->count())
            <x-adminlte-card icon="bi bi-ticket" title="Tickets ({{ $results['tickets']->count() }})">
                <ul class="list-group list-group-flush">
                    @foreach ($results['tickets'] as $t)
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span><a href="{{ route('admin.tickets.show', $t) }}" class="table-link"><strong>{{ $t->ticket_no }}</strong></a> — {{ $t->subject }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-adminlte-card>
        @endif

        @if (isset($results['products']) && $results['products']->count())
            <x-adminlte-card icon="bi bi-box-seam" title="Products ({{ $results['products']->count() }})">
                <ul class="list-group list-group-flush">
                    @foreach ($results['products'] as $p)
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span><a href="{{ route('admin.catalog-products.show', $p) }}" class="table-link"><strong>{{ $p->name }}</strong></a> — <code>{{ $p->sku }}</code></span>
                        </li>
                    @endforeach
                </ul>
            </x-adminlte-card>
        @endif
    @endif
@stop
