@extends('adminlte::page')

@section('title', 'Add IP Address')

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Add IP Address</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.ip-addresses.index') }}">IP Addresses</a></li>
                <li class="breadcrumb-item active" aria-current="page">Add</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-adminlte-alert>
    @endif

    <x-adminlte.partials.form-card icon="bi bi-geo-alt" title="New IP Address" :action="route('admin.ip-addresses.store')" submit-label="Save IP" :cancel-url="route('admin.ip-addresses.index')">
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="ip_address" label="IP Address" placeholder="e.g. 192.168.1.100" value="{{ old('ip_address') }}" required />
            </div>
            <div class="col-md-6">
                <x-adminlte-select name="ip_version" label="IP Version">
                    <option value="4" @selected(old('ip_version', '4') === '4')>IPv4</option>
                    <option value="6" @selected(old('ip_version') === '6')>IPv6</option>
                </x-adminlte-select>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-select name="subnet_id" label="Subnet">
                    <option value="">— None —</option>
                    @foreach ($subnets as $sub)
                        <option value="{{ $sub->id }}" @selected(old('subnet_id') == $sub->id)>{{ $sub->cidr }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-6">
                <x-adminlte-select name="type" label="Type">
                    <option value="private" @selected(old('type', 'private') === 'private')>Private</option>
                    <option value="public" @selected(old('type') === 'public')>Public</option>
                    <option value="reserved" @selected(old('type') === 'reserved')>Reserved</option>
                </x-adminlte-select>
            </div>
        </div>
        <x-adminlte-input name="ptr_record" label="PTR Record" placeholder="Optional reverse DNS" value="{{ old('ptr_record') }}" />
        <x-adminlte-textarea name="notes" label="Notes" rows="2">{{ old('notes') }}</x-adminlte-textarea>
    </x-adminlte.partials.form-card>
@stop
