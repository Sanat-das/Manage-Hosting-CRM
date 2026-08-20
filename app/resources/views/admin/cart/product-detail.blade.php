@extends('adminlte::page')
@section('title', $product->name)
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">{{ $product->name }}</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item"><a href="{{ route('admin.cart.index') }}">Cart</a></li><li class="breadcrumb-item active">{{ $product->name }}</li></ol></div></div>
@stop
@section('content')
    <div class="row">
        <div class="col-md-8">
            <x-adminlte-card icon="bi bi-box-seam" title="{{ $product->name }}">
                <p>{{ $product->description ?? 'No description available.' }}</p>
                <div class="mb-2">
                    <span class="badge bg-info">{{ $product->group?->name ?? '—' }}</span>
                    <span class="badge bg-secondary">{{ ucfirst(str_replace('_', ' ', $product->billing_cycle ?? 'monthly')) }}</span>
                    <span class="badge bg-primary">From ₹{{ number_format($product->price, 2) }}</span>
                </div>

                @if ($product->pricing->isNotEmpty())
                    <x-adminlte-card title="Pricing" class="mt-3">
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead><tr><th>Period</th><th>Setup</th><th>Price</th></tr></thead>
                                <tbody>
                                    @foreach ($product->pricing as $tier)
                                        <tr>
                                            <td>{{ ucfirst(str_replace('_', ' ', $tier->billing_cycle)) }}</td>
                                            <td>₹{{ number_format($tier->setup_fee, 2) }}</td>
                                            <td>₹{{ number_format($tier->price, 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </x-adminlte-card>
                @endif
            </x-adminlte-card>
        </div>
        <div class="col-md-4">
            <x-adminlte-card icon="bi bi-cart-plus" title="Add to Cart">
                <form method="POST" action="{{ route('admin.cart.add') }}">
                    @csrf
                    <input type="hidden" name="product_id" value="{{ $product->id }}">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Billing Cycle</label>
                        <select name="billing_cycle" class="form-select">
                            @php
                                // The 'free' cycle is a payment-type marker,
                                // never a selectable billing cycle.
                                $cycleTiers = $product->pricing->reject(fn ($tier) => $tier->billing_cycle === 'free');
                            @endphp
                            @if ($cycleTiers->isNotEmpty())
                                @foreach ($cycleTiers as $tier)
                                    <option value="{{ $tier->billing_cycle }}">{{ ucfirst(str_replace('_', ' ', $tier->billing_cycle)) }}</option>
                                @endforeach
                            @else
                                <option value="{{ $product->billing_cycle ?? 'monthly' }}">{{ ucfirst(str_replace('_', ' ', $product->billing_cycle ?? 'monthly')) }}</option>
                            @endif
                        </select>
                    </div>
                    @if ($product->require_domain)
                        <div class="mb-3">
                            <label class="form-label fw-bold">Domain</label>
                            <input type="text" name="domain" class="form-control" placeholder="example.com">
                        </div>
                    @endif
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-cart-plus me-1"></i> Add to Cart</button>
                </form>
                <a href="{{ route('admin.cart.index') }}" class="btn btn-outline-secondary w-100 mt-2">Back to Cart</a>
            </x-adminlte-card>
        </div>
    </div>
@stop