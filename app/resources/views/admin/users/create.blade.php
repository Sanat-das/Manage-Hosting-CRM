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
                <x-adminlte-select name="role" label="Role">
                    @foreach ($roleOptions as $value => $label)
                        <option value="{{ $value }}" @selected(old('role', 'staff') === $value)>{{ $label }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-select name="status" label="Status">
                    @foreach (['active' => 'Active', 'inactive' => 'Inactive', 'suspended' => 'Suspended'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('status', 'active') === $value)>{{ $label }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="address" label="Address" placeholder="Street, city (optional)"
                                  value="{{ old('address') }}" />
            </div>
        </div>
    </x-adminlte.partials.form-card>
@stop
