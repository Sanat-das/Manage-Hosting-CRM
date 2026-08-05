@extends('adminlte::page')

@section('title', 'Edit IP — '.$ipAddress->ip_address)

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Edit IP: {{ $ipAddress->ip_address }}</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.ip-addresses.index') }}">IP Addresses</a></li>
                <li class="breadcrumb-item active" aria-current="page">Edit</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-adminlte-alert>
    @endif

    <x-adminlte.partials.form-card icon="bi bi-geo-alt" title="Edit IP" :action="route('admin.ip-addresses.update', $ipAddress)" submit-label="Update IP" :cancel-url="route('admin.ip-addresses.show', $ipAddress)">
        @method('PUT')
        <x-adminlte-input name="ip_address" label="IP Address" value="{{ old('ip_address', $ipAddress->ip_address) }}" required />
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-select name="subnet_id" label="Subnet">
                    <option value="">— None —</option>
                    @foreach ($subnets as $sub)
                        <option value="{{ $sub->id }}" @selected(old('subnet_id', $ipAddress->subnet_id) == $sub->id)>{{ $sub->cidr }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-6">
                <x-adminlte-select name="type" label="Type">
                    @foreach (['private' => 'Private', 'public' => 'Public', 'reserved' => 'Reserved'] as $val => $lbl)
                        <option value="{{ $val }}" @selected(old('type', $ipAddress->type) === $val)>{{ $lbl }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
        </div>
        <x-adminlte-input name="ptr_record" label="PTR Record" value="{{ old('ptr_record', $ipAddress->ptr_record) }}" />
        <x-adminlte-textarea name="notes" label="Notes" rows="2">{{ old('notes', $ipAddress->notes) }}</x-adminlte-textarea>
    </x-adminlte.partials.form-card>
@stop
