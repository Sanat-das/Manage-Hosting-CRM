@extends('adminlte::page')
@section('title', 'Edit DNS Zone — '.$dnsZone->domain)
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">Edit: {{ $dnsZone->domain }}</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item"><a href="{{ route('admin.dns-zones.index') }}">DNS Zones</a></li><li class="breadcrumb-item active">Edit</li></ol></div></div>
@stop
@section('content')
    @if ($errors->any()) <x-adminlte-alert theme="danger" dismissible><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-adminlte-alert> @endif
    <x-adminlte.partials.form-card icon="bi bi-globe" title="Edit DNS Zone" :action="route('admin.dns-zones.update', $dnsZone)" submit-label="Update Zone" :cancel-url="route('admin.dns-zones.show', $dnsZone)">
        @method('PUT')
        <div class="row">
            <div class="col-md-6"><x-adminlte-input name="primary_ns" label="Primary NS" value="{{ old('primary_ns', $dnsZone->primary_ns) }}" /></div>
            <div class="col-md-6"><x-adminlte-input name="admin_email" label="Admin Email" value="{{ old('admin_email', $dnsZone->admin_email) }}" /></div>
        </div>
        <div class="row">
            <div class="col-md-3"><x-adminlte-input name="serial" label="Serial" type="number" value="{{ old('serial', $dnsZone->serial) }}" /></div>
            <div class="col-md-3"><x-adminlte-input name="refresh" label="Refresh" type="number" value="{{ old('refresh', $dnsZone->refresh) }}" /></div>
            <div class="col-md-3"><x-adminlte-input name="retry" label="Retry" type="number" value="{{ old('retry', $dnsZone->retry) }}" /></div>
            <div class="col-md-3"><x-adminlte-input name="expire" label="Expire" type="number" value="{{ old('expire', $dnsZone->expire) }}" /></div>
        </div>
        <x-adminlte-select name="status" label="Status">
            <option value="active" @selected(old('status', $dnsZone->status) === 'active')>Active</option>
            <option value="disabled" @selected(old('status') === 'disabled')>Disabled</option>
        </x-adminlte-select>
    </x-adminlte.partials.form-card>
@stop
