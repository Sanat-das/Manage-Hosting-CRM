@extends('adminlte::page')
@section('title', 'Add Inventory Asset')
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">Add Inventory Asset</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item"><a href="{{ route('admin.inventory-assets.index') }}">Inventory</a></li><li class="breadcrumb-item active">Add</li></ol></div></div>
@stop
@section('content')
    @if ($errors->any()) <x-adminlte-alert theme="danger" dismissible><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-adminlte-alert> @endif
    <x-adminlte.partials.form-card icon="bi bi-box-seam" title="New Asset" :action="route('admin.inventory-assets.store')" submit-label="Save Asset" :cancel-url="route('admin.inventory-assets.index')">
        <div class="row">
            <div class="col-md-6"><x-adminlte-input name="name" label="Name" placeholder="e.g. Web Server 01" value="{{ old('name') }}" required /></div>
            <div class="col-md-6">
                <x-adminlte-select name="asset_type" label="Type">
                    @foreach (['server','switch','router','pdu','nic','storage','other'] as $t)
                        <option value="{{ $t }}" @selected(old('asset_type', 'server') === $t)>{{ ucfirst($t) }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
        </div>
        <div class="row">
            <div class="col-md-4"><x-adminlte-input name="serial_number" label="Serial Number" value="{{ old('serial_number') }}" /></div>
            <div class="col-md-4"><x-adminlte-input name="model" label="Model" value="{{ old('model') }}" /></div>
            <div class="col-md-4"><x-adminlte-input name="manufacturer" label="Manufacturer" value="{{ old('manufacturer') }}" /></div>
        </div>
        <div class="row">
            <div class="col-md-4">
                <x-adminlte-select name="datacenter_id" label="Datacenter">
                    <option value="">— None —</option>
                    @foreach ($datacenters as $dc)
                        <option value="{{ $dc->id }}" @selected(old('datacenter_id') == $dc->id)>{{ $dc->name }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-4">
                <x-adminlte-select name="rack_id" label="Rack">
                    <option value="">— None —</option>
                    @foreach ($racks as $rack)
                        <option value="{{ $rack->id }}" @selected(old('rack_id') == $rack->id)>{{ $rack->name }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-4"><x-adminlte-input name="u_position" label="U Position" type="number" min="1" value="{{ old('u_position') }}" /></div>
        </div>
        <div class="row">
            <div class="col-md-4"><x-adminlte-input name="purchase_date" label="Purchase Date" type="date" value="{{ old('purchase_date') }}" /></div>
            <div class="col-md-4"><x-adminlte-input name="warranty_expiry" label="Warranty Expiry" type="date" value="{{ old('warranty_expiry') }}" /></div>
            <div class="col-md-4">
                <x-adminlte-select name="status" label="Status">
                    @foreach (['active','maintenance','decommissioned','spare'] as $s)
                        <option value="{{ $s }}" @selected(old('status', 'active') === $s)>{{ ucfirst($s) }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
        </div>
        <x-adminlte-textarea name="notes" label="Notes" rows="2">{{ old('notes') }}</x-adminlte-textarea>
    </x-adminlte.partials.form-card>
@stop
