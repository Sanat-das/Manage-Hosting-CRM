@extends('adminlte::page')
@section('title', 'Add DNS Zone')
@section('content_header')
    <x-ui.page-header title="Add DNS Zone" subtitle="Provision a new DNS zone" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'DNS Zones', 'url' => route('admin.dns-zones.index')],
        ['label' => 'Add', 'active' => true],
    ]" />
@stop
@section('content')
    @if ($errors->any()) <x-adminlte-alert theme="danger" dismissible><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-adminlte-alert> @endif
    <x-adminlte.partials.form-card icon="bi bi-globe" title="New DNS Zone" :action="route('admin.dns-zones.store')" submit-label="Save Zone" :cancel-url="route('admin.dns-zones.index')">
        <div class="row">
            <div class="col-md-6"><x-adminlte-input name="domain" label="Domain" placeholder="e.g. example.com" value="{{ old('domain') }}" required /></div>
            <div class="col-md-6">
                <x-adminlte-select name="type" label="Type">
                    <option value="master" @selected(old('type', 'master') === 'master')>Master</option>
                    <option value="slave" @selected(old('type') === 'slave')>Slave</option>
                    <option value="forwarder" @selected(old('type') === 'forwarder')>Forwarder</option>
                </x-adminlte-select>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6"><x-adminlte-input name="primary_ns" label="Primary NS" placeholder="ns1.example.com" value="{{ old('primary_ns') }}" /></div>
            <div class="col-md-6"><x-adminlte-input name="admin_email" label="Admin Email" placeholder="admin@example.com" value="{{ old('admin_email') }}" /></div>
        </div>
        <div class="row">
            <div class="col-md-3"><x-adminlte-input name="serial" label="Serial" type="number" value="{{ old('serial', date('Ymd01')) }}" /></div>
            <div class="col-md-3"><x-adminlte-input name="refresh" label="Refresh" type="number" value="{{ old('refresh', 3600) }}" /></div>
            <div class="col-md-3"><x-adminlte-input name="retry" label="Retry" type="number" value="{{ old('retry', 600) }}" /></div>
            <div class="col-md-3"><x-adminlte-input name="expire" label="Expire" type="number" value="{{ old('expire', 86400) }}" /></div>
        </div>
    </x-adminlte.partials.form-card>
@stop
