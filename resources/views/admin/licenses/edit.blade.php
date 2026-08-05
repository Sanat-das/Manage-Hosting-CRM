@extends('adminlte::page')

@section('title', 'Edit License — '.$license->license_type)

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Edit License: {{ $license->license_type }}</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.licenses.index') }}">Licenses</a></li>
                <li class="breadcrumb-item active" aria-current="page">Edit</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-adminlte-alert>
    @endif

    <x-adminlte.partials.form-card icon="bi bi-key" title="Edit License" :action="route('admin.licenses.update', $license)" submit-label="Update License" :cancel-url="route('admin.licenses.show', $license)">
        @method('PUT')
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="license_type" label="License Type" value="{{ old('license_type', $license->license_type) }}" required />
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="vendor" label="Vendor" value="{{ old('vendor', $license->vendor) }}" />
            </div>
        </div>
        <x-adminlte-textarea name="license_key" label="License Key" rows="2" required>{{ old('license_key', $license->license_key) }}</x-adminlte-textarea>
        <div class="row">
            <div class="col-md-4">
                <x-adminlte-input name="seats" label="Seats" type="number" min="1" value="{{ old('seats', $license->seats) }}" />
            </div>
            <div class="col-md-4">
                <x-adminlte-input name="cost" label="Cost" type="number" step="0.01" min="0" value="{{ old('cost', $license->cost) }}" />
            </div>
            <div class="col-md-4">
                <x-adminlte-input name="expiry_date" label="Expiry Date" type="date" value="{{ old('expiry_date', $license->expiry_date?->format('Y-m-d')) }}" />
            </div>
        </div>
        <x-adminlte-select name="status" label="Status">
            @foreach (['active' => 'Active', 'expired' => 'Expired', 'revoked' => 'Revoked'] as $val => $lbl)
                <option value="{{ $val }}" @selected(old('status', $license->status) === $val)>{{ $lbl }}</option>
            @endforeach
        </x-adminlte-select>
        <x-adminlte-textarea name="notes" label="Notes" rows="2">{{ old('notes', $license->notes) }}</x-adminlte-textarea>
    </x-adminlte.partials.form-card>
@stop
