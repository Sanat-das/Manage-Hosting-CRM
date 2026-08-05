@extends('adminlte::page')

@section('title', 'Edit: ' . $addon->name)

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Edit Add-on</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.addons.index') }}">Add-ons</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $addon->name }}</li>
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
        icon="bi bi-plus-square"
        title="Edit: {{ $addon->name }}"
        :action="route('admin.addons.update', $addon)"
        submit-label="Save Changes"
        :cancel-url="route('admin.addons.index')"
    >
        @method('PUT')

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="name" label="Name" placeholder="Add-on name"
                                  value="{{ old('name', $addon->name) }}" required />
            </div>
            <div class="col-md-6">
                <x-adminlte-select name="product_id" label="Product (optional)">
                    <option value="">Global (available for all products)</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}" @selected(old('product_id', $addon->product_id) == $product->id)>{{ $product->name }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
        </div>

        <x-adminlte-textarea name="description" label="Description" rows="2"
                             placeholder="Brief description (optional)">{{ old('description', $addon->description) }}</x-adminlte-textarea>

        <div class="row">
            <div class="col-md-4">
                <x-adminlte-select name="billing_cycle" label="Billing cycle" required>
                    @foreach (['one_time' => 'One Time', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'semi_annual' => 'Semi-Annual', 'annual' => 'Annual'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('billing_cycle', $addon->billing_cycle) === $value)>{{ $label }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-4">
                <x-adminlte-input name="price" type="number" step="0.01" min="0" label="Price"
                                  value="{{ old('price', $addon->price) }}" required />
            </div>
            <div class="col-md-4">
                <x-adminlte-input name="setup_fee" type="number" step="0.01" min="0" label="Setup fee"
                                  value="{{ old('setup_fee', $addon->setup_fee) }}" />
            </div>
        </div>

        <x-adminlte-select name="status" label="Status">
            @foreach (['active' => 'Active', 'inactive' => 'Inactive'] as $value => $label)
                <option value="{{ $value }}" @selected(old('status', $addon->status) === $value)>{{ $label }}</option>
            @endforeach
        </x-adminlte-select>
    </x-adminlte.partials.form-card>
@stop
