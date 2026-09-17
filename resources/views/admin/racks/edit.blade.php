@extends('adminlte::page')

@section('title', 'Edit Rack — '.$rack->name)

@section('content_header')
    <x-ui.page-header title="Edit Rack: {{ $rack->name }}" subtitle="Update rack configuration" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Racks', 'url' => route('admin.racks.index')],
        ['label' => 'Edit', 'active' => true],
    ]" />
@stop

@section('content')
    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible>
            <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </x-adminlte-alert>
    @endif

    <x-adminlte.partials.form-card icon="bi bi-racks" title="Edit Rack" :action="route('admin.racks.update', $rack)" submit-label="Update Rack" :cancel-url="route('admin.racks.show', $rack)">
        @method('PUT')
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="name" label="Name" value="{{ old('name', $rack->name) }}" required />
            </div>
            <div class="col-md-6">
                <x-adminlte-select name="datacenter_id" label="Datacenter">
                    <option value="">— None —</option>
                    @foreach ($datacenters as $dc)
                        <option value="{{ $dc->id }}" @selected(old('datacenter_id', $rack->datacenter_id) == $dc->id)>{{ $dc->name }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
        </div>
        <div class="row">
            <div class="col-md-4">
                <x-adminlte-input name="u_height" label="U Height" type="number" value="{{ old('u_height', $rack->u_height) }}" min="1" />
            </div>
            <div class="col-md-4">
                <x-adminlte-input name="u_available" label="U Available" type="number" value="{{ old('u_available', $rack->u_available) }}" min="0" />
            </div>
            <div class="col-md-4">
                <x-adminlte-input name="power_capacity_watts" label="Power Capacity (W)" type="number" value="{{ old('power_capacity_watts', $rack->power_capacity_watts) }}" min="0" />
            </div>
        </div>
        <x-adminlte-select name="status" label="Status">
            @foreach (['active' => 'Active', 'maintenance' => 'Maintenance', 'decommissioned' => 'Decommissioned'] as $val => $lbl)
                <option value="{{ $val }}" @selected(old('status', $rack->status) === $val)>{{ $lbl }}</option>
            @endforeach
        </x-adminlte-select>
    </x-adminlte.partials.form-card>
@stop
