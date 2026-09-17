@extends('adminlte::page')
@section('title', 'Edit Datacenter')
@section('content_header')
    <x-ui.page-header title="Edit Datacenter" subtitle="Update datacenter configuration" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Datacenters', 'url' => route('admin.datacenters.index')],
        ['label' => 'Edit', 'active' => true],
    ]" />
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
            {{-- autocomplete="off": a facility address, never the admin's own. --}}
            <div class="col-md-4"><x-adminlte-input name="city" label="City" autocomplete="off" value="{{ old('city', $datacenter->city) }}" /></div>
            <div class="col-md-4"><x-state-field label="State" autocomplete="off" :value="$datacenter->state" :country="$datacenter->country" /></div>
            <div class="col-md-4"><x-country-select allow-blank autocomplete="off" :selected="$datacenter->country" /></div>
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
