@extends('adminlte::page')

@section('title', 'Edit '.$customer->full_name)

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Edit {{ $customer->display_id }}</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.customers.index') }}">Customers</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.customers.show', $customer) }}">{{ $customer->display_id }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">Edit</li>
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
        icon="bi bi-pencil-square"
        title="Edit Customer"
        :action="route('admin.customers.update', $customer)"
        method="PUT"
        submit-label="Save Changes"
        :cancel-url="route('admin.customers.show', $customer)"
    >
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="first_name" label="First name" placeholder="First name"
                                  value="{{ old('first_name', $customer->user?->first_name) }}" required />
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="last_name" label="Last name" placeholder="Last name"
                                  value="{{ old('last_name', $customer->user?->last_name) }}" required />
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="email" type="email" label="Email address" placeholder="name@example.com"
                                  value="{{ old('email', $customer->user?->email) }}" required />
            </div>
            <div class="col-md-6">
                <x-ui.phone-input name="phone" label="Phone Number" :value="old('phone', $customer->user?->phone)" placeholder="98007 44827" />
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="company" label="Company" placeholder="Company name"
                                  value="{{ old('company', $customer->company) }}" />
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="tax_id" label="Tax ID / GSTIN" placeholder="GSTIN (optional)"
                                  value="{{ old('tax_id', $customer->tax_id) }}" />
            </div>
        </div>

        <div class="border rounded p-3 mb-3 bg-light-subtle">
            <div class="d-flex align-items-center gap-2 mb-3">
                <i class="bi bi-geo-alt text-primary"></i>
                <h6 class="mb-0 fw-semibold">Billing Address</h6>
                <span class="text-muted small ms-1">— standard e-commerce fields, shown on invoices</span>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <x-adminlte-input name="address_line1" label="Street address" placeholder="House no., street name, area"
                                      value="{{ old('address_line1', $customer->user?->address_line1) }}" />
                </div>
                <div class="col-md-6">
                    <x-adminlte-input name="address_line2" label="Apartment / Suite (optional)" placeholder="Apartment, suite, floor, landmark"
                                      value="{{ old('address_line2', $customer->user?->address_line2) }}" />
                </div>
            </div>

            <div class="row">
                <div class="col-md-4">
                    <x-adminlte-input name="city" label="City" placeholder="e.g. Mumbai"
                                      value="{{ old('city', $customer->user?->city) }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="state" label="State / Province" placeholder="e.g. Maharashtra"
                                      value="{{ old('state', $customer->user?->state) }}" />
                </div>
                <div class="col-md-4">
                    <x-adminlte-input name="postcode" label="Postcode / ZIP" placeholder="e.g. 400001"
                                      value="{{ old('postcode', $customer->user?->postcode) }}" />
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <x-adminlte-select name="country" label="Country">
                        @php $countries = ['India','United States','United Kingdom','Canada','Australia','Singapore','United Arab Emirates','Germany','France','Other']; @endphp
                        @foreach ($countries as $c)
                            <option value="{{ $c }}" @selected(old('country', $customer->user?->country ?? 'India') === $c)>{{ $c }}</option>
                        @endforeach
                    </x-adminlte-select>
                </div>
                <div class="col-md-6 d-flex align-items-end">
                    <div class="form-text mb-3 w-100">State drives GST (CGST/SGST vs IGST). Postcode validates shipping/tax.</div>
                </div>
            </div>
        </div>

        <x-adminlte-select name="status" label="Status">
            @foreach (['active' => 'Active', 'inactive' => 'Inactive', 'suspended' => 'Suspended'] as $value => $label)
                <option value="{{ $value }}" @selected(old('status', $customer->status) === $value)>{{ $label }}</option>
            @endforeach
        </x-adminlte-select>
    </x-adminlte.partials.form-card>
@stop
