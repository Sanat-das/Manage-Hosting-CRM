@extends('adminlte::page')

@section('title', 'Edit Manufacturer — '.$manufacturer->name)

@section('content_header')
    <x-ui.page-header title="Edit Manufacturer: {{ $manufacturer->name }}" subtitle="Update manufacturer details" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Manufacturers', 'url' => route('admin.manufacturers.index')],
        ['label' => 'Edit', 'active' => true],
    ]" />
@stop

@section('content')
    <x-adminlte.partials.flash-alert />

    <x-adminlte.partials.form-card icon="bi bi-building" title="Edit Manufacturer" :action="route('admin.manufacturers.update', $manufacturer)" submit-label="Update Manufacturer" :cancel-url="route('admin.manufacturers.index')">
        @method('PUT')
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="name" label="Name" value="{{ old('name', $manufacturer->name) }}" required />
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="slug" label="Slug" placeholder="auto from name" value="{{ old('slug', $manufacturer->slug) }}" />
            </div>
        </div>
        <x-adminlte-textarea name="description" label="Description" rows="2">{{ old('description', $manufacturer->description) }}</x-adminlte-textarea>
    </x-adminlte.partials.form-card>
@stop
