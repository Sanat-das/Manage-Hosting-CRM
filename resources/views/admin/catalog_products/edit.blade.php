@extends('adminlte::page')

@section('title', 'Edit Catalog Product — '.$product->name)

@section('content_header')
    <x-ui.page-header title="Edit: {{ $product->name }}" subtitle="Update catalog product details" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Catalog Products', 'url' => route('admin.catalog-products.index')],
        ['label' => 'Edit', 'active' => true],
    ]" />
@stop

@section('content')
    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-adminlte-alert>
    @endif

    <x-adminlte.partials.form-card icon="bi bi-box-seam" title="Edit Catalog Product" :action="route('admin.catalog-products.update', $product)" submit-label="Update Product" :cancel-url="route('admin.catalog-products.show', $product)">
        @method('PUT')
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="name" label="Name" value="{{ old('name', $product->name) }}" required />
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="description" label="Description" value="{{ old('description', $product->description) }}" />
            </div>
        </div>
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-select name="status" label="Status">
                    <option value="active" @selected(old('status', $product->status) === 'active')>Active</option>
                    <option value="inactive" @selected(old('status') === 'inactive')>Inactive</option>
                </x-adminlte-select>
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="sort_order" label="Sort Order" type="number" min="0" value="{{ old('sort_order', $product->sort_order) }}" />
            </div>
        </div>
    </x-adminlte.partials.form-card>
@stop
