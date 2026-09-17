@extends('adminlte::page')

@section('title', 'Add Rack')

@section('content_header')
    <x-ui.page-header title="Add Rack" subtitle="Provision a new rack" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Racks', 'url' => route('admin.racks.index')],
        ['label' => 'Add Rack', 'active' => true],
    ]" />
@stop

@section('content')
    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible>
            <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </x-adminlte-alert>
    @endif

    <x-adminlte.partials.form-card icon="bi bi-racks" title="New Rack" :action="route('admin.racks.store')" submit-label="Save Rack" :cancel-url="route('admin.racks.index')">
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="name" label="Name" placeholder="e.g. Rack A-01" value="{{ old('name') }}" required />
            </div>
            <div class="col-md-6">
                <x-adminlte-select name="datacenter_id" label="Datacenter">
                    <option value="">— Select Datacenter —</option>
                    @foreach ($datacenters as $dc)
                        <option value="{{ $dc->id }}" @selected(old('datacenter_id') == $dc->id)>{{ $dc->name }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
        </div>
        <div class="row">
            <div class="col-md-4">
                <x-adminlte-input name="u_height" label="U Height" type="number" value="{{ old('u_height', 42) }}" min="1" />
            </div>
            <div class="col-md-4">
                <x-adminlte-input name="u_available" label="U Available" type="number" value="{{ old('u_available') }}" min="0" />
            </div>
            <div class="col-md-4">
                <x-adminlte-input name="power_capacity_watts" label="Power Capacity (W)" type="number" value="{{ old('power_capacity_watts') }}" min="0" />
            </div>
        </div>
        <x-adminlte-select name="status" label="Status">
            <option value="active" @selected(old('status', 'active') === 'active')>Active</option>
            <option value="maintenance" @selected(old('status') === 'maintenance')>Maintenance</option>
            <option value="decommissioned" @selected(old('status') === 'decommissioned')>Decommissioned</option>
        </x-adminlte-select>
    </x-adminlte.partials.form-card>
@stop
