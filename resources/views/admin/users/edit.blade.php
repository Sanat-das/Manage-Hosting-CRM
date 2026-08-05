@extends('adminlte::page')

@section('title', 'Edit '.$user->full_name)

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Edit {{ $user->full_name }}</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.users.index') }}">Staff Users</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.users.show', $user) }}">{{ $user->full_name }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">Edit</li>
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
        icon="bi bi-pencil-square"
        title="Edit Staff Account"
        :action="route('admin.users.update', $user)"
        method="PUT"
        submit-label="Save Changes"
        :cancel-url="route('admin.users.show', $user)"
    >
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="first_name" label="First name" placeholder="First name"
                                  value="{{ old('first_name', $user->first_name) }}" required />
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="last_name" label="Last name" placeholder="Last name"
                                  value="{{ old('last_name', $user->last_name) }}" required />
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="email" type="email" label="Email address" placeholder="name@example.com"
                                  value="{{ old('email', $user->email) }}" required />
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="phone" label="Phone" placeholder="+91 98765 43210"
                                  value="{{ old('phone', $user->phone) }}" />
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="company" label="Company" placeholder="Company name"
                                  value="{{ old('company', $user->company) }}" />
            </div>
            <div class="col-md-6">
                <x-adminlte-select name="role" label="Role" :disabled="$user->id === auth()->id()">
                    @foreach ($roleOptions as $value => $label)
                        <option value="{{ $value }}" @selected(old('role', $user->role) === $value)>{{ $label }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-select name="status" label="Status" :disabled="$user->id === auth()->id()">
                    @foreach (['active' => 'Active', 'inactive' => 'Inactive', 'suspended' => 'Suspended'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('status', $user->status) === $value)>{{ $label }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="address" label="Address" placeholder="Street, city (optional)"
                                  value="{{ old('address', $user->address) }}" />
            </div>
        </div>

        <p class="text-muted small mb-0 mt-2">
            <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
            Passwords are reset separately from the account detail page, not here.
            @if ($user->id === auth()->id())
                You cannot change your own role or status.
            @endif
        </p>
    </x-adminlte.partials.form-card>
@stop
