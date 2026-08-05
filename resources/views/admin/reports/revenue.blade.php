@extends('adminlte::page')

@section('title', 'Revenue Report')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Revenue Report</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active">Reports</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    <x-adminlte-card icon="bi bi-funnel" title="Filter">
        <form method="GET" class="d-flex gap-2 align-items-end">
            <x-adminlte-input name="from" type="date" label="From" value="{{ $from }}" />
            <x-adminlte-input name="to" type="date" label="To" value="{{ $to }}" />
            <button class="btn btn-primary"><i class="bi bi-search me-1"></i> Filter</button>
        </form>
    </x-adminlte-card>

    <x-adminlte-card icon="bi bi-currency-rupee" title="Paid Invoices">
        <div class="mb-3"><strong>Total: ₹{{ number_format($total, 2) }}</strong></div>
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr><th>Invoice #</th><th>Customer</th><th>Paid At</th><th class="text-end">Amount</th></tr>
            </thead>
            <tbody>
                @forelse ($invoices as $inv)
                    <tr>
                        <td><strong>{{ $inv->invoice_no }}</strong></td>
                        <td>{{ $inv->customer?->full_name ?? '—' }}</td>
                        <td class="text-muted">{{ $inv->paid_at?->format('M j, Y') }}</td>
                        <td class="text-end fw-bold">₹{{ number_format($inv->total, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center text-muted py-4">No invoices in this period.</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $invoices->links() }}
    </x-adminlte-card>
@stop
