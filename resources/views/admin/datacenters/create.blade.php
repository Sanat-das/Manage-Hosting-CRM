@extends('adminlte::page')
@section('title', 'Add Datacenter')
@section('content_header')
    <x-ui.page-header title="Add Datacenter" subtitle="Provision a new datacenter" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Datacenters', 'url' => route('admin.datacenters.index')],
        ['label' => 'Add', 'active' => true],
    ]" />
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
            {{-- autocomplete="off": a facility address, never the admin's own. --}}
            <div class="col-md-4"><x-adminlte-input name="city" label="City" autocomplete="off" value="{{ old('city') }}" /></div>
            <div class="col-md-4"><x-state-field label="State" autocomplete="off" :value="old('state')" :country="old('country')" /></div>
            <div class="col-md-4"><x-country-select allow-blank autocomplete="off" :selected="old('country')" /></div>
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
