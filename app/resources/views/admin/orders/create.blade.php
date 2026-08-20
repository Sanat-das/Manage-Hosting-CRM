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

    $optionCycleSuffix = [
        'free' => 'free', 'one_time' => 'once', 'monthly' => 'mo', 'quarterly' => 'qtr',
        'semi_annual' => '6mo', 'annual' => 'yr', 'biennial' => '2yr', 'triennial' => '3yr',
    ];

    // product_id => everything the client-side line editor needs: pricing,
    // order-form flags, GST config (for the tax estimate) and the option
    // links (rendered per type when the line's product is chosen).
    $productMap = $products->mapWithKeys(function ($p) use ($billingCycles, $optionCycleSuffix) {
        $optionLinks = $p->optionLinks->map(function ($link) use ($optionCycleSuffix) {
            $base = [
                'id' => $link->id,
                'name' => $link->group?->name ?? 'Option',
                'type' => $link->group?->type ?? 'dropdown',
                // Customer-editable links render controls; informational links
                // are display-only (their values are fixed by the catalog).
                'customerEditable' => (bool) $link->customer_editable,
            ];

            if (in_array($link->group?->type, \App\Models\ProductOptionGroup::CONTINUOUS_TYPES, true)) {
                $base['min'] = $link->input_min ?? $link->group?->input_min ?? 0;
                $base['max'] = $link->input_max ?? $link->group?->input_max ?? 100;
                $base['step'] = $link->input_step ?? $link->group?->input_step ?? 1;
                $base['placeholder'] = $link->input_placeholder ?? $link->group?->input_placeholder;
                $base['unit'] = $link->unitPricing
                    ->mapWithKeys(fn ($price) => [(string) $price->billing_cycle => (float) $price->price_modifier])
                    ->all();
                // Continuous groups may carry catalog values as well
                // (e.g. informational sliders); informational links render
                // them as a display list, so keep them in the payload.
                $base['values'] = $link->linkValues->map(fn ($value) => [
                    'label' => $value->label,
                    'default' => (bool) $value->is_default,
                    'modifiers' => $value->pricing
                        ->mapWithKeys(fn ($price) => [(string) $price->billing_cycle => (float) $price->price_modifier])
                        ->all(),
                ])->all();
            } else {
                $base['values'] = $link->linkValues->map(fn ($value) => [
                    'label' => $value->label,
                    'default' => (bool) $value->is_default,
                    'modifiers' => $value->pricing
                        ->mapWithKeys(fn ($price) => [(string) $price->billing_cycle => (float) $price->price_modifier])
                        ->all(),
                ])->all();

                // Checkbox groups cap selections (input_max, else all values).
                if ($link->group?->type === 'checkbox') {
                    $base['max'] = (int) ($link->input_max ?? $link->group?->input_max ?? $link->linkValues->count());
                }
            }

            return $base;
        });

        return [$p->id => [
            'name' => $p->name,
            'price' => (float) $p->price,
            'cycle' => $p->billing_cycle,
            'requiresDomain' => (bool) $p->require_domain,
            'singleUnit' => $p->isSingleUnit(),
            'paymentType' => (string) ($p->payment_type ?? 'recurring'),
            'quantityBehaviour' => (string) ($p->quantity_behaviour ?? 'multiple_services'),
            'autoTerminate' => [
                'value' => (int) ($p->auto_terminate_value ?? 0),
                'unit' => (string) ($p->auto_terminate_unit ?? 'days'),
            ],
            // Cycles the ladder offers, falling back to the full vocabulary
            // for legacy products without ladder rows (the same fallback the
            // server-side OrderRequest uses).
            'availableCycles' => array_values(array_intersect(
                $p->pricing->pluck('billing_cycle')->all(),
                array_keys($billingCycles)
            )) ?: array_keys($billingCycles),
            'prices' => $p->pricing
                ->keyBy('billing_cycle')
                ->map(fn ($row) => (float) $row->price)
                ->all(),
            'promo' => $p->pricing
                ->mapWithKeys(fn ($row) => [(string) $row->billing_cycle => [
                    'price' => $row->promo_price !== null ? (float) $row->promo_price : null,
                    'active' => $row->promo_start !== null && $row->promo_end !== null
                        && now()->between($row->promo_start, $row->promo_end),
                ]])->all(),
            'gst' => [
                'enabled' => (bool) ($p->gst_enabled ?? false),
                'type' => (string) ($p->gst_type ?? 'standard'),
                'cgst' => $p->cgst_rate !== null ? (float) $p->cgst_rate : null,
                'sgst' => $p->sgst_rate !== null ? (float) $p->sgst_rate : null,
                'igst' => $p->igst_rate !== null ? (float) $p->igst_rate : null,
            ],
            'optionLinks' => $optionLinks,
        ]];
    });

    // Restore submitted lines after a validation error (one default line on
    // a fresh render). old() returns null when never submitted.
    $linesOld = old('lines', [null]);
    $paymentMethods = $paymentMethods ?? [];
    $gstSettings = $gstSettings ?? [];
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
        form-id="order-create-form"
        :show-footer="false"
    >
        <x-slot name="tools">
            <a href="{{ route('admin.orders.index') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-x-lg me-1" aria-hidden="true"></i> Cancel
            </a>
            <button type="submit" form="order-create-form" class="btn btn-sm btn-primary">
                <i class="bi bi-check-lg me-1" aria-hidden="true"></i> Create Order
            </button>
        </x-slot>

        {{-- Customer + inline creation --}}
        <h6 class="mb-2"><i class="bi bi-person me-1"></i>Customer</h6>
        <div class="row g-3">
            <div class="col-md-8">
                <x-adminlte-select name="customer_id" label="Customer" required>
                    <option value="">Select a customer...</option>
                    @foreach ($customers as $customer)
                        <option value="{{ $customer->id }}" data-state-code="{{ $customer->state_code ?? '' }}"
                                @selected((string) old('customer_id') === (string) $customer->id)>
                            {{ $customer->full_name }}{{ $customer->user?->email ? ' — '.$customer->user->email : '' }}
                        </option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-4">
                {{-- Invisible label matching the select component's form-label
                     exactly, so the button aligns with the input (not 7px
                     higher, as a small/mb-1 spacer would). --}}
                <label class="form-label invisible">Customer</label>
                <button type="button" id="new-customer-btn" class="btn btn-outline-primary w-100" data-bs-toggle="modal" data-bs-target="#new-customer-modal">
                    <i class="bi bi-person-plus me-1"></i> New Customer
                </button>
            </div>
        </div>

        {{-- Order lines --}}
        <div class="border rounded p-2 mb-3">
            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-2">
                <h6 class="mb-0"><i class="bi bi-box-seam me-1"></i>Products / Services</h6>
                <span class="text-muted small">The first line is the order's primary product.</span>
            </div>
            <div id="order-lines">
                @foreach ($linesOld as $index => $line)
                    @php
                        $line = $line ?? [];
                        $lineError = fn (string $key) => $errors->first("lines.$index.$key");
                    @endphp
                    <div class="order-line border rounded mb-2 overflow-hidden" data-line-index="{{ $index }}"
                         data-submitted-options='{{ json_encode($line['options'] ?? []) }}'>
                        <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap px-2 py-1 bg-body-secondary border-bottom">
                            <div class="small fw-semibold text-muted">
                                <i class="bi bi-box-seam me-1" aria-hidden="true"></i><span class="line-service-label">Service {{ $index + 1 }}</span>
                                @if ($index === 0)<span class="badge text-bg-primary ms-1">Primary</span>@endif
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="small fw-bold line-total text-nowrap">—</span>
                                <span class="small text-muted line-adjustment text-nowrap"></span>
                                <button type="button" class="btn btn-sm btn-outline-danger line-remove" title="Remove line" aria-label="Remove line">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                        <div class="p-2">
                            <div class="row g-2 align-items-end">
                                <div class="col-md-4">
                                    <label class="form-label small text-muted mb-1" for="line-product-{{ $index }}">Product</label>
                                    <select class="form-select form-select-sm line-product {{ $lineError('product_id') ? 'is-invalid' : '' }}"
                                            id="line-product-{{ $index }}"
                                            name="lines[{{ $index }}][product_id]" required>
                                        <option value="">— Select product —</option>
                                        @foreach ($products as $product)
                                            <option value="{{ $product->id }}" @selected((string) ($line['product_id'] ?? '') === (string) $product->id)>{{ $product->name }}</option>
                                        @endforeach
                                    </select>
                                    @if ($lineError('product_id'))<div class="invalid-feedback d-block">{{ $lineError('product_id') }}</div>@endif
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small text-muted mb-1" for="line-cycle-{{ $index }}">Billing cycle</label>
                                    <select class="form-select form-select-sm line-cycle {{ $lineError('billing_cycle') ? 'is-invalid' : '' }}"
                                            id="line-cycle-{{ $index }}"
                                            name="lines[{{ $index }}][billing_cycle]" required>
                                        @foreach ($billingCycles as $value => $label)
                                            <option value="{{ $value }}" @selected((string) ($line['billing_cycle'] ?? 'monthly') === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @if ($lineError('billing_cycle'))<div class="invalid-feedback d-block">{{ $lineError('billing_cycle') }}</div>@endif
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small text-muted mb-1" for="line-qty-{{ $index }}">Quantity</label>
                                    <input type="number" class="form-control form-control-sm line-qty {{ $lineError('quantity') ? 'is-invalid' : '' }}"
                                           id="line-qty-{{ $index }}"
                                           name="lines[{{ $index }}][quantity]" min="1" max="99"
                                           value="{{ $line['quantity'] ?? 1 }}" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small text-muted mb-1" for="line-price-{{ $index }}">Unit price (₹)</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text">₹</span>
                                        <input type="number" step="0.01" min="0" class="form-control form-control-sm line-price {{ $lineError('unit_price') ? 'is-invalid' : '' }}"
                                               id="line-price-{{ $index }}" name="lines[{{ $index }}][unit_price]"
                                               value="{{ $line['unit_price'] ?? '' }}" required>
                                    </div>
                                    @if ($lineError('unit_price'))<div class="invalid-feedback d-block">{{ $lineError('unit_price') }}</div>@endif
                                </div>
                            </div>
                            @if ($lineError('quantity'))<div class="invalid-feedback d-block">{{ $lineError('quantity') }}</div>@endif
                            <div class="form-text small d-none line-qty-hint"><i class="bi bi-info-circle me-1"></i>Sold as a single unit.</div>
                            {{-- Outside the aligned field row so it never breaks
                                 the controls' baseline. --}}
                            <div class="form-check mt-2 mb-0">
                                <input class="form-check-input line-override" type="checkbox"
                                       name="lines[{{ $index }}][override]" value="1"
                                       id="line-override-{{ $index }}" @checked(! empty($line['override']))>
                                <label class="form-check-label small text-muted" for="line-override-{{ $index }}">Custom amount</label>
                            </div>
                            <div class="row g-2 line-domain-wrap d-none mt-1">
                                <div class="col-md-6">
                                    <label class="form-label small text-muted mb-1" for="line-domain-{{ $index }}">Domain name</label>
                                    <input type="text" class="form-control form-control-sm line-domain {{ $lineError('domain_name') ? 'is-invalid' : '' }}"
                                           id="line-domain-{{ $index }}"
                                           name="lines[{{ $index }}][domain_name]" value="{{ $line['domain_name'] ?? '' }}"
                                           placeholder="example.com (required)" maxlength="253">
                                    <div class="form-text small text-muted">The domain this service is provisioned against.</div>
                                    @if ($lineError('domain_name'))<div class="invalid-feedback d-block">{{ $lineError('domain_name') }}</div>@endif
                                </div>
                            </div>
                            <div class="line-info small text-muted mt-2 d-none"></div>
                            <div class="line-options mt-2 d-none"></div>
                        </div>
                    </div>
                @endforeach
            </div>
            <button type="button" id="add-line-btn" class="btn btn-outline-primary w-100 border-2 add-line-btn mt-2">
                <i class="bi bi-plus-lg me-1"></i> Add another product
            </button>
        </div>

        {{-- Billing controls + order summary --}}
        <div class="row g-3 mt-1">
            <div class="col-lg-6">
                <div class="border rounded p-3 h-100">
                    <h6 class="mb-3"><i class="bi bi-credit-card me-1"></i>Billing</h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <div class="btn-group w-100" role="group" aria-label="Order status">
                                <input type="radio" class="btn-check" name="status" id="status-pending" value="pending"
                                       @checked(old('status', 'pending') === 'pending')>
                                <label class="btn btn-outline-secondary flex-grow-1" for="status-pending">Pending</label>
                                <input type="radio" class="btn-check" name="status" id="status-active" value="active"
                                       @checked(old('status') === 'active')>
                                <label class="btn btn-outline-secondary flex-grow-1" for="status-active">Active</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <x-adminlte-select name="payment_method" label="Payment method">
                                <option value="">— None —</option>
                                @foreach ($paymentMethods as $value => $label)
                                    <option value="{{ $value }}" @selected(old('payment_method') === $value)>{{ $label }}</option>
                                @endforeach
                            </x-adminlte-select>
                        </div>
                        <div class="col-12">
                            <label class="form-label d-block mb-1">Post-order actions</label>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="send_confirmation" value="1" id="send-confirmation"
                                       @checked(old('send_confirmation', true))>
                                <label class="form-check-label" for="send-confirmation">Order Confirmation</label>
                                <div class="form-text small text-muted mt-0">Email the customer an order confirmation.</div>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="generate_invoice" value="1" id="generate-invoice"
                                       @checked(old('generate_invoice', $autoGenerateInvoice))>
                                <label class="form-check-label" for="generate-invoice">Generate Invoice</label>
                                <div class="form-text small text-muted mt-0">Create a draft invoice for the order.</div>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="send_invoice" value="1" id="send-invoice"
                                       @checked(old('send_invoice', true))>
                                <label class="form-check-label" for="send-invoice">Send Email</label>
                                <div class="form-text small text-muted mt-0">Email the generated invoice to the customer.</div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-text text-muted small">
                                <i class="bi bi-info-circle me-1"></i>
                                Creating as <strong>Active</strong> runs activation immediately (provisions the service + seeds the billing schedule from each item's cycle). For <strong>Pending</strong> orders the schedule starts on activation.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="border rounded p-3 bg-body-tertiary h-100">
                    <h6 class="mb-3"><i class="bi bi-receipt me-1"></i>Order Summary</h6>
                    <div class="d-flex justify-content-between mb-1"><span class="text-muted small">Items</span><span id="summary-items">0</span></div>
                    <div class="d-flex justify-content-between mb-1"><span class="text-muted small">Subtotal</span><span id="gst-subtotal">₹0.00</span></div>
                    <div class="d-flex justify-content-between mb-1"><span class="text-muted small" id="gst-label">GST (estimate)</span><span id="gst-amount">₹0.00</span></div>
                    <hr class="my-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-bold">Order total</span>
                        <span class="fs-4 fw-bold text-primary" id="gst-total">₹0.00</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-3">
            <x-adminlte-textarea name="notes" label="Notes" rows="2" maxlength="2000"
                                 placeholder="Internal notes (optional)">{{ old('notes') }}</x-adminlte-textarea>
        </div>
    </x-adminlte.partials.form-card>

    {{-- Inline customer creation modal --}}
    <div class="modal fade" id="new-customer-modal" tabindex="-1" aria-labelledby="new-customer-modal-title" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="new-customer-modal-title">New Customer</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="customer-quick-errors" class="alert alert-danger d-none mb-2">
                        <ul class="mb-0"></ul>
                    </div>
                    <form id="customer-quick-form">
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label small" for="qc-first_name">First name</label>
                                <input type="text" class="form-control form-control-sm" id="qc-first_name" name="first_name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small" for="qc-last_name">Last name</label>
                                <input type="text" class="form-control form-control-sm" id="qc-last_name" name="last_name" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label small" for="qc-email">Email</label>
                                <input type="email" class="form-control form-control-sm" id="qc-email" name="email" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label small" for="qc-password">Password</label>
                                <input type="password" class="form-control form-control-sm" id="qc-password" name="password"
                                       minlength="8" placeholder="Min 8 chars, with upper, lower & number" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small" for="qc-phone">Phone</label>
                                <input type="text" class="form-control form-control-sm" id="qc-phone" name="phone">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small" for="qc-company">Company</label>
                                <input type="text" class="form-control form-control-sm" id="qc-company" name="company">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small" for="qc-status">Status</label>
                                <select class="form-select form-select-sm" id="qc-status" name="status">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                    <option value="suspended">Suspended</option>
                                </select>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary btn-sm" id="customer-quick-save">
                        <i class="bi bi-check-lg me-1"></i> Create Customer
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- JS template for an order line; __INDEX__ is replaced on clone. --}}
    <template id="order-line-template">
        <div class="order-line border rounded mb-2 overflow-hidden" data-line-index="__INDEX__" data-submitted-options="">
            <div class="d-flex align-items-center justify-content-between gap-2 px-2 py-1 bg-body-secondary border-bottom">
                <div class="small fw-semibold text-muted">
                    <i class="bi bi-box-seam me-1" aria-hidden="true"></i><span class="line-service-label">Service</span>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="small fw-bold line-total text-nowrap">—</span>
                    <span class="small text-muted line-adjustment text-nowrap"></span>
                    <button type="button" class="btn btn-sm btn-outline-danger line-remove" title="Remove line" aria-label="Remove line">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
            <div class="p-2">
                <div class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label small text-muted mb-1" for="line-product-__INDEX__">Product</label>
                        <select class="form-select form-select-sm line-product" id="line-product-__INDEX__"
                                name="lines[__INDEX__][product_id]" required>
                            <option value="">— Select product —</option>
                            @foreach ($products as $product)
                                <option value="{{ $product->id }}">{{ $product->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small text-muted mb-1" for="line-cycle-__INDEX__">Billing cycle</label>
                        <select class="form-select form-select-sm line-cycle" id="line-cycle-__INDEX__"
                                name="lines[__INDEX__][billing_cycle]" required></select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small text-muted mb-1" for="line-qty-__INDEX__">Quantity</label>
                        <input type="number" class="form-control form-control-sm line-qty" id="line-qty-__INDEX__"
                               name="lines[__INDEX__][quantity]" min="1" max="99" value="1" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small text-muted mb-1" for="line-price-__INDEX__">Unit price (₹)</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">₹</span>
                            <input type="number" step="0.01" min="0" class="form-control form-control-sm line-price"
                                   id="line-price-__INDEX__" name="lines[__INDEX__][unit_price]" required>
                        </div>
                    </div>
                </div>
                <div class="form-text small d-none line-qty-hint"><i class="bi bi-info-circle me-1"></i>Sold as a single unit.</div>
                {{-- Outside the aligned field row so it never breaks the
                     controls' baseline. --}}
                <div class="form-check mt-2 mb-0">
                    <input class="form-check-input line-override" type="checkbox"
                           name="lines[__INDEX__][override]" value="1" id="line-override-__INDEX__">
                    <label class="form-check-label small text-muted" for="line-override-__INDEX__">Custom amount</label>
                </div>
                <div class="row g-2 line-domain-wrap d-none mt-1">
                    <div class="col-md-6">
                        <label class="form-label small text-muted mb-1" for="line-domain-__INDEX__">Domain name</label>
                        <input type="text" class="form-control form-control-sm line-domain" id="line-domain-__INDEX__"
                               name="lines[__INDEX__][domain_name]" placeholder="example.com (required)" maxlength="253">
                        <div class="form-text small text-muted">The domain this service is provisioned against.</div>
                    </div>
                </div>
                <div class="line-info small text-muted mt-2 d-none"></div>
                <div class="line-options mt-2 d-none"></div>
            </div>
        </div>
    </template>

    @push('js')
        <style>
            .option-cap-limited { opacity: .45; }
            .add-line-btn { border-style: dashed !important; }
            #order-lines:not(.multi-line) .line-remove { display: none; }
        </style>
        <script>
            window.orderProducts = @json($productMap);
            window.billingCycleLabels = @json($billingCycles);
            window.gstSettings = @json($gstSettings);
            window.customerQuickStoreUrl = @json(route('admin.customers.quick-store'));

            (function () {
                const fmt = (n) => '₹' + Number(n).toLocaleString('en-IN', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2,
                });

                // ─────────────────────────── Line management ───────────────────────────

                const linesContainer = document.getElementById('order-lines');
                const template = document.getElementById('order-line-template');
                const addBtn = document.getElementById('add-line-btn');
                const customerSelect = document.getElementById('customer_id');

                function currentLines() {
                    return Array.from(linesContainer.querySelectorAll('.order-line'));
                }

                function nextIndex() {
                    return currentLines().reduce((max, el) => Math.max(max, Number(el.dataset.lineIndex) || 0), 0) + 1;
                }

                function productData(el) {
                    const select = el.querySelector('.line-product');
                    return select?.value ? (window.orderProducts?.[select.value] ?? null) : null;
                }

                function catalogPrice(el) {
                    const data = productData(el);
                    const cycle = el.querySelector('.line-cycle');
                    if (!data) return null;
                    if (cycle?.value && data.prices && data.prices[cycle.value] !== undefined) return data.prices[cycle.value];
                    if (data.prices && data.prices[data.cycle] !== undefined) return data.prices[data.cycle];
                    return data.price ?? null;
                }

                function isOverriding(el) {
                    return el.querySelector('.line-override')?.checked ?? false;
                }

                // Restrict the cycle dropdown to the selected product's ladder.
                function refreshCycleOptions(el) {
                    const cycle = el.querySelector('.line-cycle');
                    if (!cycle) return;
                    const data = productData(el);
                    const labels = window.billingCycleLabels ?? {};
                    const offered = data?.availableCycles?.length ? data.availableCycles : Object.keys(labels);
                    const current = cycle.value;
                    cycle.innerHTML = '';
                    offered.forEach((value) => {
                        const option = document.createElement('option');
                        option.value = value;
                        option.textContent = labels[value] ?? value;
                        cycle.appendChild(option);
                    });
                    if (offered.includes(current)) {
                        cycle.value = current;
                    } else if (data?.cycle && offered.includes(data.cycle)) {
                        cycle.value = data.cycle;
                    } else if (offered.length) {
                        cycle.value = offered[0];
                    }
                    if (!isOverriding(el)) refreshPrice(el);
                }

                // Single-unit products are sold one unit at a time: lock the
                // quantity input at 1. readOnly (not disabled) so the value 1
                // still submits.
                function refreshQuantity(el) {
                    const data = productData(el);
                    const locked = data?.singleUnit ?? false;
                    const qty = el.querySelector('.line-qty');
                    const hint = el.querySelector('.line-qty-hint');
                    if (qty) {
                        qty.readOnly = locked;
                        qty.value = locked ? '1' : qty.value;
                    }
                    if (hint) hint.classList.toggle('d-none', !locked);
                }

                // Show the line's domain input when its product requires one
                // (each purchased service is provisioned against its own domain).
                function refreshDomain(el) {
                    const data = productData(el);
                    const wrap = el.querySelector('.line-domain-wrap');
                    if (wrap) wrap.classList.toggle('d-none', !data?.requiresDomain);
                }

                function promoBadge(data) {
                    if (!data) return '';
                    const active = Object.values(data.promo || {}).some((p) => p?.active && p.price !== null && p.price !== undefined);
                    return active ? '<span class="badge bg-warning text-dark">Promo pricing active</span> ' : '';
                }

                function infoPanel(data) {
                    if (!data) return '';
                    const badges = [promoBadge(data)];

                    const paymentLabels = { free: 'Free', one_time: 'One time', recurring: 'Recurring' };
                    badges.push('<span class="badge bg-secondary">' + (paymentLabels[data.paymentType] ?? data.paymentType) + '</span>');

                    const gst = data.gst ?? {};
                    if (gst.enabled) {
                        const rate = (gst.cgst ?? 0) + (gst.sgst ?? 0) || (gst.igst ?? 0);
                        badges.push('<span class="badge bg-info">GST ' + rate + '%</span>');
                    }

                    const at = data.autoTerminate ?? {};
                    if (Number(at.value) > 0) {
                        badges.push('<span class="badge bg-light text-dark border">Auto-terminates after ' + at.value + ' ' + at.unit + '</span>');
                    }

                    if (data.singleUnit) badges.push('<span class="badge bg-light text-dark border">Single unit</span>');

                    return badges.join(' ');
                }

                // ───────────────────────── Option controls ─────────────────────────

                const continuousTypes = ['slider', 'number', 'quantity'];

                function optionName(el, linkId, suffix) {
                    return 'lines[' + el.dataset.lineIndex + '][options][' + linkId + ']' + (suffix || '');
                }

                function renderOptions(el) {
                    const data = productData(el);
                    const container = el.querySelector('.line-options');
                    if (!container) return;
                    container.innerHTML = '';
                    container.classList.add('d-none');

                    const links = data?.optionLinks ?? [];
                    if (!links.length) return;

                    container.classList.remove('d-none');
                    let html = '<div class="border-top pt-2"><div class="small fw-bold mb-1">Configuration Options</div><div class="row g-2">';

                    links.forEach((link) => {
                        const type = link.type;
                        const cid = optionName(el, link.id);

                        // Informational (non-editable) links are display-only:
                        // their values come from the catalog and cannot be
                        // changed at order time.
                        if (!link.customerEditable) {
                            const labels = (link.values || []).map((v) => v.label).join(', ');
                            html += '<div class="col-md-4"><label class="form-label small text-muted mb-1">' + link.name + '</label>' +
                                '<div class="text-muted small">' + (labels || '—') + '</div></div>';
                            return;
                        }

                        let control = '';
                        const cycle = currentCycle(el);

                        if (type === 'dropdown') {
                            control = '<select class="form-select form-select-sm" name="' + cid + '" required>' +
                                (link.values || []).map((v) =>
                                    '<option value="' + v.label + '"' + (v.default ? ' selected' : '') + '>' + v.label +
                                    (modifierFor(v.modifiers, el, cycle) !== 0 ? modifierLabel(modifierFor(v.modifiers, el, cycle), cycle) : '') +
                                    '</option>'
                                ).join('') + '</select>';
                        } else if (type === 'radio') {
                            control = (link.values || []).map((v, i) => {
                                const vid = cid.replace(/[\[\]]/g, '-') + '-' + i;
                                return '<div class="form-check">' +
                                '<input class="form-check-input" type="radio" name="' + cid + '" id="' + vid + '" value="' + v.label + '"' + (v.default ? ' checked' : '') + (i === 0 ? ' required' : '') + '>' +
                                '<label class="form-check-label small" for="' + vid + '">' + v.label +
                                (modifierFor(v.modifiers, el, cycle) !== 0 ? modifierLabel(modifierFor(v.modifiers, el, cycle), cycle) : '') +
                                '</label></div>';
                            }).join('');
                        } else if (type === 'checkbox') {
                            control = '<div class="option-checkbox-group" data-checkbox-group="' + link.id + '" data-max="' + (link.max ?? 1) + '">' +
                                (link.values || []).map((v, i) => {
                                    const vid = cid.replace(/[\[\]]/g, '-') + '-' + i;
                                    return '<div class="form-check" data-checkbox-option>' +
                                    '<input class="form-check-input" type="checkbox" name="' + cid + '[]" id="' + vid + '" value="' + v.label + '"' + (v.default ? ' checked' : '') + '>' +
                                    '<label class="form-check-label small" for="' + vid + '">' + v.label +
                                    (modifierFor(v.modifiers, el, cycle) !== 0 ? modifierLabel(modifierFor(v.modifiers, el, cycle), cycle) : '') +
                                    '</label></div>';
                                }).join('') + '</div>';
                        } else if (type === 'quantity') {
                            // A count: always whole units — step 1, never the
                            // group's input_step (matches the integer rule).
                            control = '<input type="number" class="form-control form-control-sm" name="' + cid + '" min="' + (link.min ?? 0) + '"' +
                                (link.max != null ? ' max="' + link.max + '"' : '') + ' step="1" value="' + (link.min ?? 0) + '">' +
                                '<div class="form-text small text-muted">₹' + (modifierFor(link.unit, el, cycle)).toFixed(2) + ' per unit / ' + (cycleSuffix[cycle] || 'mo') + '</div>';
                        } else if (type === 'number') {
                            control = '<input type="number" class="form-control form-control-sm" name="' + cid + '" min="' + (link.min ?? 0) + '"' +
                                (link.max != null ? ' max="' + link.max + '"' : '') + ' step="' + (link.step ?? 1) + '" value="' + (link.min ?? 0) + '">' +
                                '<div class="form-text small text-muted">₹' + (modifierFor(link.unit, el, cycle)).toFixed(2) + ' per unit / ' + (cycleSuffix[cycle] || 'mo') + '</div>';
                        } else if (type === 'slider') {
                            control = '<div class="d-flex align-items-center gap-2">' +
                                '<input type="range" class="form-range flex-grow-1" name="' + cid + '" min="' + (link.min ?? 0) + '" max="' + (link.max ?? 100) + '" step="' + (link.step ?? 1) + '" value="' + (link.min ?? 0) + '">' +
                                '<span class="small text-muted fw-semibold slider-val" data-for="' + cid.replace(/[\[\]]/g, '-') + '"></span></div>' +
                                '<div class="form-text small text-muted">₹' + (modifierFor(link.unit, el, cycle)).toFixed(2) + ' per unit / ' + (cycleSuffix[cycle] || 'mo') + '</div>';
                        } else {
                            control = '<input type="text" class="form-control form-control-sm" name="' + cid + '" maxlength="255" placeholder="' + (link.placeholder ?? '') + '">';
                        }

                        html += '<div class="col-md-4"><label class="form-label small text-muted mb-1">' + link.name + '</label>' + control + '</div>';
                    });

                    html += '</div></div>';
                    container.innerHTML = html;

                    // Live value labels for sliders.
                    container.querySelectorAll('input[type="range"]').forEach((range) => {
                        const label = range.parentElement.querySelector('.slider-val');
                        if (label) {
                            label.textContent = range.value;
                            range.addEventListener('input', () => { label.textContent = range.value; });
                        }
                    });

                    restoreSubmittedOptions(el, container);
                    syncCheckboxCaps(container);
                }

                // Restore the options submitted for this line (after a
                // validation error the form re-renders with old() values), so
                // pre-checked selections survive the round-trip. Runs before
                // the cap sync so over-cap restores grey out immediately.
                function restoreSubmittedOptions(el, container) {
                    const raw = el.dataset.submittedOptions;
                    if (!raw) return;
                    let submitted;
                    try { submitted = JSON.parse(raw); } catch (e) { return; }

                    Object.keys(submitted).forEach(function (linkId) {
                        const selection = submitted[linkId];
                        const cid = optionName(el, linkId);

                        const checkboxes = container.querySelectorAll('input[name="' + cid + '[]"]');
                        if (checkboxes.length) {
                            const selected = Array.isArray(selection) ? selection : [selection];
                            checkboxes.forEach(function (cb) { cb.checked = selected.indexOf(cb.value) !== -1; });
                            return;
                        }

                        const radios = container.querySelectorAll('input[name="' + cid + '"][type="radio"]');
                        if (radios.length) {
                            radios.forEach(function (radio) { radio.checked = radio.value === selection; });
                            return;
                        }

                        const single = container.querySelector('select[name="' + cid + '"], input[name="' + cid + '"]');
                        if (single) single.value = selection;
                    });
                }

                // Checkbox selection caps (input_max, else all values): grey
                // out + disable unchecked options once the cap is reached.
                function syncCheckboxCaps(scope) {
                    (scope || document).querySelectorAll('[data-checkbox-group]').forEach(function (group) {
                        const max = parseInt(group.dataset.max, 10) || 1;
                        const inputs = Array.from(group.querySelectorAll('input[type="checkbox"]'));
                        const atCap = group.querySelectorAll('input[type="checkbox"]:checked').length >= max;

                        inputs.forEach(function (input) {
                            const block = atCap && !input.checked;
                            input.disabled = block;
                            const option = input.closest('[data-checkbox-option]');
                            if (option) option.classList.toggle('option-cap-limited', block);
                        });
                    });
                }

                // ───────────────────────── Totals + GST estimate ─────────────────────────

                function customerState() {
                    const selected = customerSelect?.options?.[customerSelect.selectedIndex];
                    return selected ? (selected.dataset.stateCode || '') : '';
                }

                // Mirrors GstTaxService::calculateItemTax for the preview — the
                // draft invoice remains the source of truth.
                function gstForLine(lineTotal, data) {
                    const g = window.gstSettings || {};
                    if (!g.enabled) return { enabled: false, amount: 0 };

                    const intra = !!(g.state_code && customerState() &&
                        g.state_code.toUpperCase() === customerState().toUpperCase());

                    const pd = data?.gst ?? {};
                    const mode = g.tax_mode || 'global';

                    let applyGst = false;
                    let usePerProduct = false;
                    if (mode === 'per_product') {
                        applyGst = !!pd.enabled;
                        if (applyGst) usePerProduct = pd.cgst != null || pd.sgst != null || pd.igst != null;
                    } else if (mode === 'mixed') {
                        applyGst = true;
                        usePerProduct = !!pd.enabled && (pd.cgst != null || pd.sgst != null || pd.igst != null);
                    } else {
                        applyGst = true;
                    }
                    if (!applyGst) return { enabled: false, amount: 0 };

                    if (usePerProduct && (pd.type === 'exempt' || pd.type === 'reverse_charge')) {
                        return { enabled: true, amount: 0 };
                    }

                    let rate;
                    if (usePerProduct) {
                        rate = intra ? ((pd.cgst ?? 0) + (pd.sgst ?? 0)) : (pd.igst ?? 0);
                    } else {
                        rate = intra ? ((g.cgst_rate ?? 0) + (g.sgst_rate ?? 0)) : (g.igst_rate ?? 0);
                    }

                    return { enabled: true, amount: Math.round(lineTotal * rate) / 100 };
                }

                // ─────────────────── Option price adjustments ───────────────────

                const cycleSuffix = {
                    free: 'free', one_time: 'once', monthly: 'mo', quarterly: 'qtr',
                    semi_annual: '6mo', annual: 'yr', biennial: '2yr', triennial: '3yr',
                };

                function currentCycle(el) {
                    return el.querySelector('.line-cycle')?.value || 'monthly';
                }

                // Exact cycle, then monthly, then the single configured cycle —
                // gated on the cycles the product actually offers (mirrors the
                // server-side formatPrice fallbacks).
                function modifierFor(map, el, cycle) {
                    if (!map) return 0;
                    if (map[cycle] !== undefined) return map[cycle];
                    const offered = productData(el)?.availableCycles ?? [];
                    if (offered.indexOf('monthly') !== -1 && map.monthly !== undefined) return map.monthly;
                    const keys = Object.keys(map);
                    if (keys.length === 1 && offered.indexOf(keys[0]) !== -1) return map[keys[0]];
                    return 0;
                }

                function modifierLabel(modifier, cycle) {
                    const sign = modifier < 0 ? '-' : '+';
                    const suffix = cycle === 'one_time' ? ' once' : '/' + (cycleSuffix[cycle] || 'mo');
                    return ' (' + sign + '₹' + Math.abs(modifier).toFixed(2) + suffix + ')';
                }

                // Sum the modifiers of the line's currently-selected options for
                // the line's billing cycle (continuous links multiply the value
                // by the per-unit price).
                function selectionPrice(el) {
                    const data = productData(el);
                    const container = el.querySelector('.line-options');
                    if (!container || !data) return 0;

                    const cycle = currentCycle(el);
                    let sum = 0;

                    (data.optionLinks || []).forEach((link) => {
                        if (!link.customerEditable) return;

                        const cid = optionName(el, link.id);

                        if (continuousTypes.includes(link.type)) {
                            const input = container.querySelector('input[name="' + cid + '"]');
                            const value = input ? (parseFloat(input.value) || 0) : 0;
                            sum += modifierFor(link.unit, el, cycle) * value;
                            return;
                        }

                        if (link.type === 'checkbox') {
                            container.querySelectorAll('input[name="' + cid + '[]"]:checked').forEach((cb) => {
                                const value = (link.values || []).find((v) => v.label === cb.value);
                                sum += modifierFor(value?.modifiers, el, cycle);
                            });
                            return;
                        }

                        const control = container.querySelector('select[name="' + cid + '"], input[name="' + cid + '"]:checked');
                        if (control) {
                            const value = (link.values || []).find((v) => v.label === control.value);
                            sum += modifierFor(value?.modifiers, el, cycle);
                        }
                    });

                    return sum;
                }

                // The chargeable per-unit price: the entered price PLUS the
                // selected options' adjustments for the line's cycle.
                function effectiveUnitPrice(el) {
                    const price = parseFloat(el.querySelector('.line-price')?.value) || 0;
                    return price + selectionPrice(el);
                }

                function refreshLineTotal(el) {
                    const qty = parseFloat(el.querySelector('.line-qty')?.value) || 0;
                    const totalEl = el.querySelector('.line-total');
                    const adjustmentEl = el.querySelector('.line-adjustment');
                    const adjustment = selectionPrice(el);

                    if (adjustmentEl) {
                        adjustmentEl.textContent = adjustment !== 0
                            ? 'Options ' + (adjustment < 0 ? '-' : '+') + '₹' + Math.abs(adjustment).toFixed(2) + ' / ' + (cycleSuffix[currentCycle(el)] || 'mo')
                            : '';
                    }

                    if (totalEl) totalEl.textContent = fmt(effectiveUnitPrice(el) * qty);
                }

                function refreshTotals() {
                    let subtotal = 0;
                    let gst = 0;
                    currentLines().forEach((el) => {
                        const data = productData(el);
                        const qty = parseFloat(el.querySelector('.line-qty')?.value) || 0;
                        const lineTotal = effectiveUnitPrice(el) * qty;
                        subtotal += lineTotal;
                        gst += gstForLine(lineTotal, data).amount;
                    });
                    const subtotalEl = document.getElementById('gst-subtotal');
                    const gstEl = document.getElementById('gst-amount');
                    const totalEl = document.getElementById('gst-total');
                    const itemsEl = document.getElementById('summary-items');
                    if (subtotalEl) subtotalEl.textContent = fmt(subtotal);
                    if (gstEl) gstEl.textContent = fmt(gst);
                    if (totalEl) totalEl.textContent = fmt(subtotal + gst);
                    if (itemsEl) itemsEl.textContent = String(currentLines().length);
                    refreshGstLabel();
                }

                // The estimate label shows the effective GST rate when it is
                // uniform (global mode): CGST+SGST intra-state, IGST otherwise.
                // Per-product modes vary per line, so no single % is shown.
                function refreshGstLabel() {
                    const label = document.getElementById('gst-label');
                    if (!label) return;
                    const g = window.gstSettings || {};
                    if (!g.enabled || g.tax_mode !== 'global') {
                        label.textContent = 'GST (estimate)';
                        return;
                    }
                    const intra = !!(g.state_code && customerState() &&
                        g.state_code.toUpperCase() === customerState().toUpperCase());
                    const rate = intra
                        ? (Number(g.cgst_rate) + Number(g.sgst_rate))
                        : Number(g.igst_rate);
                    label.textContent = 'GST (estimate @ ' + rate + '%)';
                }

                // "Service N" labels + the remove button (hidden while a single
                // line remains, since an order needs at least one product).
                function refreshLineLabels() {
                    currentLines().forEach(function (el) {
                        const label = el.querySelector('.line-service-label');
                        if (label) label.textContent = 'Service ' + (Number(el.dataset.lineIndex) + 1);
                    });
                    linesContainer.classList.toggle('multi-line', currentLines().length > 1);
                }

                function refreshPrice(el) {
                    const price = el.querySelector('.line-price');
                    const p = catalogPrice(el);
                    if (price && p !== null) price.value = p.toFixed(2);
                    refreshLineTotal(el);
                }

                function refreshLine(el, init) {
                    refreshCycleOptions(el);
                    refreshQuantity(el);
                    refreshDomain(el);
                    renderOptions(el);
                    if (!init && !isOverriding(el)) refreshPrice(el);
                    refreshLineTotal(el);
                    refreshTotals();
                }

                function bindLine(el) {
                    el.querySelector('.line-product')?.addEventListener('change', function () {
                        if (isOverriding(el)) {
                            // Keep the manual amount, but rebuild cycle/options.
                            refreshCycleOptions(el);
                            refreshQuantity(el);
                            refreshDomain(el);
                            renderOptions(el);
                            refreshLineTotal(el);
                            refreshTotals();
                            return;
                        }
                        refreshLine(el, false);
                    });
                    el.querySelector('.line-cycle')?.addEventListener('change', function () {
                        // Re-render the option controls so their price labels
                        // follow the new cycle, then recompute the totals.
                        renderOptions(el);
                        if (!isOverriding(el)) refreshPrice(el);
                        refreshLineTotal(el);
                        refreshTotals();
                    });
                    el.querySelector('.line-qty')?.addEventListener('input', function () {
                        refreshLineTotal(el);
                        refreshTotals();
                    });
                    el.querySelector('.line-price')?.addEventListener('input', function () {
                        refreshLineTotal(el);
                        refreshTotals();
                    });
                    el.querySelector('.line-override')?.addEventListener('change', function () {
                        const price = el.querySelector('.line-price');
                        if (price) price.readOnly = this.checked;
                        if (this.checked) {
                            price?.focus();
                        } else {
                            refreshPrice(el);
                        }
                        refreshLineTotal(el);
                        refreshTotals();
                    });
                    // Option controls are re-rendered per product/cycle, so
                    // listen on the container (delegation survives re-renders).
                    const optionsBox = el.querySelector('.line-options');
                    if (optionsBox) {
                        optionsBox.addEventListener('change', function () {
                            syncCheckboxCaps(optionsBox);
                            refreshLineTotal(el);
                            refreshTotals();
                        });
                        optionsBox.addEventListener('input', function () {
                            refreshLineTotal(el);
                            refreshTotals();
                        });
                    }
                    el.querySelector('.line-remove')?.addEventListener('click', function () {
                        if (currentLines().length > 1) {
                            el.remove();
                            refreshLineLabels();
                            refreshTotals();
                        }
                    });
                }

                // ─────────────────────────── Initial state ───────────────────────────

                addBtn?.addEventListener('click', function () {
                    const index = nextIndex();
                    const html = template.innerHTML.replace(/__INDEX__/g, String(index));
                    const wrapper = document.createElement('div');
                    wrapper.innerHTML = html.trim();
                    const line = wrapper.firstElementChild;
                    linesContainer.appendChild(line);
                    bindLine(line);
                    refreshLine(line, true);
                    refreshLineLabels();
                    line.querySelector('.line-product')?.focus();
                });

                customerSelect?.addEventListener('change', refreshTotals);

                // Bind + initialize the server-rendered lines (old() values
                // preserved after a validation error — prices/options are not
                // clobbered on load; the product rules apply).
                currentLines().forEach((el) => {
                    bindLine(el);
                    const price = el.querySelector('.line-price');
                    if (price) price.readOnly = isOverriding(el);
                    refreshLine(el, true);
                });
                refreshLineLabels();

                // ───────────────────────── Inline customer creation ─────────────────────────

                const quickForm = document.getElementById('new-customer-modal');
                const quickSave = document.getElementById('customer-quick-save');
                const quickErrors = document.getElementById('customer-quick-errors');

                function showQuickErrors(errors) {
                    if (!quickErrors) return;
                    quickErrors.classList.remove('d-none');
                    const list = quickErrors.querySelector('ul');
                    list.innerHTML = '';
                    Object.values(errors).flat().forEach((msg) => {
                        const li = document.createElement('li');
                        li.textContent = msg;
                        list.appendChild(li);
                    });
                }

                quickSave?.addEventListener('click', function () {
                    const form = quickForm.querySelector('form');
                    if (!form) return;
                    const data = new FormData(form);
                    quickSave.disabled = true;

                    fetch(window.customerQuickStoreUrl, { method: 'POST', body: data, headers: { 'Accept': 'application/json' } })
                        .then((res) => res.json().then((body) => ({ ok: res.ok, status: res.status, body })))
                        .then(({ ok, body }) => {
                            if (!ok) {
                                showQuickErrors(body?.errors ?? { error: ['Could not create the customer.'] });
                                return;
                            }
                            const option = document.createElement('option');
                            option.value = body.id;
                            option.textContent = body.label;
                            customerSelect.appendChild(option);
                            customerSelect.value = String(body.id);
                            // Reset the modal + close it. The `bootstrap`
                            // global is not exposed (AdminLTE loads Bootstrap
                            // as an ES module), so trigger the dismiss button —
                            // Bootstrap's delegated handler closes the modal.
                            form.reset();
                            quickErrors.classList.add('d-none');
                            quickForm.querySelector('.btn-close')?.click();
                            refreshTotals();
                        })
                        .catch(() => {
                            showQuickErrors({ error: ['Network error — please try again.'] });
                        })
                        .finally(() => {
                            quickSave.disabled = false;
                        });
                });

                // Clear stale errors when the modal reopens.
                quickForm?.addEventListener('show.bs.modal', function () {
                    quickErrors?.classList.add('d-none');
                });

                // The modal form must never submit natively (Enter would
                // reload the page) — creation goes through the fetch call.
                quickForm?.querySelector('form')?.addEventListener('submit', function (e) {
                    e.preventDefault();
                });
            })();
        </script>
    @endpush
@stop
