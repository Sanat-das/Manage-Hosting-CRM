@extends('adminlte::page')

@section('title', 'Add Server')

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Add Server</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.servers.index') }}">Servers</a></li>
                <li class="breadcrumb-item active" aria-current="page">Add Server</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible>
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-adminlte-alert>
    @endif

    <x-adminlte.partials.form-card
        icon="bi bi-server"
        title="New Server"
        :action="route('admin.servers.store')"
        submit-label="Save Server"
        :cancel-url="route('admin.servers.index')"
    >
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="name" label="Name" placeholder="e.g. Web-01"
                                  value="{{ old('name') }}" required />
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="ip_address" label="IP address" placeholder="e.g. 192.168.1.10"
                                  value="{{ old('ip_address') }}" required />
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-select name="panel_type" label="Panel type">
                    @foreach (['cpanel' => 'cPanel', 'plesk' => 'Plesk', 'directadmin' => 'DirectAdmin', 'custom' => 'Custom'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('panel_type', 'cpanel') === $value)>{{ $label }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-6">
                <x-adminlte-select name="status" label="Status">
                    <option value="active" @selected(old('status', 'active') === 'active')>Active</option>
                    <option value="inactive" @selected(old('status') === 'inactive')>Inactive</option>
                </x-adminlte-select>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="api_url" label="API URL" placeholder="e.g. https://web01.example.com:2083"
                                  value="{{ old('api_url') }}" />
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="api_username" label="API username" placeholder="Optional"
                                  value="{{ old('api_username') }}" />
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="api_key" label="API key" placeholder="Optional"
                                  value="{{ old('api_key') }}" />
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="max_accounts" type="number" min="0" step="1" label="Max accounts (0 = unlimited)"
                                  value="{{ old('max_accounts', 0) }}" />
            </div>
        </div>
    </x-adminlte.partials.form-card>
@stop
