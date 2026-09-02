@extends('adminlte::page')
@section('title', 'Checkout')
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">Checkout</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item"><a href="{{ route('admin.cart.index') }}">Cart</a></li><li class="breadcrumb-item active">Checkout</li></ol></div></div>
@stop
@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif
    @if (session('error')) <x-adminlte-alert theme="danger" dismissible>{{ session('error') }}</x-adminlte-alert> @endif
    @if ($errors->any())
        <x-adminlte-alert theme="warning" dismissible>
            <ul class="mb-0">
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </x-adminlte-alert>
    @endif
    @if (empty($items))
        <x-adminlte-alert theme="info">Your cart is empty. <a href="{{ route('admin.cart.index') }}">Browse products</a>.</x-adminlte-alert>
    @else
        <x-adminlte-card icon="bi bi-credit-card" title="Order Summary">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Product</th><th>Cycle</th><th>Qty</th><th>Unit Price</th><th>Subtotal</th><th>Domain</th><th class="text-end">Action</th></tr></thead>
                    <tbody>
                        @php $subtotal = 0; @endphp
                        @foreach ($items as $idx => $item)
                            @php $subtotal += $item['total']; @endphp
                            <tr>
                                <td><strong>{{ $item['product']->name }}</strong></td>
                                <td><span class="badge text-bg-info">{{ ucfirst(str_replace('_', ' ', $item['cycle'])) }}</span></td>
                                <td>{{ $item['quantity'] }}</td>
                                <td>₹{{ number_format($item['unit_price'], 2) }}</td>
                                <td>₹{{ number_format($item['total'], 2) }}</td>
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
                    <tfoot>
                        <tr>
                            <th colspan="4" class="text-end">Subtotal</th>
                            <th>₹{{ number_format($subtotal, 2) }}</th>
                            <th colspan="2"></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </x-adminlte-card>

        <form method="POST" action="{{ route('admin.cart.place-order') }}">
            @csrf
            <div class="row">
                <div class="col-md-6">
                    <x-adminlte-card icon="bi bi-person" title="Customer">
                        <x-adminlte-select name="customer_id" label="Customer" enable-old-support required>
                            <option value="">— Select a customer —</option>
                            @foreach ($customers as $customer)
                                <option value="{{ $customer->id }}">{{ $customer->full_name }} ({{ $customer->user?->email }})</option>
                            @endforeach
                        </x-adminlte-select>
                    </x-adminlte-card>
                </div>
            </div>
            <div class="text-end mt-3">
                <a href="{{ route('admin.cart.index') }}" class="btn btn-outline-secondary">Continue Shopping</a>
                <button type="submit" class="btn btn-primary btn-lg ms-2">
                    <i class="bi bi-check-circle me-1"></i> Place Order
                </button>
            </div>
        </form>
    @endif
@stop
