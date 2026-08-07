@extends('adminlte::page')

@section('title', 'Your Cart')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Your Cart</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('client.store.index') }}">Store</a></li>
                <li class="breadcrumb-item active">Cart</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif
    @if (session('error')) <x-adminlte-alert theme="danger" dismissible>{{ session('error') }}</x-adminlte-alert> @endif

    @php $subtotal = 0; @endphp
    @foreach ($items as $item) @php $subtotal += $item['total']; @endphp @endforeach

    <x-adminlte-card icon="bi bi-cart" title="{{ count($items) }} item(s) in cart">
        @if ($items === [])
            <x-adminlte-alert theme="info">Your cart is empty. <a href="{{ route('client.store.index') }}">Browse the store</a>.</x-adminlte-alert>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr><th>Product</th><th>Cycle</th><th>Domain</th><th>Unit Price</th><th>Qty</th><th class="text-end">Total</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($items as $idx => $item)
                            <tr>
                                <td><strong>{{ $item['product']->name }}</strong></td>
                                <td><span class="badge bg-info">{{ ucfirst(str_replace('_', ' ', $item['cycle'])) }}</span></td>
                                <td>{{ $item['domain'] ?? '—' }}</td>
                                <td>₹{{ number_format($item['unit_price'], 2) }}</td>
                                <td>
                                    <form method="POST" action="{{ route('client.store.cart.update') }}" class="d-flex align-items-center gap-1">
                                        @csrf
                                        <input type="hidden" name="index" value="{{ $idx }}">
                                        <input type="number" name="quantity" value="{{ $item['quantity'] }}" min="1" max="99" class="form-control form-control-sm" style="width:70px">
                                        <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-repeat"></i></button>
                                    </form>
                                </td>
                                <td class="text-end">₹{{ number_format($item['total'], 2) }}</td>
                                <td class="text-end">
                                    <form method="POST" action="{{ route('client.store.cart.remove') }}" class="d-inline">
                                        @csrf
                                        <input type="hidden" name="index" value="{{ $idx }}">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="5" class="text-end">Subtotal</th>
                            <th class="text-end">₹{{ number_format($subtotal, 2) }}</th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="text-end mt-3">
                <a href="{{ route('client.store.index') }}" class="btn btn-outline-secondary">Continue Shopping</a>
                <a href="{{ route('client.store.checkout') }}" class="btn btn-primary btn-lg ms-2"><i class="bi bi-credit-card me-1"></i> Proceed to Checkout</a>
            </div>
        @endif
    </x-adminlte-card>
@stop