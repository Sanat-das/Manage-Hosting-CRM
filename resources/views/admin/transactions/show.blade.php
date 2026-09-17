@extends('adminlte::page')

@section('title', 'Transaction #' . $transaction->id)

@section('content_header')
    <x-ui.page-header title="Transaction #{{ $transaction->id }}" subtitle="View transaction details and payment status" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')], ['label' => 'Transactions', 'url' => route('admin.transactions.index')], ['label' => '#' . $transaction->id, 'active' => true]]" />
@stop

@section('content')
    <x-adminlte-card>
        <table class="table table-sm table-borderless">
            <tbody>
                <tr><th class="w-25 text-muted">Customer</th><td>{{ $transaction->customer?->full_name ?? '—' }}</td></tr>
                <tr>
                    <th class="text-muted">Invoice</th>
                    <td>
                        @if ($transaction->invoice)
                            <a href="{{ route('admin.invoices.show', $transaction->invoice) }}">{{ $transaction->invoice->invoice_no }}</a>
                        @else
                            —
                        @endif
                    </td>
                </tr>
                <tr><th class="text-muted">Amount</th><td class="fw-bold">{{ number_format($transaction->amount, 2) }}</td></tr>
                <tr><th class="text-muted">Fee</th><td>{{ number_format($transaction->fee, 2) }}</td></tr>
                <tr><th class="text-muted">Net amount</th><td class="fw-bold">{{ number_format($transaction->net_amount, 2) }}</td></tr>
                <tr><th class="text-muted">Currency</th><td>{{ $transaction->currency }}</td></tr>
                <tr><th class="text-muted">Payment method</th><td>{{ ucfirst(str_replace('_', ' ', $transaction->payment_method)) }}</td></tr>
                <tr><th class="text-muted">Transaction ID</th><td>{{ $transaction->transaction_id ?? '—' }}</td></tr>
                <tr><th class="text-muted">Status</th><td><x-adminlte.partials.status-badge :status="$transaction->status" /></td></tr>
                <tr><th class="text-muted">Date</th><td>{{ $transaction->created_at?->format('M j, Y H:i') }}</td></tr>
                @if ($transaction->notes)
                    <tr><th class="text-muted">Notes</th><td>{{ $transaction->notes }}</td></tr>
                @endif
            </tbody>
        </table>
    </x-adminlte-card>
@stop
