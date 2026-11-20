@extends('adminlte::page')
@section('title', 'Edit Device — '.$device->asset_tag)
@section('content_header')
    <x-ui.page-header title="Edit: {{ $device->asset_tag }}" subtitle="Update device details" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Devices','url' => route('admin.devices.index')],['label' => 'Edit','active' => true]]" />
@stop
@section('content')
    <x-adminlte.partials.flash-alert />
    <x-adminlte.partials.form-card icon="bi bi-box-seam" title="Edit Device" :action="route('admin.devices.update', $device)" submit-label="Update Device" :cancel-url="route('admin.devices.show', $device)">
        @method('PUT')
        <div class="row">
            <div class="col-md-6"><x-adminlte-input name="asset_tag" label="Asset Tag" value="{{ old('asset_tag', $device->asset_tag) }}" required /></div>
            <div class="col-md-6"><x-adminlte-input name="serial_number" label="Serial Number" value="{{ old('serial_number', $device->serial_number) }}" /></div>
        </div>
        <div class="row">
            <div class="col-md-4"><x-adminlte-input name="manufacturer" label="Manufacturer" value="{{ old('manufacturer', $device->manufacturer) }}" /></div>
            <div class="col-md-4"><x-adminlte-input name="purchase_date" label="Purchase Date" type="date" value="{{ old('purchase_date', $device->purchase_date?->format('Y-m-d')) }}" /></div>
        </div>
        <div class="row">
            <div class="col-md-4"><x-adminlte-input name="model" label="Model" value="{{ old('model', $device->model) }}" /></div>
            <div class="col-md-4">
                <x-adminlte-select name="datacenter_id" label="Datacenter">
                    <option value="">— None —</option>
                    @foreach ($datacenters as $dc)
                        <option value="{{ $dc->id }}" @selected(old('datacenter_id', $device->datacenter_id) == $dc->id)>{{ $dc->name }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-4">
                <x-adminlte-select name="rack_id" label="Rack">
                    <option value="">— None —</option>
                    @foreach ($racks as $rack)
                        <option value="{{ $rack->id }}" @selected(old('rack_id', $device->rack_id) == $rack->id)>{{ $rack->name }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-4">
                <x-adminlte-select name="face" label="Mounting face">
                    <option value="front" @selected(old('face', $device->face) === 'front')>Front</option>
                    <option value="rear" @selected(old('face', $device->face) === 'rear')>Rear</option>
                </x-adminlte-select>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-select name="device_type_id" label="Device Type" required>
                    @foreach ($deviceTypes->groupBy(fn ($deviceType) => $deviceType->manufacturer?->name ?? 'Unattributed') as $manufacturerName => $group)
                        <optgroup label="{{ $manufacturerName }}">
                            @foreach ($group as $deviceType)
                                <option value="{{ $deviceType->id }}" data-category="{{ $deviceType->category }}" @selected(old('device_type_id', $device->device_type_id) == $deviceType->id)>{{ $manufacturerName }} — {{ $deviceType->model }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </x-adminlte-select>
                <small class="form-text text-muted">Selecting a device type sets manufacturer and model from the catalog.</small>
            </div>
            <div class="col-md-6">
                <label for="tags" class="form-label">Tags</label>
                <input type="hidden" name="tags_submitted" value="1">
                <select name="tags[]" id="tags" multiple size="5" class="form-select @error('tags') is-invalid @enderror @error('tags.*') is-invalid @enderror">
                    @foreach ($tags as $tag)
                        <option value="{{ $tag->id }}" @selected(in_array($tag->id, old('tags', $device->tags->pluck('id')->all())))>{{ $tag->name }}</option>
                    @endforeach
                </select>
                @error('tags')
                    <span class="invalid-feedback d-block" role="alert">{{ $message }}</span>
                @enderror
                @error('tags.*')
                    <span class="invalid-feedback d-block" role="alert">{{ $message }}</span>
                @enderror
                <small class="form-text text-muted">Ctrl/Cmd-click to select multiple tags.</small>
            </div>
        </div>
        <div class="row">
            <div class="col-md-4"><x-adminlte-input name="rack_u_position" label="U Position" type="number" min="1" value="{{ old('rack_u_position', $device->rack_u_position) }}" /></div>
            <div class="col-md-4">
                <x-adminlte-select name="status" label="Status">
                    @foreach ($statuses as $s)
                        <option value="{{ $s }}" @selected(old('status', $device->status) === $s)>{{ ucfirst(str_replace('_', ' ', $s)) }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-4"><x-adminlte-input name="warranty_expiry" label="Warranty Expiry" type="date" value="{{ old('warranty_expiry', $device->warranty_expiry?->format('Y-m-d')) }}" /></div>
        </div>
        @include('admin.devices._ip_picker')
        <div class="row">
            <div class="col-md-6"><x-adminlte-input name="vendor" label="Vendor" value="{{ old('vendor', $device->vendor) }}" /></div>
            <div class="col-md-6"><x-adminlte-input name="purchase_cost" label="Purchase Cost" type="number" step="0.01" min="0" value="{{ old('purchase_cost', $device->purchase_cost) }}" /></div>
        </div>
        <x-adminlte-textarea name="notes" label="Notes" rows="2">{{ old('notes', $device->notes) }}</x-adminlte-textarea>
    </x-adminlte.partials.form-card>
@stop

