@extends('adminlte::page')

@section('title', 'Checkout')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Checkout</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('client.store.cart') }}">Cart</a></li>
                <li class="breadcrumb-item active">Checkout</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @php $total = 0; @endphp
    @foreach ($items as $item) @php $total += $item['total']; @endphp @endforeach

    <x-adminlte-card icon="bi bi-credit-card" title="Order Summary">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr><th>Product</th><th>Cycle</th><th>Domain</th><th>Qty</th><th>Unit Price</th><th class="text-end">Total</th></tr>
                </thead>
                <tbody>
                    @foreach ($items as $item)
                        <tr>
                            <td><strong>{{ $item['product']->name }}</strong></td>
                            <td><span class="badge bg-info">{{ ucfirst(str_replace('_', ' ', $item['cycle'])) }}</span></td>
                            <td>{{ $item['domain'] ?? '—' }}</td>
                            <td>{{ $item['quantity'] }}</td>
                            <td>₹{{ number_format($item['unit_price'], 2) }}</td>
                            <td class="text-end">₹{{ number_format($item['total'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="5" class="text-end">Grand Total</th>
                        <th class="text-end">₹{{ number_format($total, 2) }}</th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </x-adminlte-card>

    <x-adminlte-card icon="bi bi-person" title="Billing Information">
        <div class="row">
            <div class="col-md-6">
                <strong>{{ auth()->user()->full_name }}</strong><br>
                <span class="text-muted">{{ auth()->user()->email }}</span>
            </div>
        </div>
    </x-adminlte-card>

    <div class="text-end mt-3">
        <a href="{{ route('client.store.cart') }}" class="btn btn-outline-secondary">Back to Cart</a>
        <form method="POST" action="{{ route('client.store.checkout.post') }}" class="d-inline">
            @csrf
            <button type="submit" class="btn btn-primary btn-lg ms-2"><i class="bi bi-check-circle me-1"></i> Place Order</button>
        </form>
    </div>
@stop