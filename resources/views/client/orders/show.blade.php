@extends('adminlte::page')

@section('title', 'Order ' . $order->order_no)

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Order {{ $order->order_no }}</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('client.orders.index') }}">Orders</a></li>
                <li class="breadcrumb-item active">{{ $order->order_no }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @php
        $cycleLabels = ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'semi_annual' => 'Semi-Annual',
            'annual' => 'Annual', 'biennial' => 'Biennial', 'one_time' => 'One-Time'];

        // What the customer is told each status means. Phrased as "where is my
        // order" answers, which is why they clicked through from the email.
        $statusNotes = [
            'pending' => 'We have your order. It is waiting for payment to clear.',
            'paid' => 'Payment received. Setup starts shortly.',
            'provisioning' => 'Your service is being set up right now.',
            'active' => 'Your service is live.',
            'failed' => 'Setup did not complete. Our team has been notified — open a ticket if you need an update.',
            'suspended' => 'This service is suspended. Settling any outstanding invoice usually restores it.',
            'cancelled' => 'This order was cancelled.',
            'terminated' => 'This order was terminated and the service has been removed.',
        ];
    @endphp

    <div class="row">
        <div class="col-lg-8">
            <x-adminlte-card icon="bi bi-bag-check" title="Order Details">
                <table class="table table-sm table-borderless">
                    <tr><th class="w-25 text-muted">Order #</th><td><strong>{{ $order->order_no }}</strong></td></tr>
                    <tr><th class="text-muted">Status</th>
                        <td>
                            <x-adminlte.partials.status-badge :status="$order->status" />
                            @if (isset($statusNotes[$order->status]))
                                <div class="text-muted small mt-1">{{ $statusNotes[$order->status] }}</div>
                            @endif
                        </td>
                    </tr>
                    <tr><th class="text-muted">Placed</th><td>{{ $order->created_at?->format('M j, Y H:i') ?? '—' }}</td></tr>
                    <tr><th class="text-muted">Billing cycle</th><td>{{ $cycleLabels[$order->billing_cycle] ?? ($order->billing_cycle ?? '—') }}</td></tr>
                    @if ($order->domain_name)
                        <tr><th class="text-muted">Domain</th><td>{{ $order->domain_name }}</td></tr>
                    @endif
                    @if ($order->next_billing_date)
                        <tr><th class="text-muted">Next renewal</th><td>{{ $order->next_billing_date->format('M j, Y') }}</td></tr>
                    @endif
                </table>

                {{-- What was ordered, line by line, each with the configuration
                     captured at order time (RAM / storage / support). --}}
                <table class="table table-sm mt-3">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th class="text-center">Qty</th>
                            <th class="text-end">Unit Price</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($order->items as $item)
                            <tr>
                                <td>
                                    <strong>{{ $item->product_name ?? $item->product?->name ?? '—' }}</strong>
                                    @if ($item->domain_name)
                                        <div class="text-muted small">{{ $item->domain_name }}</div>
                                    @endif
                                    @include('partials._selected_options', [
                                        'entries' => $item->config_options['options'] ?? [],
                                        'modifiersByLink' => [],
                                        'cycle' => $item->billing_cycle ?? $order->billing_cycle ?? 'monthly',
                                        'includeUnselected' => false,
                                    ])
                                </td>
                                <td class="text-center">{{ $item->quantity }}</td>
                                <td class="text-end">₹{{ number_format((float) $item->unit_price, 2) }}</td>
                                <td class="text-end">₹{{ number_format((float) $item->total, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-3">No line items recorded on this order.</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr class="fw-bold">
                            <td colspan="3" class="text-end">Order total</td>
                            <td class="text-end">₹{{ number_format((float) $order->total, 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </x-adminlte-card>

            @if ($timeline !== [])
                <x-adminlte-card icon="bi bi-clock-history" title="Progress" class="mt-3">
                    <ul class="list-unstyled mb-0">
                        @foreach ($timeline as $entry)
                            <li class="d-flex align-items-center gap-2 mb-2">
                                <x-adminlte.partials.status-badge :status="$entry['status']" variant="subtle" />
                                <span class="text-muted small">{{ $entry['at']?->format('M j, Y H:i') ?? '—' }}</span>
                            </li>
                        @endforeach
                    </ul>
                </x-adminlte-card>
            @endif
        </div>

        <div class="col-lg-4">
            <x-adminlte-card icon="bi bi-receipt" title="Invoices">
                @forelse ($order->invoices as $invoice)
                    <div class="d-flex justify-content-between align-items-center {{ $loop->last ? '' : 'border-bottom pb-2 mb-2' }}">
                        <div>
                            <a href="{{ route('client.invoices.show', $invoice->id) }}" class="text-decoration-none">
                                <strong>{{ $invoice->invoice_no }}</strong>
                            </a>
                            <div class="text-muted small">₹{{ number_format((float) $invoice->total, 2) }}</div>
                        </div>
                        <div class="text-end">
                            <x-adminlte.partials.status-badge :status="$invoice->status" />
                            {{-- Same rule the payment page enforces: a void or
                                 cancelled invoice is not payable, and neither is
                                 one already settled. --}}
                            @if (! $invoice->isFullyPaid() && ! $invoice->isVoid() && ! $invoice->isCancelled())
                                <div class="mt-1">
                                    <a href="{{ route('client.invoices.pay', $invoice->id) }}" class="btn btn-sm btn-primary">Pay</a>
                                </div>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-muted mb-0">No invoices have been raised for this order yet.</p>
                @endforelse
            </x-adminlte-card>

            @if ($order->hostingAccount)
                <x-adminlte-card icon="bi bi-hdd-stack" title="Service" class="mt-3">
                    <div class="mb-2">
                        <div class="fw-semibold">{{ $order->hostingAccount->domain ?? '—' }}</div>
                        <x-adminlte.partials.status-badge :status="$order->hostingAccount->status" />
                    </div>
                    <a href="{{ route('client.hosting.show', $order->hostingAccount->id) }}" class="btn btn-outline-secondary w-100">
                        <i class="bi bi-box-arrow-up-right me-1"></i> Manage Service
                    </a>
                </x-adminlte-card>
            @endif

            <x-adminlte-card icon="bi bi-life-preserver" title="Need help?" class="mt-3">
                <p class="text-muted small">Quote order {{ $order->order_no }} and we can pick it up from there.</p>
                <a href="{{ route('client.tickets.create') }}" class="btn btn-outline-secondary w-100">
                    <i class="bi bi-envelope me-1"></i> Open a Ticket
                </a>
            </x-adminlte-card>
        </div>
    </div>

    <div class="mt-3">
        <a href="{{ route('client.orders.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Orders
        </a>
    </div>
@stop
