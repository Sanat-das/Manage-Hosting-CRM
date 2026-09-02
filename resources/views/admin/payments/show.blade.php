@extends('adminlte::page')

@section('title', 'Payment #' . $payment->id)

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Payment #{{ $payment->id }}</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.payments.index') }}">Payments</a></li>
                <li class="breadcrumb-item active">#{{ $payment->id }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    <x-adminlte-card>
        <table class="table table-sm table-borderless">
            <tbody>
                <tr><th class="w-25 text-muted">Invoice</th><td><a href="{{ route('admin.invoices.show', $payment->invoice) }}">{{ $payment->invoice?->invoice_no ?? '—' }}</a></td></tr>
                <tr><th class="text-muted">Customer</th><td>{{ $payment->invoice?->customer?->full_name ?? '—' }}</td></tr>
                <tr><th class="text-muted">Amount</th><td class="fw-bold">{{ number_format($payment->amount, 2) }}</td></tr>
                <tr><th class="text-muted">Method</th><td>{{ \App\Models\Payment::METHOD_LABELS[$payment->method] ?? ucfirst(str_replace('_', ' ', $payment->method)) }}</td></tr>
                <tr><th class="text-muted">Transaction ID</th><td>{{ $payment->transaction_id ?? '—' }}</td></tr>
                <tr><th class="text-muted">Status</th><td><x-adminlte.partials.status-badge :status="$payment->status" /></td></tr>
                <tr><th class="text-muted">Date</th><td>{{ $payment->created_at?->format('M j, Y H:i') }}</td></tr>
                @if ($payment->notes)
                    <tr><th class="text-muted">Notes</th><td>{{ $payment->notes }}</td></tr>
                @endif
            </tbody>
        </table>
    </x-adminlte-card>
@stop
