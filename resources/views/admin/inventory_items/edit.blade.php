@extends('adminlte::page')
@section('title', 'Edit Item — '.$item->name)
@section('content_header')
    <x-ui.page-header title="Edit: {{ $item->name }}" subtitle="Update inventory item details" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Inventory Items','url' => route('admin.inventory-items.index')],['label' => 'Edit','active' => true]]" />
@stop
@section('content')
    <x-adminlte.partials.flash-alert />
    <x-adminlte.partials.form-card icon="bi bi-bricks" title="Edit Item" :action="route('admin.inventory-items.update', $item)" submit-label="Update Item" :cancel-url="route('admin.inventory-items.show', $item)">
        @method('PUT')
        @include('admin.inventory_items._form')
    </x-adminlte.partials.form-card>
@stop
