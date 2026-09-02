@extends('adminlte::page')

@section('title', 'Order Placed')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Order Placed</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('client.store.index') }}">Store</a></li>
                <li class="breadcrumb-item active">Order {{ $order->order_no }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @include('client.store._alerts')

    <x-adminlte-callout theme="success" title="Order placed successfully">
        Your order <strong>{{ $order->order_no }}</strong> has been received and is currently
        <strong>{{ $order->status }}</strong>. You can continue shopping or track your invoices.
    </x-adminlte-callout>

    <x-adminlte-card icon="bi bi-receipt" title="Order {{ $order->order_no }}">
        <div class="row mb-3">
            <div class="col-md-3"><strong>Order #</strong><br>{{ $order->order_no }}</div>
            <div class="col-md-3"><strong>Status</strong><br><span class="badge text-bg-warning">{{ ucfirst($order->status) }}</span></div>
            <div class="col-md-3"><strong>Placed</strong><br>{{ $order->created_at?->format('M j, Y g:i A') }}</div>
            <div class="col-md-3"><strong>Total</strong><br>₹{{ number_format($order->total, 2) }}</div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr><th>Product</th><th>Qty</th><th class="text-end">Unit Price</th><th class="text-end">Total</th></tr>
                </thead>
                <tbody>
                    @foreach ($order->items as $item)
                        <tr>
                            <td><strong>{{ $item->product_name }}</strong></td>
                            <td>{{ $item->quantity }}</td>
                            <td class="text-end">₹{{ number_format($item->unit_price, 2) }}</td>
                            <td class="text-end">₹{{ number_format($item->total, 2) }}</td>
                        </tr>
                        @if (! empty($item->config_options['options']))
                            <tr>
                                <td colspan="4" class="pt-0 border-0">
                                    @include('client.partials._selected_options', [
                                        'entries' => $item->config_options['options'],
                                        'modifiersByLink' => [],
                                        'cycle' => $order->billing_cycle ?? 'monthly',
                                        'includeUnselected' => true,
                                    ])
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-adminlte-card>

    <div class="mt-3">
        <a href="{{ route('client.store.index') }}" class="btn btn-primary"><i class="bi bi-shop me-1"></i> Back to Store</a>
        <a href="{{ route('client.invoices.index') }}" class="btn btn-outline-secondary ms-2"><i class="bi bi-receipt me-1"></i> View Invoices</a>
    </div>
@stop
