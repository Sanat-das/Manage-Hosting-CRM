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
    @include('client.store._alerts')

    @php
        $subtotal = 0;
        $continuousTypes = \App\Models\ProductOptionGroup::CONTINUOUS_TYPES;
    @endphp
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
                                    @if ($item['product']->isSingleUnit())
                                        <span class="badge bg-secondary" title="Sold as a single unit per order">1</span>
                                    @else
                                        <form method="POST" action="{{ route('client.store.cart.update') }}" class="d-flex align-items-center gap-1">
                                            @csrf
                                            <input type="hidden" name="index" value="{{ $idx }}">
                                            <input type="number" name="quantity" value="{{ $item['quantity'] }}" min="1" max="99" class="form-control form-control-sm" style="width:70px">
                                            <button type="submit" class="btn btn-sm btn-outline-primary" aria-label="Update quantity"><i class="bi bi-arrow-repeat"></i></button>
                                        </form>
                                    @endif
                                </td>
                                <td class="text-end">₹{{ number_format($item['total'], 2) }}</td>
                                <td class="text-end">
                                    <form method="POST" action="{{ route('client.store.cart.remove') }}" class="d-inline">
                                        @csrf
                                        <input type="hidden" name="index" value="{{ $idx }}">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Remove item"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            @php
                                $snapshotEntries = $item['config_options']['options'] ?? [];
                                $selectedEntries = array_values(array_filter($snapshotEntries, function ($entry) {
                                    $selected = $entry['selected'] ?? null;
                                    return is_array($selected) ? $selected !== [] : ($selected !== null && $selected !== '');
                                }));
                            @endphp
                            @if ($selectedEntries !== [])
                            @php
                                $item['product']->loadMissing('optionLinks.group', 'optionLinks.linkValues.pricing', 'optionLinks.unitPricing');
                                $optionModifiers = [];
                                foreach ($item['product']->optionLinks as $optionLink) {
                                    if (! $optionLink->customer_editable) {
                                        continue;
                                    }
                                    $selected = $item['options'][$optionLink->id] ?? null;
                                    if ($selected === null) {
                                        continue;
                                    }
                                    $total = 0.0;
                                    if (in_array($optionLink->group?->type, $continuousTypes, true)) {
                                        $price = $optionLink->unitPricing->firstWhere('billing_cycle', $item['cycle'])
                                            ?? $optionLink->unitPricing->firstWhere('billing_cycle', 'monthly')
                                            ?? $optionLink->unitPricing->first();
                                        if ($price !== null) {
                                            $total += (float) $selected * (float) $price->price_modifier;
                                        }
                                    } else {
                                        $selectedLabels = is_array($selected) ? $selected : [$selected];
                                        foreach ($selectedLabels as $label) {
                                            $optionValue = $optionLink->linkValues->firstWhere('label', $label);
                                            if ($optionValue === null) {
                                                continue;
                                            }
                                            $price = $optionValue->pricing->firstWhere('billing_cycle', $item['cycle'])
                                                ?? $optionValue->pricing->firstWhere('billing_cycle', 'monthly')
                                                ?? $optionValue->pricing->first();
                                            if ($price !== null) {
                                                $total += (float) $price->price_modifier;
                                            }
                                        }
                                    }
                                    if ($total != 0.0) {
                                        $optionModifiers[$optionLink->id] = $total;
                                    }
                                }
                            @endphp
                                <tr>
                                    <td colspan="7" class="pt-0 border-0">
                                        @include('client.partials._selected_options', [
                                            'entries' => $selectedEntries,
                                            'modifiersByLink' => $optionModifiers,
                                            'cycle' => $item['cycle'],
                                            'includeUnselected' => false,
                                        ])
                                    </td>
                                </tr>
                            @endif
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