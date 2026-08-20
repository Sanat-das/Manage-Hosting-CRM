@extends('adminlte::page')
@section('title', 'Edit Datacenter')
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">Edit Datacenter</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item"><a href="{{ route('admin.datacenters.index') }}">Datacenters</a></li><li class="breadcrumb-item active">Edit</li></ol></div></div>
@stop
@section('content')
    @if ($errors->any()) <x-adminlte-alert theme="danger" dismissible><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-adminlte-alert> @endif
    <x-adminlte.partials.form-card icon="bi bi-building" title="Edit Datacenter" :action="route('admin.datacenters.update', $datacenter)" submit-label="Update Datacenter" :cancel-url="route('admin.datacenters.show', $datacenter)">
        @method('PUT')
        <div class="row">
            <div class="col-md-6"><x-adminlte-input name="name" label="Name" value="{{ old('name', $datacenter->name) }}" required /></div>
            <div class="col-md-6"><x-adminlte-input name="code" label="Code" value="{{ old('code', $datacenter->code) }}" /></div>
        </div>
        <x-adminlte-input name="address" label="Address" value="{{ old('address', $datacenter->address) }}" />
        <div class="row">
            <div class="col-md-4"><x-adminlte-input name="city" label="City" value="{{ old('city', $datacenter->city) }}" /></div>
            <div class="col-md-4"><x-adminlte-input name="state" label="State" value="{{ old('state', $datacenter->state) }}" /></div>
            <div class="col-md-4"><x-adminlte-input name="country" label="Country" value="{{ old('country', $datacenter->country) }}" /></div>
        </div>
        <div class="row">
            <div class="col-md-6"><x-adminlte-input name="timezone" label="Timezone" value="{{ old('timezone', $datacenter->timezone) }}" /></div>
            <div class="col-md-6">
                <x-adminlte-select name="status" label="Status">
                    <option value="active" @selected(old('status', $datacenter->status) === 'active')>Active</option>
                    <option value="maintenance" @selected(old('status', $datacenter->status) === 'maintenance')>Maintenance</option>
                    <option value="decommissioned" @selected(old('status', $datacenter->status) === 'decommissioned')>Decommissioned</option>
                </x-adminlte-select>
            </div>
        </div>
    </x-adminlte.partials.form-card>
@stop
