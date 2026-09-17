@extends('adminlte::page')
@section('title', 'Add Upgrade Path')
@section('content_header')
    <x-ui.page-header title="Add Upgrade Path" subtitle="Create a new upgrade path" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Product Upgrade Paths', 'url' => route('admin.product-upgrades.index')],
        ['label' => 'Add', 'active' => true],
    ]" />
@stop
@section('content')
    @if ($errors->any()) <x-adminlte-alert theme="danger" dismissible><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-adminlte-alert> @endif
    <x-adminlte.partials.form-card icon="bi bi-arrow-up-right-circle" title="New Upgrade Path" :action="route('admin.product-upgrades.store')" submit-label="Save" :cancel-url="route('admin.product-upgrades.index')">
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-select name="from_product_id" label="From Product" enable-old-support required>
                    <option value="">— Select source product —</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}" @selected(old('from_product_id') == $product->id)>{{ $product->name }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-6">
                <x-adminlte-select name="to_product_id" label="To Product" enable-old-support required>
                    <option value="">— Select target product —</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}" @selected(old('to_product_id') == $product->id)>{{ $product->name }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
        </div>
        <x-adminlte-input-switch name="enabled" label="Enabled" :checked="old('enabled', true) === true || old('enabled', true) === '1'" />
    </x-adminlte.partials.form-card>
@stop
