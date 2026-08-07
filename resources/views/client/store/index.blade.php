@extends('adminlte::page')

@section('title', 'Store')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Store</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('client.dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item active">Store</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif
    @if (session('error')) <x-adminlte-alert theme="danger" dismissible>{{ session('error') }}</x-adminlte-alert> @endif

    @if ($categories->isEmpty())
        <x-adminlte-alert theme="info">No products are available in the store right now.</x-adminlte-alert>
    @else
        <div class="row">
            <div class="col-md-3">
                <x-adminlte-card icon="bi bi-list" title="Categories">
                    <ul class="nav nav-pills flex-column">
                        @foreach ($categories as $cat)
                            <li class="nav-item">
                                <a class="nav-link" href="#cat-{{ $cat->id }}">
                                    <i class="bi bi-folder me-1"></i> {{ $cat->name }}
                                    <span class="badge bg-primary float-end">{{ $cat->products->count() }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                    <hr>
                    <a href="{{ route('client.store.cart') }}" class="btn btn-outline-primary w-100">
                        <i class="bi bi-cart me-1"></i> View Cart
                    </a>
                </x-adminlte-card>
            </div>
            <div class="col-md-9">
                @forelse ($categories as $cat)
                    <x-adminlte-card icon="bi bi-folder" title="{{ $cat->name }}" id="cat-{{ $cat->id }}">
                        <div class="row">
                            @forelse ($cat->products as $product)
                                @php
                                    $minPrice = $product->pricing->min('price') ?? $product->price;
                                @endphp
                                <div class="col-md-6 col-lg-4 mb-3">
                                    <div class="card h-100 border">
                                        <div class="card-body">
                                            <h6 class="card-title">{{ $product->name }}</h6>
                                            <p class="card-text small text-muted">{{ Str::limit($product->description, 80) }}</p>
                                            <span class="badge bg-info">From ₹{{ number_format($minPrice, 2) }}/mo</span>
                                            <span class="badge bg-secondary">{{ ucfirst($product->type) }}</span>
                                        </div>
                                        <div class="card-footer d-flex justify-content-between align-items-center">
                                            <a href="{{ route('client.store.show', $product) }}" class="btn btn-sm btn-outline-primary">View</a>
                                            <form method="POST" action="{{ route('client.store.cart.add') }}" class="d-inline">
                                                @csrf
                                                <input type="hidden" name="product_id" value="{{ $product->id }}">
                                                <input type="hidden" name="billing_cycle" value="{{ $product->billing_cycle ?? 'monthly' }}">
                                                <input type="hidden" name="quantity" value="1">
                                                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-cart-plus"></i></button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <div class="col-12 text-muted">No products in this category.</div>
                            @endforelse
                        </div>
                    </x-adminlte-card>
                @empty
                    <x-adminlte-alert theme="info">No product categories available.</x-adminlte-alert>
                @endforelse
            </div>
        </div>
    @endif
@stop