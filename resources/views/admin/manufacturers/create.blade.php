@extends('adminlte::page')

@section('title', 'Add Manufacturer')

@section('content_header')
    <x-ui.page-header title="Add Manufacturer" subtitle="Register a new hardware manufacturer" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Manufacturers', 'url' => route('admin.manufacturers.index')],
        ['label' => 'Add', 'active' => true],
    ]" />
@stop

@section('content')
    <x-adminlte.partials.flash-alert />

    <x-adminlte.partials.form-card icon="bi bi-building" title="New Manufacturer" :action="route('admin.manufacturers.store')" submit-label="Save Manufacturer" :cancel-url="route('admin.manufacturers.index')">
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="name" label="Name" placeholder="e.g. Dell EMC" value="{{ old('name') }}" required />
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="slug" label="Slug" placeholder="auto from name" value="{{ old('slug') }}" />
            </div>
        </div>
        <x-adminlte-textarea name="description" label="Description" rows="2">{{ old('description') }}</x-adminlte-textarea>
    </x-adminlte.partials.form-card>
@stop
