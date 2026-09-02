@extends('adminlte::page')

@section('title', 'GST Settings')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">GST Settings</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active">GST Settings</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif
    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible>
            <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </x-adminlte-alert>
    @endif

    <form method="POST" action="{{ route('admin.gst-settings.update') }}">
        @csrf @method('PUT')

        <x-adminlte-card icon="bi bi-percent" title="Company GST Details">
            <div class="row">
                <div class="col-md-4">
                    <x-adminlte-input name="gstin" label="GSTIN" value="{{ old('gstin', $gst->gstin) }}" required placeholder="22AAAAA0000A1Z5" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="legal_name" label="Legal Name" value="{{ old('legal_name', $gst->legal_name) }}" required />
                </div>
                <div class="col-md-4">
                    <x-adminlte-select name="enabled" label="GST Enabled">
                        <option value="1" @selected(old('enabled', $gst->enabled))>Enabled</option>
                        <option value="0" @selected(!old('enabled', $gst->enabled))>Disabled</option>
                    </x-adminlte-select>
                </div>
            </div>
            <div class="row">
                <div class="col-md-3">
                    <x-adminlte-input name="state_code" label="State Code" value="{{ old('state_code', $gst->state_code) }}" required placeholder="27" />
                </div>
                <div class="col-md-3">
                    <x-adminlte-input name="state_name" label="State Name" value="{{ old('state_name', $gst->state_name) }}" required />
                </div>
                <div class="col-md-3">
                    <x-adminlte-input name="hsn_code" label="HSN Code" value="{{ old('hsn_code', $gst->hsn_code) }}" />
                </div>
                <div class="col-md-3">
                    <x-adminlte-input name="sac_code" label="SAC Code" value="{{ old('sac_code', $gst->sac_code) }}" />
                </div>
            </div>
        </x-adminlte-card>

        <x-adminlte-card icon="bi bi-calculator" title="Tax Rates">
            <div class="row">
                <div class="col-md-3">
                    <x-adminlte-input name="cgst_rate" type="number" step="0.01" min="0" max="100" label="CGST Rate (%)"
                        value="{{ old('cgst_rate', $gst->cgst_rate) }}" required />
                </div>
                <div class="col-md-3">
                    <x-adminlte-input name="sgst_rate" type="number" step="0.01" min="0" max="100" label="SGST Rate (%)"
                        value="{{ old('sgst_rate', $gst->sgst_rate) }}" required />
                </div>
                <div class="col-md-3">
                    <x-adminlte-input name="igst_rate" type="number" step="0.01" min="0" max="100" label="IGST Rate (%)"
                        value="{{ old('igst_rate', $gst->igst_rate) }}" required />
                </div>
                <div class="col-md-3">
                    <x-adminlte-select name="tax_mode" label="Tax Mode">
                        <option value="global" @selected(old('tax_mode', $gst->tax_mode) === 'global')>Global</option>
                        <option value="per_product" @selected(old('tax_mode', $gst->tax_mode) === 'per_product')>Per Product</option>
                        <option value="mixed" @selected(old('tax_mode', $gst->tax_mode) === 'mixed')>Mixed</option>
                    </x-adminlte-select>
                </div>
            </div>
        </x-adminlte-card>

        <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-check-lg me-1"></i> Save GST Settings</button>
    </form>
@stop
