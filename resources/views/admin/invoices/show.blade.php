@extends('adminlte::page')

@section('title', $invoice->invoice_no)

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">{{ $invoice->invoice_no }}</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.invoices.index') }}">Invoices</a></li>
                <li class="breadcrumb-item active">{{ $invoice->invoice_no }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif

    <div class="d-flex justify-content-between mb-3">
        <div>
            <x-adminlte.partials.status-badge :status="$invoice->status" />
            <span class="text-muted ms-2">Created: {{ $invoice->created_at?->format('M j, Y') }}</span>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.invoices.pdf', $invoice) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-pdf me-1"></i> PDF</a>
            @can('invoices.edit')
                <a href="{{ route('admin.invoices.edit', $invoice) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i> Edit</a>
            @endcan
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <x-adminlte-card title="Line Items">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Description</th><th class="text-center">Qty</th><th class="text-end">Unit Price</th><th class="text-end">Total</th></tr></thead>
                    <tbody>
                        @forelse ($invoice->items as $item)
                            <tr>
                                <td>{{ $item->description }}</td>
                                <td class="text-center">{{ $item->quantity }}</td>
                                <td class="text-end">{{ number_format($item->unit_price, 2) }}</td>
                                <td class="text-end">{{ number_format($item->total, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted">No items.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-adminlte-card>

            {{-- GST Breakdown --}}
            @if ($gstBreakdown['tax'] > 0)
                <x-adminlte-card title="GST Breakdown">
                    <div class="row text-center">
                        @if ($gstBreakdown['type'] === 'intra')
                            <div class="col-4">
                                <div class="text-muted small">CGST</div>
                                <strong>{{ number_format($gstBreakdown['cgst'], 2) }}</strong>
                                <div class="text-muted small">@ {{ $gstBreakdown['cgst_rate'] }}%</div>
                            </div>
                            <div class="col-4">
                                <div class="text-muted small">SGST</div>
                                <strong>{{ number_format($gstBreakdown['sgst'], 2) }}</strong>
                                <div class="text-muted small">@ {{ $gstBreakdown['sgst_rate'] }}%</div>
                            </div>
                        @else
                            <div class="col-6">
                                <div class="text-muted small">IGST</div>
                                <strong>{{ number_format($gstBreakdown['igst'], 2) }}</strong>
                                <div class="text-muted small">@ {{ $gstBreakdown['igst_rate'] }}%</div>
                            </div>
                        @endif
                    </div>
                </x-adminlte-card>
            @endif

            {{-- Payments --}}
            <x-adminlte-card title="Payment History">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Date</th><th>Method</th><th>Transaction ID</th><th class="text-end">Amount</th></tr></thead>
                    <tbody>
                        @forelse ($invoice->payments as $payment)
                            <tr>
                                <td class="text-muted">{{ $payment->created_at?->format('M j, Y H:i') }}</td>
                                <td>{{ ucfirst(str_replace('_', ' ', $payment->method)) }}</td>
                                <td class="text-muted">{{ $payment->transaction_id ?? '—' }}</td>
                                <td class="text-end fw-bold">{{ number_format($payment->amount, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-3">No payments recorded.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-adminlte-card>
        </div>

        <div class="col-lg-4">
            <x-adminlte-card title="Summary">
                <table class="table table-sm table-borderless mb-0">
                    <tbody>
                        <tr><th class="text-muted">Customer</th><td>{{ $invoice->customer->full_name ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Subtotal</th><td class="text-end">{{ number_format($invoice->amount, 2) }}</td></tr>
                        <tr><th class="text-muted">Tax</th><td class="text-end">{{ number_format($invoice->tax, 2) }}</td></tr>
                        <tr><th class="text-muted">Discount</th><td class="text-end">-{{ number_format($invoice->discount, 2) }}</td></tr>
                        <tr class="border-top"><th>Total</th><td class="text-end fw-bold">{{ number_format($invoice->total, 2) }}</td></tr>
                        <tr><th class="text-muted">Paid</th><td class="text-end text-success">{{ number_format($invoice->paid_amount ?? 0, 2) }}</td></tr>
                        <tr><th>Due</th><td class="text-end fw-bold {{ ($invoice->total - ($invoice->paid_amount ?? 0)) > 0 ? 'text-danger' : 'text-success' }}">
                            {{ number_format(max(0, $invoice->total - ($invoice->paid_amount ?? 0)), 2) }}
                        </td></tr>
                        <tr><th class="text-muted">Due date</th><td>{{ $invoice->due_date?->format('M j, Y') ?? '—' }}</td></tr>
                    </tbody>
                </table>
            </x-adminlte-card>

            @if ($invoice->notes)
                <x-adminlte-card title="Notes" class="mt-3">
                    <p class="mb-0">{{ $invoice->notes }}</p>
                </x-adminlte-card>
            @endif
        </div>
    </div>
@stop
