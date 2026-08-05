@extends('adminlte::page')
@section('title', 'Edit Asset — '.$inventoryAsset->name)
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">Edit: {{ $inventoryAsset->name }}</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item"><a href="{{ route('admin.inventory-assets.index') }}">Inventory</a></li><li class="breadcrumb-item active">Edit</li></ol></div></div>
@stop
@section('content')
    @if ($errors->any()) <x-adminlte-alert theme="danger" dismissible><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-adminlte-alert> @endif
    <x-adminlte.partials.form-card icon="bi bi-box-seam" title="Edit Asset" :action="route('admin.inventory-assets.update', $inventoryAsset)" submit-label="Update Asset" :cancel-url="route('admin.inventory-assets.show', $inventoryAsset)">
        @method('PUT')
        <div class="row">
            <div class="col-md-6"><x-adminlte-input name="name" label="Name" value="{{ old('name', $inventoryAsset->name) }}" required /></div>
            <div class="col-md-6"><x-adminlte-input name="serial_number" label="Serial Number" value="{{ old('serial_number', $inventoryAsset->serial_number) }}" /></div>
        </div>
        <div class="row">
            <div class="col-md-4"><x-adminlte-input name="model" label="Model" value="{{ old('model', $inventoryAsset->model) }}" /></div>
            <div class="col-md-4">
                <x-adminlte-select name="datacenter_id" label="Datacenter">
                    <option value="">— None —</option>
                    @foreach ($datacenters as $dc)
                        <option value="{{ $dc->id }}" @selected(old('datacenter_id', $inventoryAsset->datacenter_id) == $dc->id)>{{ $dc->name }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-4">
                <x-adminlte-select name="rack_id" label="Rack">
                    <option value="">— None —</option>
                    @foreach ($racks as $rack)
                        <option value="{{ $rack->id }}" @selected(old('rack_id', $inventoryAsset->rack_id) == $rack->id)>{{ $rack->name }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
        </div>
        <div class="row">
            <div class="col-md-4"><x-adminlte-input name="u_position" label="U Position" type="number" min="1" value="{{ old('u_position', $inventoryAsset->u_position) }}" /></div>
            <div class="col-md-4">
                <x-adminlte-select name="status" label="Status">
                    @foreach (['active','maintenance','decommissioned','spare'] as $s)
                        <option value="{{ $s }}" @selected(old('status', $inventoryAsset->status) === $s)>{{ ucfirst($s) }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-4"><x-adminlte-input name="warranty_expiry" label="Warranty Expiry" type="date" value="{{ old('warranty_expiry', $inventoryAsset->warranty_expiry?->format('Y-m-d')) }}" /></div>
        </div>
        <x-adminlte-textarea name="notes" label="Notes" rows="2">{{ old('notes', $inventoryAsset->notes) }}</x-adminlte-textarea>
    </x-adminlte.partials.form-card>
@stop
