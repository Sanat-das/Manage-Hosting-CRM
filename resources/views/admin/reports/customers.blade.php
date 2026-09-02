@extends('adminlte::page')

@section('title', 'Customer Report')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Customer Report</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active">Reports</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    <x-adminlte-card icon="bi bi-people" title="All Customers">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr><th>Customer</th><th>Invoices</th><th>Tickets</th><th>Domains</th><th>Hosting</th></tr>
            </thead>
            <tbody>
                @forelse ($customers as $c)
                    <tr>
                        <td>
                            <a href="{{ route('admin.customers.show', $c) }}"><strong>{{ $c->full_name }}</strong></a>
                            <div class="text-muted small">{{ $c->user?->email }}</div>
                        </td>
                        <td>{{ $c->invoices_count }}</td>
                        <td>{{ $c->tickets_count }}</td>
                        <td>{{ $c->domains_count }}</td>
                        <td>{{ $c->hosting_accounts_count }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">No customers.</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $customers->links() }}
    </x-adminlte-card>
@stop
