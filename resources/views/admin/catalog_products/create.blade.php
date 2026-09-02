@extends('adminlte::page')

@section('title', 'Add Catalog Product')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Add Catalog Product</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.catalog-products.index') }}">Catalog Products</a></li>
                <li class="breadcrumb-item active" aria-current="page">Add</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-adminlte-alert>
    @endif

    <x-adminlte.partials.form-card icon="bi bi-box-seam" title="New Catalog Product" :action="route('admin.catalog-products.store')" submit-label="Save Product" :cancel-url="route('admin.catalog-products.index')">
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="sku" label="SKU" placeholder="e.g. HOST-SHARED-STD" value="{{ old('sku') }}" required />
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="name" label="Name" placeholder="e.g. Shared Hosting Standard" value="{{ old('name') }}" required />
            </div>
        </div>
        <div class="row">
            <div class="col-md-4">
                <x-adminlte-select name="category_id" label="Category">
                    <option value="">— None —</option>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat->id }}" @selected(old('category_id') == $cat->id)>{{ $cat->name }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-4">
                <x-adminlte-select name="product_type" label="Type">
                    @foreach (['shared' => 'Shared', 'reseller' => 'Reseller', 'dedicated' => 'Dedicated', 'vps' => 'VPS', 'domain' => 'Domain'] as $val => $lbl)
                        <option value="{{ $val }}" @selected(old('product_type', 'shared') === $val)>{{ $lbl }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-4">
                <x-adminlte-select name="billing_model" label="Billing Model">
                    @foreach (['recurring' => 'Recurring', 'one_time' => 'One-Time', 'usage' => 'Usage-Based'] as $val => $lbl)
                        <option value="{{ $val }}" @selected(old('billing_model', 'recurring') === $val)>{{ $lbl }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
        </div>
        <x-adminlte-textarea name="description" label="Description" rows="2">{{ old('description') }}</x-adminlte-textarea>
        <x-adminlte-input name="provisioning_method" label="Provisioning Method" placeholder="e.g. cpanel_api" value="{{ old('provisioning_method') }}" />
        <div class="row">
            <div class="col-md-4">
                <x-adminlte-select name="status" label="Status">
                    <option value="active" @selected(old('status', 'active') === 'active')>Active</option>
                    <option value="inactive" @selected(old('status') === 'inactive')>Inactive</option>
                </x-adminlte-select>
            </div>
            <div class="col-md-4">
                <div class="mt-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="require_domain" value="1" @checked(old('require_domain'))>
                        <label class="form-check-label">Require Domain</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="show_in_order" value="1" @checked(old('show_in_order', true))>
                        <label class="form-check-label">Show in Order Form</label>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <x-adminlte-input name="sort_order" label="Sort Order" type="number" min="0" value="{{ old('sort_order', 0) }}" />
            </div>
        </div>
    </x-adminlte.partials.form-card>
@stop
