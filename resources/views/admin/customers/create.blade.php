@extends('adminlte::page')

@section('title', 'Add Customer')

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Add Customer</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.customers.index') }}">Customers</a></li>
                <li class="breadcrumb-item active" aria-current="page">Add Customer</li>
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
        icon="bi bi-person-plus"
        title="New Customer"
        :action="route('admin.customers.store')"
        submit-label="Save Customer"
        :cancel-url="route('admin.customers.index')"
    >
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="first_name" label="First name" placeholder="First name"
                                  value="{{ old('first_name') }}" required />
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="last_name" label="Last name" placeholder="Last name"
                                  value="{{ old('last_name') }}" required />
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="email" type="email" label="Email address" placeholder="name@example.com"
                                   value="{{ old('email') }}" required>
                    <span class="form-text">Used for login and invoice delivery.</span>
                </x-adminlte-input>
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="password" type="password" label="Password" placeholder="Min 8 chars, A-Z, a-z, 0-9"
                                   required>
                    <span class="form-text">Min 8 characters, include upper, lower &amp; number.</span>
                </x-adminlte-input>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="password_confirmation" type="password" label="Confirm password" required />
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="phone" label="Phone" placeholder="+91 98765 43210"
                                  value="{{ old('phone') }}" />
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="company" label="Company" placeholder="Company name"
                                  value="{{ old('company') }}" />
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="tax_id" label="Tax ID / GSTIN" placeholder="GSTIN (optional)"
                                  value="{{ old('tax_id') }}" />
            </div>
        </div>

        <x-adminlte-textarea name="address" label="Address" rows="2"
                              placeholder="Billing address (optional)">{{ old('address') }}</x-adminlte-textarea>
        <div class="form-text mt-n2 mb-3">Optional — shown on invoices if provided.</div>

        <x-adminlte-select name="status" label="Status">
            @foreach (['active' => 'Active', 'inactive' => 'Inactive', 'suspended' => 'Suspended'] as $value => $label)
                <option value="{{ $value }}" @selected(old('status', 'active') === $value)>{{ $label }}</option>
            @endforeach
        </x-adminlte-select>
    </x-adminlte.partials.form-card>
@stop
