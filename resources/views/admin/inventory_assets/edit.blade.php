@extends('adminlte::page')
@section('title', 'Edit Asset — '.$inventoryAsset->asset_tag)
@section('content_header')
    <x-ui.page-header title="Edit: {{ $inventoryAsset->asset_tag }}" subtitle="Update inventory asset details" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Inventory','url' => route('admin.inventory-assets.index')],['label' => 'Edit','active' => true]]" />
@stop
@section('content')
    <x-adminlte.partials.flash-alert />
    <x-adminlte.partials.form-card icon="bi bi-box-seam" title="Edit Asset" :action="route('admin.inventory-assets.update', $inventoryAsset)" submit-label="Update Asset" :cancel-url="route('admin.inventory-assets.show', $inventoryAsset)">
        @method('PUT')
        <div class="row">
            <div class="col-md-6"><x-adminlte-input name="asset_tag" label="Asset Tag" value="{{ old('asset_tag', $inventoryAsset->asset_tag) }}" required /></div>
            <div class="col-md-6"><x-adminlte-input name="serial_number" label="Serial Number" value="{{ old('serial_number', $inventoryAsset->serial_number) }}" /></div>
        </div>
        <div class="row">
            <div class="col-md-4">
                <x-adminlte-select name="asset_type" id="asset_type" label="Type">
                    @foreach ($assetTypes as $t)
                        <option value="{{ $t }}" @selected(old('asset_type', $inventoryAsset->asset_type) === $t)>{{ ucfirst(str_replace('_', ' ', $t)) }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-4"><x-adminlte-input name="manufacturer" label="Manufacturer" value="{{ old('manufacturer', $inventoryAsset->manufacturer) }}" /></div>
            <div class="col-md-4"><x-adminlte-input name="purchase_date" label="Purchase Date" type="date" value="{{ old('purchase_date', $inventoryAsset->purchase_date?->format('Y-m-d')) }}" /></div>
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
            <div class="col-md-4"><x-adminlte-input name="rack_u_position" label="U Position" type="number" min="1" value="{{ old('rack_u_position', $inventoryAsset->rack_u_position) }}" /></div>
            <div class="col-md-4">
                <x-adminlte-select name="status" label="Status">
                    @foreach ($statuses as $s)
                        <option value="{{ $s }}" @selected(old('status', $inventoryAsset->status) === $s)>{{ ucfirst(str_replace('_', ' ', $s)) }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-4"><x-adminlte-input name="warranty_expiry" label="Warranty Expiry" type="date" value="{{ old('warranty_expiry', $inventoryAsset->warranty_expiry?->format('Y-m-d')) }}" /></div>
        </div>
        @include('admin.inventory_assets._ip_picker')
        <div class="row">
            <div class="col-md-6"><x-adminlte-input name="vendor" label="Vendor" value="{{ old('vendor', $inventoryAsset->vendor) }}" /></div>
            <div class="col-md-6"><x-adminlte-input name="purchase_cost" label="Purchase Cost" type="number" step="0.01" min="0" value="{{ old('purchase_cost', $inventoryAsset->purchase_cost) }}" /></div>
        </div>
        <x-adminlte-textarea name="notes" label="Notes" rows="2">{{ old('notes', $inventoryAsset->notes) }}</x-adminlte-textarea>
    </x-adminlte.partials.form-card>
@stop

