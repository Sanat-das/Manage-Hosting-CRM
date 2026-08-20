@extends('adminlte::auth.auth-master', ['authType' => 'login'])

@section('auth_body')
    <header class="auth-head">
        <p class="auth-head__eyebrow">Two-Factor Authentication</p>
        <h1 class="auth-head__title">{{ __('adminlte.two_factor_auth') }}</h1>
    </header>

    @if ($errors->has('code'))
        <div class="alert alert-danger auth-alert">{{ $errors->first('code') }}</div>
    @endif

    <form action="{{ route('two-factor.login') }}" method="post">
        @csrf

        <div class="auth-field">
            <label class="auth-field__label" for="code">{{ __('adminlte.two_factor_code') }}</label>
            <div class="auth-input">
                <input type="text" name="code" id="code" autocomplete="one-time-code"
                       class="auth-input__control @error('code') is-invalid @enderror"
                       placeholder="{{ __('adminlte.two_factor_code') }}" required autofocus inputmode="numeric">
                <span class="auth-input__icon bi bi-shield-lock"></span>
            </div>
            @error('code')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>

        <button type="submit" class="btn btn-primary auth-submit w-100">{{ __('adminlte.verify') }}</button>
    </form>

    <div class="auth-divider">Or use a recovery code</div>

    <form action="{{ route('two-factor.login') }}" method="post">
        @csrf

        <div class="auth-field">
            <label class="auth-field__label" for="recovery_code">{{ __('adminlte.two_factor_recovery_code') }}</label>
            <div class="auth-input">
                <input type="text" name="recovery_code" id="recovery_code" autocomplete="one-time-code"
                       class="auth-input__control @error('recovery_code') is-invalid @enderror"
                       placeholder="{{ __('adminlte.two_factor_recovery_code') }}">
                <span class="auth-input__icon bi bi-key"></span>
            </div>
            @error('recovery_code')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>

        <button type="submit" class="btn btn-outline-secondary auth-submit--ghost w-100">{{ __('adminlte.recover_with_backup_code') }}</button>
    </form>
@endsection