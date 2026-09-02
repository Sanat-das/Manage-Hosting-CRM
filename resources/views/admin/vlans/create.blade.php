@extends('adminlte::page')

@section('title', 'Add VLAN')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Add VLAN</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.vlans.index') }}">VLANs</a></li>
                <li class="breadcrumb-item active" aria-current="page">Add</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-adminlte-alert>
    @endif

    <x-adminlte.partials.form-card icon="bi bi-diagram-3" title="New VLAN" :action="route('admin.vlans.store')" submit-label="Save VLAN" :cancel-url="route('admin.vlans.index')">
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="name" label="Name" placeholder="e.g. Production VLAN" value="{{ old('name') }}" required />
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="vlan_id" label="VLAN ID" type="number" min="1" max="4094" value="{{ old('vlan_id') }}" required />
            </div>
        </div>
        <x-adminlte-select name="datacenter_id" label="Datacenter">
            <option value="">— None —</option>
            @foreach ($datacenters as $dc)
                <option value="{{ $dc->id }}" @selected(old('datacenter_id') == $dc->id)>{{ $dc->name }}</option>
            @endforeach
        </x-adminlte-select>
        <x-adminlte-textarea name="description" label="Description" rows="2">{{ old('description') }}</x-adminlte-textarea>
    </x-adminlte.partials.form-card>
@stop
