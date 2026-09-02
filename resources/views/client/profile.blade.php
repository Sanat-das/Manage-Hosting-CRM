@extends('adminlte::page')

@section('title', 'My Profile')

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">My Profile</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('client.dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item active" aria-current="page">Profile</li>
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
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-adminlte-alert>
    @endif

    <x-adminlte-card icon="bi bi-person-circle" title="Profile Details">
        <form method="POST" action="{{ route('client.profile.update') }}">
            @csrf
            @method('PUT')

            <div class="row">
                <div class="col-md-6">
                    <x-adminlte-input name="first_name" label="First name" value="{{ old('first_name', $user->first_name) }}" required />
                </div>
                <div class="col-md-6">
                    <x-adminlte-input name="last_name" label="Last name" value="{{ old('last_name', $user->last_name) }}" required />
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <x-adminlte-input name="email" type="email" label="Email address" value="{{ old('email', $user->email) }}" required />
                </div>
                <div class="col-md-6">
                    <x-adminlte-input name="phone" label="Phone" value="{{ old('phone', $user->phone) }}" />
                </div>
            </div>

            <x-adminlte-input name="company" label="Company" value="{{ old('company', $user->company) }}" />

            <x-adminlte-textarea name="address" label="Address" rows="2">{{ old('address', $user->address) }}</x-adminlte-textarea>

            <div class="row">
                <div class="col-md-6">
                    <x-adminlte-input name="password" type="password" label="New password (optional)"
                                      placeholder="Leave blank to keep current" />
                </div>
                <div class="col-md-6">
                    <x-adminlte-input name="password_confirmation" type="password" label="Confirm new password" />
                </div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-lg me-1" aria-hidden="true"></i> Save Changes
            </button>
        </form>
    </x-adminlte-card>

    <x-adminlte-card icon="bi bi-megaphone" title="Marketing Preferences">
        <form method="POST" action="{{ route('client.profile.consent') }}">
            @csrf
            <input type="hidden" name="contact_type" value="marketing_email">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="consent" value="1"
                       id="marketing-consent" {{ $marketingConsent ? 'checked' : '' }}
                       onchange="this.form.submit()">
                <label class="form-check-label" for="marketing-consent">
                    Receive marketing emails about offers and product updates
                </label>
            </div>
        </form>
    </x-adminlte-card>

    {{-- Two-Factor Authentication management --}}
    @include('auth.two-factor-manage')
@stop
