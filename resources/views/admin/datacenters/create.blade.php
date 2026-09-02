@extends('adminlte::page')
@section('title', 'Add Datacenter')
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">Add Datacenter</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item"><a href="{{ route('admin.datacenters.index') }}">Datacenters</a></li><li class="breadcrumb-item active">Add</li></ol></div></div>
@stop
@section('content')
    @if ($errors->any()) <x-adminlte-alert theme="danger" dismissible><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-adminlte-alert> @endif
    <x-adminlte.partials.form-card icon="bi bi-building" title="New Datacenter" :action="route('admin.datacenters.store')" submit-label="Save Datacenter" :cancel-url="route('admin.datacenters.index')">
        <div class="row">
            <div class="col-md-6"><x-adminlte-input name="name" label="Name" placeholder="e.g. Mumbai DC-1" value="{{ old('name') }}" required /></div>
            <div class="col-md-6"><x-adminlte-input name="code" label="Code" placeholder="e.g. MUM1" value="{{ old('code') }}" /></div>
        </div>
        <x-adminlte-input name="address" label="Address" placeholder="e.g. 100 Data Center Way" value="{{ old('address') }}" />
        <div class="row">
            <div class="col-md-4"><x-adminlte-input name="city" label="City" value="{{ old('city') }}" /></div>
            <div class="col-md-4"><x-adminlte-input name="state" label="State" value="{{ old('state') }}" /></div>
            <div class="col-md-4"><x-adminlte-input name="country" label="Country" value="{{ old('country') }}" /></div>
        </div>
        <div class="row">
            <div class="col-md-6"><x-adminlte-input name="timezone" label="Timezone" placeholder="e.g. Asia/Kolkata" value="{{ old('timezone') }}" /></div>
            <div class="col-md-6">
                <x-adminlte-select name="status" label="Status">
                    <option value="active" @selected(old('status') === 'active')>Active</option>
                    <option value="maintenance" @selected(old('status') === 'maintenance')>Maintenance</option>
                    <option value="decommissioned" @selected(old('status') === 'decommissioned')>Decommissioned</option>
                </x-adminlte-select>
            </div>
        </div>
    </x-adminlte.partials.form-card>
@stop
