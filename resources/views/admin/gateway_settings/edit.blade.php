@extends('adminlte::page')

@section('title', 'Edit: ' . $gateway->name)

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Edit {{ $gateway->name }}</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.gateway-settings.index') }}">Gateway Settings</a></li>
                <li class="breadcrumb-item active">{{ $gateway->name }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible>
            <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </x-adminlte-alert>
    @endif

    @php
        $mask = fn (string $value): string => strlen($value) <= 8
            ? str_repeat('•', strlen($value))
            : substr($value, 0, 4).str_repeat('•', 6).substr($value, -4);
    @endphp

    <x-adminlte.partials.form-card icon="bi bi-credit-card" title="Edit: {{ $gateway->name }}"
        :action="route('admin.gateway-settings.update', $gateway)" submit-label="Save Changes"
        :cancel-url="route('admin.gateway-settings.index')">
        @method('PUT')

        <div class="row">
            <div class="col-md-4">
                <x-adminlte-select name="mode" label="Mode">
                    <option value="test" @selected(old('mode', $gateway->mode) === 'test')>Test / Sandbox</option>
                    <option value="live" @selected(old('mode', $gateway->mode) === 'live')>Live</option>
                </x-adminlte-select>
            </div>
            <div class="col-md-4">
                <input type="hidden" name="enabled" value="0">
                <x-adminlte-input-switch name="enabled" label="Enabled" value="1"
                    :checked="(bool) old('enabled', $gateway->enabled)" />
            </div>
            <div class="col-md-4">
                <label class="form-label d-block">Configuration</label>
                <span class="badge text-bg-{{ $gateway->isConfigured() ? 'success' : 'secondary' }} mt-1">
                    {{ $gateway->isConfigured() ? 'Configured' : 'Not configured' }}
                </span>
                @if ($gateway->isOnline() && ! $gateway->isConfigured())
                    <small class="text-muted d-block mt-1">Configure the credentials below to enable this gateway.</small>
                @endif
            </div>
        </div>

        <hr class="my-4">

        <h6 class="fw-bold text-muted text-uppercase mb-3"><i class="bi bi-key me-1"></i> Credentials</h6>

        @switch($gateway->code)
            @case('stripe')
                <div class="row">
                    <div class="col-md-6">
                        <x-adminlte-input name="credentials[secret_key]" label="Secret Key" type="password" value="" />
                        @if ($gateway->getCredential('secret_key'))
                            <small class="text-muted">Current: <code>{{ $mask($gateway->getCredential('secret_key')) }}</code></small>
                        @endif
                    </div>
                    <div class="col-md-6">
                        <x-adminlte-input name="credentials[publishable_key]" label="Publishable Key"
                            value="{{ $gateway->getCredential('publishable_key', '') }}" />
                    </div>
                </div>
                @break

            @case('paypal')
                <div class="row">
                    <div class="col-md-6">
                        <x-adminlte-input name="credentials[client_id]" label="Client ID"
                            value="{{ $gateway->getCredential('client_id', '') }}" />
                    </div>
                    <div class="col-md-6">
                        <x-adminlte-input name="credentials[client_secret]" label="Client Secret" type="password" value="" />
                        @if ($gateway->getCredential('client_secret'))
                            <small class="text-muted">Current: <code>{{ $mask($gateway->getCredential('client_secret')) }}</code></small>
                        @endif
                    </div>
                </div>
                @break

            @case('razorpay')
                <div class="row">
                    <div class="col-md-6">
                        <x-adminlte-input name="credentials[key_id]" label="Key ID"
                            value="{{ $gateway->getCredential('key_id', '') }}" />
                    </div>
                    <div class="col-md-6">
                        <x-adminlte-input name="credentials[key_secret]" label="Key Secret" type="password" value="" />
                        @if ($gateway->getCredential('key_secret'))
                            <small class="text-muted">Current: <code>{{ $mask($gateway->getCredential('key_secret')) }}</code></small>
                        @endif
                    </div>
                </div>
                @break

            @case('bank_transfer')
                <div class="row">
                    <div class="col-md-6">
                        <x-adminlte-input name="credentials[account_name]" label="Account Name"
                            value="{{ $gateway->getCredential('account_name', '') }}" />
                    </div>
                    <div class="col-md-6">
                        <x-adminlte-input name="credentials[account_number]" label="Account Number" type="password" value="" />
                        @if ($gateway->getCredential('account_number'))
                            <small class="text-muted">Current: <code>{{ $mask($gateway->getCredential('account_number')) }}</code></small>
                        @endif
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <x-adminlte-input name="credentials[bank_name]" label="Bank Name"
                            value="{{ $gateway->getCredential('bank_name', '') }}" />
                    </div>
                    <div class="col-md-6">
                        <x-adminlte-input name="credentials[ifsc]" label="IFSC Code"
                            value="{{ $gateway->getCredential('ifsc', '') }}" />
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-12">
                        <x-adminlte-textarea name="credentials[instructions]" label="Instructions">{{ $gateway->getCredential('instructions', '') }}</x-adminlte-textarea>
                    </div>
                </div>
                @break

            @default
                <p class="text-muted mb-0">No credentials are required for this gateway.</p>
        @endswitch
    </x-adminlte.partials.form-card>
@stop
