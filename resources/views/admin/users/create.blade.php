@extends('adminlte::page')

@section('title', 'Add Staff User')

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Add Staff User</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.users.index') }}">Staff Users</a></li>
                <li class="breadcrumb-item active" aria-current="page">Add Staff User</li>
            </ol>
        </div>
    </div>
@stop

@php
    $roleOptions = [
        'admin' => 'Administrator',
        'staff' => 'Staff',
        'support' => 'Support',
        'sales' => 'Sales',
        'marketing' => 'Marketing',
    ];
@endphp

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
        title="New Staff Account"
        :action="route('admin.users.store')"
        submit-label="Save Staff User"
        :cancel-url="route('admin.users.index')"
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
                                  value="{{ old('email') }}" required />
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="password" type="password" label="Password" placeholder="Min 8 chars, A-Z, a-z, 0-9"
                                  required />
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="password_confirmation" type="password" label="Confirm password" required />
            </div>
            <div class="col-md-6">
                <x-ui.phone-input name="phone" label="Phone Number" :value="old('phone')" placeholder="98007 44827" />
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="company" label="Company" placeholder="Company name"
                                  value="{{ old('company') }}" />
            </div>
            <div class="col-md-6">
                <x-adminlte-select name="role" label="Role">
                    @foreach ($roleOptions as $value => $label)
                        <option value="{{ $value }}" @selected(old('role', 'staff') === $value)>{{ $label }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
        </div>

        <x-adminlte-select name="status" label="Status">
            @foreach (['active' => 'Active', 'inactive' => 'Inactive', 'suspended' => 'Suspended'] as $value => $label)
                <option value="{{ $value }}" @selected(old('status', 'active') === $value)>{{ $label }}</option>
            @endforeach
        </x-adminlte-select>

        <div class="border rounded p-3 mb-3 bg-light-subtle">
            <div class="d-flex align-items-center gap-2 mb-3">
                <i class="bi bi-geo-alt text-primary"></i>
                <h6 class="mb-0 fw-semibold">Address</h6>
                <span class="text-muted small ms-1">— standard e-commerce fields</span>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <x-adminlte-input name="address_line1" label="Street address" placeholder="House no., street name, area" value="{{ old('address_line1') }}" />
                </div>
                <div class="col-md-6">
                    <x-adminlte-input name="address_line2" label="Apartment / Suite (optional)" placeholder="Apartment, suite, landmark" value="{{ old('address_line2') }}" />
                </div>
            </div>
            <div class="row">
                <div class="col-md-4"><x-adminlte-input name="city" label="City" placeholder="e.g. Mumbai" value="{{ old('city') }}" /></div>
                <div class="col-md-4"><x-adminlte-input name="state" label="State / Province" placeholder="e.g. Maharashtra" value="{{ old('state') }}" /></div>
                <div class="col-md-4"><x-adminlte-input name="postcode" label="Postcode / ZIP" placeholder="e.g. 400001" value="{{ old('postcode') }}" /></div>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <x-adminlte-select name="country" label="Country">
                        @php $countries = ['India','United States','United Kingdom','Canada','Australia','Singapore','United Arab Emirates','Germany','France','Other']; @endphp
                        @foreach ($countries as $c)
                            <option value="{{ $c }}" @selected(old('country','India') === $c)>{{ $c }}</option>
                        @endforeach
                    </x-adminlte-select>
                </div>
            </div>
        </div>
    </x-adminlte.partials.form-card>
@stop
