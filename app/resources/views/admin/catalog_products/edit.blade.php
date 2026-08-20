@extends('adminlte::page')

@section('title', 'Edit Catalog Product — '.$product->name)

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Edit: {{ $product->name }}</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.catalog-products.index') }}">Catalog Products</a></li>
                <li class="breadcrumb-item active" aria-current="page">Edit</li>
            </ol>
        </div>
    </div>
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
