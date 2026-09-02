@extends('adminlte::page')

@section('title', 'Add License')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Add License</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.licenses.index') }}">Licenses</a></li>
                <li class="breadcrumb-item active" aria-current="page">Add</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-adminlte-alert>
    @endif

    <x-adminlte.partials.form-card icon="bi bi-key" title="New License" :action="route('admin.licenses.store')" submit-label="Save License" :cancel-url="route('admin.licenses.index')">
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="license_type" label="License Type" placeholder="e.g. cPanel Solo" value="{{ old('license_type') }}" required />
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="vendor" label="Vendor" placeholder="e.g. cPanel Inc." value="{{ old('vendor') }}" />
            </div>
        </div>
        <x-adminlte-textarea name="license_key" label="License Key" rows="2" required>{{ old('license_key') }}</x-adminlte-textarea>
        <div class="row">
            <div class="col-md-4">
                <x-adminlte-input name="seats" label="Seats" type="number" min="1" value="{{ old('seats', 1) }}" />
            </div>
            <div class="col-md-4">
                <x-adminlte-input name="cost" label="Cost" type="number" step="0.01" min="0" value="{{ old('cost') }}" />
            </div>
            <div class="col-md-4">
                <x-adminlte-input name="expiry_date" label="Expiry Date" type="date" value="{{ old('expiry_date') }}" />
            </div>
        </div>
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="purchase_order" label="Purchase Order" value="{{ old('purchase_order') }}" />
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="renewal_date" label="Renewal Date" type="date" value="{{ old('renewal_date') }}" />
            </div>
        </div>
        <x-adminlte-textarea name="notes" label="Notes" rows="2">{{ old('notes') }}</x-adminlte-textarea>
    </x-adminlte.partials.form-card>
@stop
