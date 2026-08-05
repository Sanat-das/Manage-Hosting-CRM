@extends('adminlte::page')

@section('title', 'Add Product Group')

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Add Product Group</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.product-groups.index') }}">Product Groups</a></li>
                <li class="breadcrumb-item active" aria-current="page">Add Product Group</li>
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
        icon="bi bi-collection"
        title="New Product Group"
        :action="route('admin.product-groups.store')"
        submit-label="Save Group"
        :cancel-url="route('admin.product-groups.index')"
    >
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="name" label="Name" placeholder="e.g. Shared Hosting"
                                  value="{{ old('name') }}" required />
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="slug" label="Slug" placeholder="Auto-generated from name if left blank"
                                  value="{{ old('slug') }}" />
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-select name="parent_id" label="Parent group">
                    <option value="">— None —</option>
                    @foreach ($parents as $parent)
                        <option value="{{ $parent->id }}" @selected((string) old('parent_id') === (string) $parent->id)>{{ $parent->name }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="sort_order" type="number" min="0" label="Sort order"
                                  value="{{ old('sort_order', 0) }}" />
            </div>
        </div>

        <x-adminlte-select name="status" label="Status">
            <option value="active" @selected(old('status', 'active') === 'active')>Active</option>
            <option value="inactive" @selected(old('status') === 'inactive')>Inactive</option>
        </x-adminlte-select>

        <x-adminlte-textarea name="description" label="Description" rows="2"
                             placeholder="Optional description">{{ old('description') }}</x-adminlte-textarea>
    </x-adminlte.partials.form-card>
@stop
