@extends('adminlte::page')

@section('title', 'New Order')

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">New Order</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.orders.index') }}">Orders</a></li>
                <li class="breadcrumb-item active" aria-current="page">New Order</li>
            </ol>
        </div>
    </div>
@stop

@php
    $billingCycles = [
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly',
        'semi_annual' => 'Semi-Annual',
        'annual' => 'Annual',
        'biennial' => 'Biennial',
        'one_time' => 'One Time',
    ];

    // product_id => name/price/cycle used by the client-side total preview.
    $productMap = $products->mapWithKeys(fn ($p) => [
        $p->id => [
            'name' => $p->name,
            'price' => (float) $p->price,
            'cycle' => $p->billing_cycle,
        ],
    ]);
@endphp

@section('content')
    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible>
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-adminlte-alert>
    @endif

    <x-adminlte.partials.form-card
        icon="bi bi-cart-plus"
        title="Create Order"
        :action="route('admin.orders.store')"
        submit-label="Create Order"
        :cancel-url="route('admin.orders.index')"
    >
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-select name="customer_id" label="Customer" required>
                    <option value="">Select a customer...</option>
                    @foreach ($customers as $customer)
                        <option value="{{ $customer->id }}" @selected((string) old('customer_id') === (string) $customer->id)>
                            {{ $customer->full_name }}{{ $customer->user?->email ? ' — '.$customer->user->email : '' }}
                        </option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-6">
                <x-adminlte-select name="product_id" label="Product" required>
                    <option value="">Select a product...</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}" @selected((string) old('product_id') === (string) $product->id)>
                            {{ $product->name }} — ₹{{ number_format((float) $product->price, 2) }}
                        </option>
                    @endforeach
                </x-adminlte-select>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-select name="billing_cycle" label="Billing cycle" required>
                    @foreach ($billingCycles as $value => $label)
                        <option value="{{ $value }}" @selected(old('billing_cycle', 'monthly') === $value)>{{ $label }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-3">
                <x-adminlte-input name="quantity" type="number" label="Quantity" min="1"
                                  value="{{ old('quantity', 1) }}" required />
            </div>
            <div class="col-md-3">
                <x-adminlte-input name="unit_price" type="number" step="0.01" min="0"
                                  label="Unit price (₹)" value="{{ old('unit_price') }}" required />
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="domain_name" label="Domain name" placeholder="example.com (optional)"
                                  value="{{ old('domain_name') }}" />
            </div>
            <div class="col-md-6">
                <div class="border rounded p-3 bg-light h-100 d-flex align-items-center">
                    <div class="w-100">
                        <div class="text-muted small">Order total (preview)</div>
                        <div class="fs-3 fw-bold" id="total-preview">₹0.00</div>
                    </div>
                </div>
            </div>
        </div>

        <x-adminlte-textarea name="notes" label="Notes" rows="2"
                             placeholder="Internal notes (optional)">{{ old('notes') }}</x-adminlte-textarea>
    </x-adminlte.partials.form-card>

    @push('js')
        <script>
            window.orderProducts = @json($productMap);

            (function () {
                const qty = document.getElementById('quantity');
                const price = document.getElementById('unit_price');
                const total = document.getElementById('total-preview');
                const product = document.getElementById('product_id');
                const cycle = document.getElementById('billing_cycle');

                const fmt = (n) => '₹' + Number(n).toLocaleString('en-IN', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2,
                });

                function updateTotal() {
                    const q = parseFloat(qty?.value) || 0;
                    const p = parseFloat(price?.value) || 0;
                    if (total) total.textContent = fmt(q * p);
                }

                qty?.addEventListener('input', updateTotal);
                price?.addEventListener('input', updateTotal);

                product?.addEventListener('change', function () {
                    const data = window.orderProducts?.[this.value];
                    if (!data) return;
                    if (price) price.value = data.price.toFixed(2);
                    if (cycle && data.cycle && !cycle.value) cycle.value = data.cycle;
                    updateTotal();
                });

                updateTotal();
            })();
        </script>
    @endpush
@stop
