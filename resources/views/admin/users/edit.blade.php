@extends('adminlte::page')

@section('title', 'Edit '.$user->full_name)

@section('content_header')
    <x-ui.page-header title="Edit {{ $user->full_name }}" subtitle="Update staff user details" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Staff Users','url' => route('admin.users.index')],['label' => $user->full_name,'url' => route('admin.users.show', $user)],['label' => 'Edit','active' => true]]" />
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
                <x-ui.phone-input name="phone" label="Phone Number" :value="old('phone', $user->phone)" placeholder="98007 44827" />
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

        <x-adminlte-select name="status" label="Status" :disabled="$user->id === auth()->id()">
            @foreach (['active' => 'Active', 'inactive' => 'Inactive', 'suspended' => 'Suspended'] as $value => $label)
                <option value="{{ $value }}" @selected(old('status', $user->status) === $value)>{{ $label }}</option>
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
                    {{-- autocomplete="off": a staff member is editing ANOTHER user's
                         address, so browser autofill would offer their own. --}}
                    <x-adminlte-input name="address_line1" label="Street address" placeholder="House no., street name, area" autocomplete="off" value="{{ old('address_line1', $user->address_line1) }}" />
                </div>
                <div class="col-md-6">
                    <x-adminlte-input name="address_line2" label="Apartment / Suite (optional)" placeholder="Apartment, suite, landmark" autocomplete="off" value="{{ old('address_line2', $user->address_line2) }}" />
                </div>
            </div>
            <div class="row">
                <div class="col-md-4"><x-adminlte-input name="city" label="City" placeholder="e.g. Mumbai" autocomplete="off" value="{{ old('city', $user->city) }}" /></div>
                <div class="col-md-4"><x-state-field autocomplete="off" :value="$user->state" :country="$user->country" /></div>
                <div class="col-md-4"><x-adminlte-input name="postcode" label="Postcode / ZIP" placeholder="e.g. 400001" autocomplete="off" value="{{ old('postcode', $user->postcode) }}" /></div>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <x-country-select autocomplete="off" :selected="$user->country" />
                </div>
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

