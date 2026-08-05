@extends('adminlte::page')
@section('title', 'Checkout')
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">Checkout</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item"><a href="{{ route('admin.cart.index') }}">Cart</a></li><li class="breadcrumb-item active">Checkout</li></ol></div></div>
@stop
@section('content')
    @if (empty($items))
        <x-adminlte-alert theme="info">Your cart is empty. <a href="{{ route('admin.cart.index') }}">Browse products</a>.</x-adminlte-alert>
    @else
        <x-adminlte-card icon="bi bi-credit-card" title="Order Summary">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Product</th><th>SKU</th><th>Type</th><th>Domain</th><th class="text-end">Action</th></tr></thead>
                    <tbody>
                        @foreach ($items as $idx => $item)
                            <tr>
                                <td><strong>{{ $item['product']->name }}</strong></td>
                                <td><code>{{ $item['product']->sku }}</code></td>
                                <td><span class="badge bg-info">{{ ucfirst($item['product']->product_type) }}</span></td>
                                <td>{{ $item['domain'] ?? '—' }}</td>
                                <td class="text-end">
                                    <form method="POST" action="{{ route('admin.cart.remove') }}" class="d-inline">
                                        @csrf
                                        <input type="hidden" name="index" value="{{ $idx }}">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-adminlte-card>
        <div class="text-end">
            <a href="{{ route('admin.cart.index') }}" class="btn btn-outline-secondary">Continue Shopping</a>
            <button type="button" class="btn btn-primary btn-lg ms-2" onclick="alert('Order submission not yet implemented')">
                <i class="bi bi-check-circle me-1"></i> Place Order
            </button>
        </div>
    @endif
@stop
