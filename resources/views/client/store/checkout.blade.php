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
    @include('client.store._alerts')

    @php
        $total = 0;
        $continuousTypes = \App\Models\ProductOptionGroup::CONTINUOUS_TYPES;
    @endphp
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
                            <td><span class="badge text-bg-info">{{ ucfirst(str_replace('_', ' ', $item['cycle'])) }}</span></td>
                            <td>{{ $item['domain'] ?? '—' }}</td>
                            <td>{{ $item['quantity'] }}</td>
                            <td>₹{{ number_format($item['unit_price'], 2) }}</td>
                            <td class="text-end">₹{{ number_format($item['total'], 2) }}</td>
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
                                <td colspan="6" class="pt-0 border-0">
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
                        <th colspan="5" class="text-end">Grand Total</th>
                        <th class="text-end">₹{{ number_format($total, 2) }}</th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </x-adminlte-card>

    <x-adminlte-card icon="bi bi-geo-alt" title="Billing Information">
        @php $u = auth()->user(); @endphp
        <div class="row g-3">
            <div class="col-md-6">
                <div class="border rounded p-3 h-100 bg-light-subtle">
                    <div class="small text-muted text-uppercase fw-semibold mb-1">Bill to</div>
                    <div class="fw-semibold">{{ $u->full_name }}</div>
                    <div class="text-muted small">{{ $u->email }}</div>
                    @if ($u->phone)<div class="text-muted small"><i class="bi bi-telephone me-1"></i>{{ $u->phone }}</div>@endif
                    @if ($u->company)<div class="text-muted small"><i class="bi bi-building me-1"></i>{{ $u->company }}</div>@endif
                </div>
            </div>
            <div class="col-md-6">
                <div class="border rounded p-3 h-100">
                    <div class="small text-muted text-uppercase fw-semibold mb-2"><i class="bi bi-geo-alt me-1"></i>Billing Address</div>
                    @if ($u->formatted_address)
                        <div class="small">
                            @if ($u->address_line1)<div>{{ $u->address_line1 }}</div>@endif
                            @if ($u->address_line2)<div class="text-muted">{{ $u->address_line2 }}</div>@endif
                            <div>
                                @if ($u->city){{ $u->city }}@endif
                                @if ($u->city && $u->state), @endif{{ $u->state ?? '' }}
                                @if ($u->postcode) — {{ $u->postcode }}@endif
                            </div>
                            @if ($u->country)<div class="text-muted">{{ $u->country }}</div>@endif
                        </div>
                        <a href="{{ route('client.profile') }}" class="btn btn-sm btn-outline-primary mt-2"><i class="bi bi-pencil me-1"></i>Edit address</a>
                    @else
                        <div class="text-muted small mb-2">No billing address on file. Add it so invoices are correctly addressed and GST (CGST/SGST vs IGST) is calculated.</div>
                        <a href="{{ route('client.profile') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Add billing address</a>
                    @endif
                </div>
            </div>
        </div>
        <div class="text-muted small mt-2"><i class="bi bi-info-circle me-1"></i>Address is shown on your invoices. Update it in <a href="{{ route('client.profile') }}">My Profile</a> before placing the order if needed.</div>
    </x-adminlte-card>

    <div class="text-end mt-3">
        <a href="{{ route('client.store.cart') }}" class="btn btn-outline-secondary">Back to Cart</a>
        <form method="POST" action="{{ route('client.store.checkout.post') }}" class="d-inline">
            @csrf
            <button type="submit" class="btn btn-primary btn-lg ms-2"><i class="bi bi-check-circle me-1"></i> Place Order</button>
        </form>
    </div>
@stop
