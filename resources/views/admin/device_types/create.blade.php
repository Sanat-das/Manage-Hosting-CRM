@extends('adminlte::page')

@section('title', 'Add Device Type')

@section('content_header')
    <x-ui.page-header title="Add Device Type" subtitle="Register a device model" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Device Types', 'url' => route('admin.device-types.index')],
        ['label' => 'Add', 'active' => true],
    ]" />
@stop

@section('content')
    <x-adminlte.partials.flash-alert />

    <x-adminlte.partials.form-card icon="bi bi-cpu" title="New Device Type" :action="route('admin.device-types.store')" submit-label="Save Device Type" :cancel-url="route('admin.device-types.index')">
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-select name="manufacturer_id" label="Manufacturer" required>
                    <option value="">— Select manufacturer —</option>
                    @foreach ($manufacturers as $manufacturer)
                        <option value="{{ $manufacturer->id }}" @selected(old('manufacturer_id') == $manufacturer->id)>{{ $manufacturer->name }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="model" label="Model" placeholder="e.g. PowerEdge R750" value="{{ old('model') }}" required />
            </div>
        </div>
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-select name="category" label="Category" required>
                    @foreach (\App\Models\DeviceType::CATEGORIES as $value => $label)
                        <option value="{{ $value }}" @selected(old('category', 'server') === $value)>{{ $label }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="slug" label="Slug" placeholder="auto from manufacturer + model" value="{{ old('slug') }}" />
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="part_number" label="Part Number" placeholder="e.g. PNR750" value="{{ old('part_number') }}" />
            </div>
        </div>
        <div class="row">
            <div class="col-md-3">
                <x-adminlte-input name="u_height" label="U Height" type="number" min="1" max="100" value="{{ old('u_height', 1) }}" />
            </div>
            <div class="col-md-3">
                <x-adminlte-input name="power_draw_watts" label="Power Draw (W)" type="number" min="0" placeholder="e.g. 450" value="{{ old('power_draw_watts') }}" />
            </div>
            <div class="col-md-6">
                <input type="hidden" name="is_full_depth" value="0">
                <x-adminlte-input-switch name="is_full_depth" label="Full depth" :checked="(bool) old('is_full_depth', true)" />
            </div>
        </div>
        <x-adminlte-textarea name="description" label="Description" rows="2">{{ old('description') }}</x-adminlte-textarea>
    </x-adminlte.partials.form-card>
@stop
