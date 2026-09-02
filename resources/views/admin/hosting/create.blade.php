@extends('adminlte::page')

@section('title', 'Add Product/Service')

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Add Product/Service</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.hosting.index') }}">Products/Services</a></li>
                <li class="breadcrumb-item active" aria-current="page">Add Product/Service</li>
            </ol>
        </div>
    </div>
@stop

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
        icon="bi bi-hdd-stack"
        title="New Product/Service"
        :action="route('admin.hosting.store')"
        submit-label="Save Product/Service"
        :cancel-url="route('admin.hosting.index')"
    >
        {{-- New accounts are always created in 'pending' (reference CreateHostingCommand). --}}
        <x-adminlte-alert theme="info" dismissible>
            <i class="bi bi-info-circle me-1"></i>
            New accounts are created in <strong>pending</strong> status and can be activated from the account page.
        </x-adminlte-alert>

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-select name="customer_id" label="Customer" required>
                    @foreach ($customers as $customer)
                        <option value="{{ $customer->id }}" @selected(old('customer_id') == $customer->id)>
                            {{ $customer->full_name }} — {{ $customer->user?->email ?? $customer->display_id }}
                        </option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-6">
                <x-adminlte-select name="product_id" label="Package (product)" required>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}" @selected(old('product_id') == $product->id)>
                            {{ $product->name }} — {{ number_format($product->price, 2) }}
                        </option>
                    @endforeach
                </x-adminlte-select>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="host_name" label="Host name" placeholder="Auto-generated if left blank"
                                  value="{{ old('host_name') }}" />
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="domain" label="Primary domain" placeholder="e.g. example.com"
                                  value="{{ old('domain') }}" />
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-select name="server_id" label="Server (optional)">
                    <option value="">— Unassigned —</option>
                    @foreach ($servers as $server)
                        <option value="{{ $server->id }}" @selected(old('server_id') == $server->id)>
                            {{ $server->name }} ({{ $server->ip_address }})
                        </option>
                    @endforeach
                </x-adminlte-select>
            </div>
        </div>

        {{-- username / username_prefix are legacy/module-managed — credentials live in modules --}}
    </x-adminlte.partials.form-card>
@stop
