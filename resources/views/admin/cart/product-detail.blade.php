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
                    <span class="badge bg-info">{{ ucfirst($product->product_type) }}</span>
                    <span class="badge bg-secondary">{{ ucfirst(str_replace('_', ' ', $product->billing_model)) }}</span>
                    <span class="badge bg-primary">SKU: {{ $product->sku }}</span>
                </div>

                @if ($configurableOptions)
                    <x-adminlte-card title="Options" class="mt-3">
                        @foreach ($configurableOptions as $option)
                            <div class="mb-2">
                                <label class="form-label fw-bold">{{ $option['name'] ?? 'Option' }}</label>
                                <select class="form-select" name="option[{{ $option['slug'] ?? $loop->index }}]">
                                    @foreach ($option['values'] ?? [] as $val)
                                        <option value="{{ $val }}">{{ $val }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endforeach
                    </x-adminlte-card>
                @endif

                @if ($pricingTiers)
                    <x-adminlte-card title="Pricing" class="mt-3">
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead><tr><th>Period</th><th>Price</th></tr></thead>
                                <tbody>
                                    @foreach ($pricingTiers as $tier)
                                        <tr><td>{{ $tier['period'] ?? 'Monthly' }}</td><td>${{ number_format($tier['price'] ?? 0, 2) }}</td></tr>
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
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-cart-plus me-1"></i> Add to Cart</button>
                </form>
                <a href="{{ route('admin.cart.index') }}" class="btn btn-outline-secondary w-100 mt-2">Back to Cart</a>
            </x-adminlte-card>
        </div>
    </div>
@stop
