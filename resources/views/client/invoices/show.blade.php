@extends('adminlte::page')

@section('title', 'Invoice ' . $invoice->invoice_no)

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Invoice {{ $invoice->invoice_no }}</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('client.invoices.index') }}">Invoices</a></li>
                <li class="breadcrumb-item active">{{ $invoice->invoice_no }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    <div class="row">
        <div class="col-lg-8">
            <x-adminlte-card icon="bi bi-receipt" title="Invoice Details">
                <table class="table table-sm table-borderless">
                    <tr><th class="w-25 text-muted">Invoice #</th><td><strong>{{ $invoice->invoice_no }}</strong></td></tr>
                    <tr><th class="text-muted">Status</th>
                        <td>
                            @php $badge = ['paid'=>'success','sent'=>'info','overdue'=>'danger','draft'=>'secondary','cancelled'=>'dark'][$invoice->status] ?? 'secondary'; @endphp
                            <span class="badge bg-{{ $badge }}">{{ ucfirst($invoice->status) }}</span>
                        </td>
                    </tr>
                    <tr><th class="text-muted">Created</th><td>{{ $invoice->created_at?->format('M j, Y H:i') }}</td></tr>
                    <tr><th class="text-muted">Due date</th><td>{{ $invoice->due_date?->format('M j, Y') ?? '—' }}</td></tr>
                </table>

                {{-- Line items breakdown --}}
                <table class="table table-sm mt-3">
                    <thead><tr><th>Description</th><th class="text-end">Amount</th></tr></thead>
                    <tbody>
                        <tr><td>Subtotal</td><td class="text-end">{{ number_format($invoice->amount, 2) }}</td></tr>
                        @if ($invoice->gst_enabled && $invoice->tax > 0)
                            <tr><td class="text-muted">Tax ({{ number_format($invoice->tax_rate, 1) }}%)</td><td class="text-end text-muted">{{ number_format($invoice->tax, 2) }}</td></tr>
                        @endif
                        @if ($invoice->discount > 0)
                            <tr><td class="text-success">Discount</td><td class="text-end text-success">-{{ number_format($invoice->discount, 2) }}</td></tr>
                        @endif
                        <tr class="fw-bold"><td>Total</td><td class="text-end">{{ number_format($invoice->total, 2) }}</td></tr>
                    </tbody>
                </table>

                @if ($invoice->notes)
                    <div class="mt-3 p-3 bg-light rounded small">{{ $invoice->notes }}</div>
                @endif
            </x-adminlte-card>
        </div>

        <div class="col-lg-4">
            <x-adminlte-card icon="bi bi-wallet2" title="Payment">
                <div class="mb-3">
                    <div class="text-muted small">Total Due</div>
                    <div class="fs-4 fw-bold">{{ number_format($invoice->total, 2) }}</div>
                </div>
                @if ($invoice->status === 'paid')
                    <div class="alert alert-success mb-0"><i class="bi bi-check-circle me-1"></i> Paid {{ $invoice->paid_at?->format('M j, Y') }}</div>
                @else
                    <a href="{{ route('client.invoices.pay', $invoice) }}" class="btn btn-primary btn-block">
                        <i class="bi bi-credit-card me-1"></i> Pay Now
                    </a>
                @endif
            </x-adminlte-card>

            <x-adminlte-card icon="bi bi-download" title="Download" class="mt-3">
                <a href="{{ route('client.invoices.pdf', $invoice) }}" class="btn btn-outline-secondary w-100">
                    <i class="bi bi-file-earmark-pdf me-1"></i> Download PDF
                </a>
            </x-adminlte-card>
        </div>
    </div>
@stop
