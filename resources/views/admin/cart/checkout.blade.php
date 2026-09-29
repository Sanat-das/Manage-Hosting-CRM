@extends('adminlte::page')
@section('title', 'Checkout')
@section('content_header')
    <x-ui.page-header title="Checkout" subtitle="Review and place your order" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Cart', 'url' => route('admin.cart.index')],
        ['label' => 'Checkout', 'active' => true],
    ]" />
@stop
@section('content')
    <x-adminlte.partials.flash-alert />
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
                            @php
                                $isAddonPreview = (bool) ($item['preview_addon'] ?? false);
                                $subtotal += $item['total'];
                            @endphp
                            <tr>
                                <td>
                                    <strong>{{ $isAddonPreview ? $item['product_name'] : $item['product']->name }}</strong>
                                    @if ($isAddonPreview)
                                        <span class="badge text-bg-info ms-1">Add-on</span>
                                    @endif
                                </td>
                                <td><span class="badge text-bg-info">{{ ucfirst(str_replace('_', ' ', $isAddonPreview ? $item['billing_cycle'] : $item['cycle'])) }}</span></td>
                                <td>{{ $item['quantity'] }}</td>
                                <td>₹{{ number_format($item['unit_price'], 2) }}</td>
                                <td>₹{{ number_format($item['total'], 2) }}</td>
                                <td>{{ $item['domain'] ?? '—' }}</td>
                                <td class="text-end">
                                    @unless ($isAddonPreview)
                                        <form method="POST" action="{{ route('admin.cart.remove') }}" class="d-inline">
                                            @csrf
                                            <input type="hidden" name="index" value="{{ $idx }}">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Remove item"><i class="bi bi-trash" aria-hidden="true"></i></button>
                                        </form>
                                    @endunless
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
