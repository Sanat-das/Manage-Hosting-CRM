@extends('adminlte::page')
@section('title', 'Add Inventory Item')
@section('content_header')
    <x-ui.page-header title="Add Inventory Item" subtitle="Add a component installed inside a device" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Inventory Items','url' => route('admin.inventory-items.index')],['label' => 'Add Inventory Item','active' => true]]" />
@stop
@section('content')
    <x-adminlte.partials.flash-alert />
    @if ($devices->isEmpty())
        <x-adminlte.partials.empty-state icon="bi bi-bricks" title="No devices yet" message="Inventory items are installed inside a device. Create a device first." action-label="Add Device" :action-url="route('admin.devices.create')" />
    @else
        <x-adminlte.partials.form-card icon="bi bi-bricks" title="New Item" :action="route('admin.inventory-items.store')" submit-label="Save Item" :cancel-url="$selectedDeviceId ? route('admin.devices.show', $selectedDeviceId) : route('admin.inventory-items.index')">
            @include('admin.inventory_items._form')
        </x-adminlte.partials.form-card>
    @endif
@stop
