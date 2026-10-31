@extends('adminlte::page')

@section('title', 'Edit ' . $orderItem->product_name)

@section('content_header')
    <x-ui.page-header title="Edit {{ $orderItem->product_name }}" subtitle="Order {{ $order->order_number }}" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Orders', 'url' => route('admin.orders.index')],
        ['label' => $order->order_number, 'url' => route('admin.orders.show', $order)],
        ['label' => 'Edit Add-on', 'active' => true],
    ]" />
@stop

@section('content')
    <x-adminlte.partials.flash-alert />

    <x-adminlte.partials.form-card
        icon="bi bi-pencil-square"
        title="Edit {{ $orderItem->product_name }}"
        :action="route('admin.orders.addons.update', [$order, $orderItem])"
        method="PUT"
        form-id="addon-edit-form"
        :show-footer="false"
    >
        {{-- Save / Cancel live in the card header (card-tools), matching the
             product edit page: Save submits the form by id, Cancel returns to
             the order page. --}}
        <x-slot name="tools">
            <a href="{{ route('admin.orders.show', $order) }}?tab=addons" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-x-lg me-1" aria-hidden="true"></i> Cancel
            </a>
            <button type="submit" form="addon-edit-form" class="btn btn-sm btn-primary">
                <i class="bi bi-check-lg me-1" aria-hidden="true"></i> Save Changes
            </button>
        </x-slot>

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="product_name" label="Add-on"
                                  value="{{ $orderItem->product_name }}" disabled />
            </div>
            <div class="col-md-6">
                <x-adminlte-select name="billing_cycle" label="Billing cycle">
                    @foreach ($billingCycleLabels as $value => $label)
                        <option value="{{ $value }}" @selected(old('billing_cycle', $orderItem->billing_cycle) === $value)>{{ $label }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
        </div>

        <div class="row">
            <div class="col-md-4">
                <x-adminlte-input name="unit_price" type="number" step="0.01" min="0" label="Unit price"
                                  value="{{ old('unit_price', $orderItem->unit_price) }}" />
            </div>
            <div class="col-md-4">
                <x-adminlte-input name="quantity" type="number" min="1" max="{{ \App\Models\Order::MAX_QUANTITY }}" label="Quantity"
                                  value="{{ old('quantity', $orderItem->quantity) }}" />
            </div>
            <div class="col-md-4">
                <x-adminlte-input name="next_billing_date" type="date" label="Next billing date"
                                  value="{{ old('next_billing_date', $orderItem->next_billing_date?->format('Y-m-d')) }}" />
            </div>
        </div>
    </x-adminlte.partials.form-card>
@stop