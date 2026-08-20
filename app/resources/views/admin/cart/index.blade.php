@extends('adminlte::page')
@section('title', 'Shopping Cart')
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">Shopping Cart</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item active">Shopping Cart</li></ol></div></div>
@stop
@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif
    <div class="row">
        <div class="col-md-3">
            <x-adminlte-card icon="bi bi-list" title="Categories">
                <ul class="nav nav-pills flex-column">
                    @foreach ($categories as $cat)
                        <li class="nav-item">
                            <a class="nav-link" href="#cat-{{ $cat->id }}">
                                <i class="bi bi-folder me-1"></i> {{ $cat->name }}
                                <span class="badge bg-primary float-end">{{ $cat->catalogProducts->count() }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </x-adminlte-card>
            <x-adminlte-card icon="bi bi-search" title="Domain Search" class="mt-3">
                <form method="GET" action="{{ route('admin.cart.domain-search') }}">
                    <x-adminlte-input name="domain" placeholder="example.com" value="{{ request('domain') }}" />
                    <button type="submit" class="btn btn-primary w-100">Check Availability</button>
                </form>
            </x-adminlte-card>
        </div>
        <div class="col-md-9">
            @forelse ($categories as $cat)
                <x-adminlte-card icon="bi bi-folder" title="{{ $cat->name }}" id="cat-{{ $cat->id }}">
                    <div class="row">
                        @forelse ($cat->products as $product)
                            <div class="col-md-6 col-lg-4 mb-3">
                                <div class="card h-100 border">
                                    <div class="card-body">
                                        <h6 class="card-title">{{ $product->name }}</h6>
                                        <p class="card-text small text-muted">{{ Str::limit($product->description, 80) }}</p>
                                        <span class="badge bg-info">{{ $product->group?->name ?? '—' }}</span>
                                        <span class="badge bg-secondary">{{ ucfirst(str_replace('_', ' ', $product->billing_cycle ?? 'monthly')) }}</span>
                                        <span class="badge bg-primary">₹{{ number_format($product->price, 2) }}</span>
                                    </div>
                                    <div class="card-footer d-flex justify-content-between align-items-center">
                                        <a href="{{ route('admin.cart.product', $product) }}" class="btn btn-sm btn-outline-primary">Details</a>
                                        <form method="POST" action="{{ route('admin.cart.add') }}">
                                            @csrf
                                            <input type="hidden" name="product_id" value="{{ $product->id }}">
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
@stop
