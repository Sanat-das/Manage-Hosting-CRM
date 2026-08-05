@extends('adminlte::page')

@section('title', 'Add SSL Certificate')

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Add SSL Certificate</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.ssl.index') }}">SSL Certificates</a></li>
                <li class="breadcrumb-item active" aria-current="page">Add Certificate</li>
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
        icon="bi bi-shield-plus"
        title="New SSL Certificate"
        :action="route('admin.ssl.store')"
        submit-label="Save Certificate"
        :cancel-url="route('admin.ssl.index')"
    >
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-select name="customer_id" label="Customer" required>
                    @foreach ($customers as $id => $name)
                        <option value="{{ $id }}" @selected((int) old('customer_id') === $id)>{{ $name }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="domain_name" label="Domain name" placeholder="example.com"
                                  value="{{ old('domain_name') }}" required />
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-select name="certificate_type" label="Certificate type" required>
                    @foreach (['single' => 'Single', 'wildcard' => 'Wildcard', 'multidomain' => 'Multi-Domain'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('certificate_type', 'single') === $value)>{{ $label }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="provider" label="Provider" placeholder="e.g. Let's Encrypt, Sectigo"
                                  value="{{ old('provider') }}" />
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="issue_date" type="date" label="Issue date"
                                  value="{{ old('issue_date') }}" />
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="expiry_date" type="date" label="Expiry date"
                                  value="{{ old('expiry_date') }}" />
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-select name="status" label="Status">
                    @foreach (['active' => 'Active', 'pending' => 'Pending', 'expired' => 'Expired', 'revoked' => 'Revoked', 'failed' => 'Failed'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('status', 'pending') === $value)>{{ $label }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="order_id" type="number" label="Order ID (optional)" min="1"
                                  value="{{ old('order_id') }}" />
            </div>
        </div>

        <x-adminlte-textarea name="notes" label="Notes" rows="3"
                             placeholder="Internal notes (optional)">{{ old('notes') }}</x-adminlte-textarea>
    </x-adminlte.partials.form-card>
@stop
