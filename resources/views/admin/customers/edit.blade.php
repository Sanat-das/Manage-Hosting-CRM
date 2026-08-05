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
                <x-adminlte-input name="phone" label="Phone" placeholder="+91 98765 43210"
                                  value="{{ old('phone', $customer->user?->phone) }}" />
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

        <x-adminlte-textarea name="address" label="Address" rows="2"
                             placeholder="Billing address (optional)">{{ old('address', $customer->user?->address) }}</x-adminlte-textarea>

        <x-adminlte-select name="status" label="Status">
            @foreach (['active' => 'Active', 'inactive' => 'Inactive', 'suspended' => 'Suspended'] as $value => $label)
                <option value="{{ $value }}" @selected(old('status', $customer->status) === $value)>{{ $label }}</option>
            @endforeach
        </x-adminlte-select>
    </x-adminlte.partials.form-card>
@stop
