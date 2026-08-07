@extends('adminlte::page')

@section('title', $product->name)

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">{{ $product->name }}</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('client.store.index') }}">Store</a></li>
                <li class="breadcrumb-item active">{{ $product->name }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    <div class="row">
        <div class="col-md-8">
            <x-adminlte-card icon="bi bi-box-seam" title="{{ $product->name }}">
                <p>{{ $product->description ?? 'No description available.' }}</p>
                <div class="mb-2">
                    <span class="badge bg-info">{{ ucfirst($product->type) }}</span>
                    @if ($product->pricing->isNotEmpty())
                        <span class="badge bg-primary">From ₹{{ number_format($product->pricing->min('price'), 2) }}/mo</span>
                    @else
                        <span class="badge bg-primary">₹{{ number_format($product->price, 2) }}</span>
                    @endif
                </div>

                <div class="row mt-3">
                    <div class="col-md-3 col-6"><strong>Disk</strong><br>{{ $product->quota_disk ?? '—' }} GB</div>
                    <div class="col-md-3 col-6"><strong>Bandwidth</strong><br>{{ $product->quota_bandwidth ?? '—' }} GB</div>
                    <div class="col-md-3 col-6"><strong>Email</strong><br>{{ $product->quota_email ?? '—' }}</div>
                    <div class="col-md-3 col-6"><strong>Databases</strong><br>{{ $product->quota_database ?? '—' }}</div>
                </div>
            </x-adminlte-card>
        </div>
        <div class="col-md-4">
            <x-adminlte-card icon="bi bi-cart-plus" title="Add to Cart">
                <form method="POST" action="{{ route('client.store.cart.add') }}">
                    @csrf
                    <input type="hidden" name="product_id" value="{{ $product->id }}">

                    @if ($product->pricing->isNotEmpty())
                        <div class="mb-3">
                            <label class="form-label fw-bold">Billing Cycle</label>
                            @foreach ($product->pricing as $tier)
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="billing_cycle"
                                           value="{{ $tier->billing_cycle }}"
                                           id="cycle-{{ $tier->billing_cycle }}"
                                           @checked($loop->first || $tier->billing_cycle === ($product->billing_cycle ?? null))>
                                    <label class="form-check-label" for="cycle-{{ $tier->billing_cycle }}">
                                        {{ ucfirst(str_replace('_', ' ', $tier->billing_cycle)) }}
                                        — ₹{{ number_format($tier->price, 2) }}
                                        @if ((float) $tier->setup_fee > 0)
                                            <span class="text-muted small">(+₹{{ number_format($tier->setup_fee, 2) }} setup)</span>
                                        @endif
                                    </label>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <input type="hidden" name="billing_cycle" value="{{ $product->billing_cycle ?? 'monthly' }}">
                    @endif

                    <div class="mb-3">
                        <label class="form-label fw-bold">Quantity</label>
                        <input type="number" name="quantity" class="form-control" min="1" max="99" value="1">
                    </div>

                    @if ($product->require_domain)
                        <div class="mb-3">
                            <label class="form-label fw-bold">Domain</label>
                            <input type="text" name="domain" class="form-control" placeholder="example.com">
                        </div>
                    @endif

                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-cart-plus me-1"></i> Add to Cart</button>
                </form>
                <a href="{{ route('client.store.index') }}" class="btn btn-outline-secondary w-100 mt-2">Back to Store</a>
            </x-adminlte-card>
        </div>
    </div>
@stop