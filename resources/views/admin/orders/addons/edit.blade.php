@extends('adminlte::page')

@section('title', 'Edit Add-on')

@section('content_header')
    <x-ui.page-header title="Edit Add-on" subtitle="{{ $order->order_number }}" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Orders', 'url' => route('admin.orders.index')],
        ['label' => $order->order_number, 'url' => route('admin.orders.show', $order)],
        ['label' => 'Edit Add-on', 'active' => true],
    ]" />
@stop

@section('content')
    <x-adminlte.partials.flash-alert />

    <x-adminlte-card icon="bi bi-pencil" title="Edit {{ $orderItem->product_name }}">
        <form method="POST" action="{{ route('admin.orders.addons.update', [$order, $orderItem]) }}">
            @csrf
            @method('PUT')

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="addon-name">Add-on</label>
                    <input type="text" class="form-control" id="addon-name" value="{{ $orderItem->product_name }}" disabled>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="addon-cycle">Billing cycle</label>
                    <select name="billing_cycle" id="addon-cycle" class="form-select">
                        @foreach ($billingCycleLabels as $value => $label)
                            <option value="{{ $value }}" @selected($orderItem->billing_cycle === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="addon-price">Unit price</label>
                    <input type="number" step="0.01" min="0" name="unit_price" id="addon-price"
                           class="form-control" value="{{ $orderItem->unit_price }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="addon-qty">Quantity</label>
                    <input type="number" min="1" max="{{ \App\Models\Order::MAX_QUANTITY }}" name="quantity" id="addon-qty"
                           class="form-control" value="{{ $orderItem->quantity }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="addon-date">Next billing date</label>
                    <input type="date" name="next_billing_date" id="addon-date" class="form-control"
                           value="{{ $orderItem->next_billing_date?->format('Y-m-d') }}">
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i>Save changes
                </button>
                <a href="{{ route('admin.orders.show', $order) }}?tab=addons" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </x-adminlte-card>
@stop