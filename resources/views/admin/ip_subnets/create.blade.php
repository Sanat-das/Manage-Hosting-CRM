@extends('adminlte::page')
@section('title', 'Add IP Subnet')
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">Add IP Subnet</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item"><a href="{{ route('admin.ip-subnets.index') }}">IP Subnets</a></li><li class="breadcrumb-item active">Add</li></ol></div></div>
@stop
@section('content')
    @if ($errors->any()) <x-adminlte-alert theme="danger" dismissible><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-adminlte-alert> @endif
    <x-adminlte.partials.form-card icon="bi bi-grid" title="New IP Subnet" :action="route('admin.ip-subnets.store')" submit-label="Save Subnet" :cancel-url="route('admin.ip-subnets.index')">
        <div class="row">
            <div class="col-md-4"><x-adminlte-input name="name" label="Name" placeholder="e.g. DMZ-01" value="{{ old('name') }}" required /></div>
            <div class="col-md-4"><x-adminlte-input name="subnet_cidr" label="CIDR" placeholder="e.g. 10.0.0.0/24" value="{{ old('subnet_cidr') }}" required /></div>
            <div class="col-md-4"><x-adminlte-input name="gateway" label="Gateway" placeholder="e.g. 10.0.0.1" value="{{ old('gateway') }}" /></div>
        </div>
        <div class="row">
            <div class="col-md-4"><x-adminlte-input name="netmask" label="Netmask" placeholder="e.g. 255.255.255.0" value="{{ old('netmask') }}" /></div>
            <div class="col-md-4"><x-adminlte-input name="total_addresses" label="Total IPs" type="number" min="0" value="{{ old('total_addresses') }}" /></div>
            <div class="col-md-4">
                <x-adminlte-select name="ip_version" label="IP Version">
                    <option value="4" @selected(old('ip_version') === '4')>IPv4</option>
                    <option value="6" @selected(old('ip_version') === '6')>IPv6</option>
                </x-adminlte-select>
            </div>
        </div>
        <div class="row">
            <div class="col-md-4">
                <x-adminlte-select name="network_type" label="Network Type">
                    <option value="private" @selected(old('network_type') === 'private')>Private</option>
                    <option value="public" @selected(old('network_type') === 'public')>Public</option>
                </x-adminlte-select>
            </div>
            <div class="col-md-4">
                <x-adminlte-select name="datacenter_id" label="Datacenter">
                    <option value="">—</option>
                    @foreach ($datacenters as $dc)
                        <option value="{{ $dc->id }}" @selected(old('datacenter_id') == $dc->id)>{{ $dc->name }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-4">
                <x-adminlte-select name="vlan_id" label="VLAN">
                    <option value="">—</option>
                    @foreach ($vlans as $vlan)
                        <option value="{{ $vlan->id }}" @selected(old('vlan_id') == $vlan->id)>{{ $vlan->name }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
        </div>
        <x-adminlte-textarea name="description" label="Description" rows="2">{{ old('description') }}</x-adminlte-textarea>
    </x-adminlte.partials.form-card>
@stop
