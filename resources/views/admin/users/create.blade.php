@extends('adminlte::page')

@section('title', 'Add Staff User')

@section('content_header')
    <x-ui.page-header title="Add Staff User" subtitle="Add a new staff user" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Staff Users','url' => route('admin.users.index')],['label' => 'Add Staff User','active' => true]]" />
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
                    {{-- autocomplete="off": a staff member is entering ANOTHER user's
                         address, so browser autofill would offer their own. --}}
                    <x-adminlte-input name="address_line1" label="Street address" placeholder="House no., street name, area" autocomplete="off" value="{{ old('address_line1') }}" />
                </div>
                <div class="col-md-6">
                    <x-adminlte-input name="address_line2" label="Apartment / Suite (optional)" placeholder="Apartment, suite, landmark" autocomplete="off" value="{{ old('address_line2') }}" />
                </div>
            </div>
            <div class="row">
                <div class="col-md-4"><x-adminlte-input name="city" label="City" placeholder="e.g. Mumbai" autocomplete="off" value="{{ old('city') }}" /></div>
                <div class="col-md-4"><x-state-field autocomplete="off" :value="old('state')" :country="old('country')" /></div>
                <div class="col-md-4"><x-adminlte-input name="postcode" label="Postcode / ZIP" placeholder="e.g. 400001" autocomplete="off" value="{{ old('postcode') }}" /></div>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <x-country-select autocomplete="off" :selected="old('country')" />
                </div>
            </div>
        </div>
    </x-adminlte.partials.form-card>
@stop

