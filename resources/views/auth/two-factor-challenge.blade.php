@extends('adminlte::auth.auth-master', ['authType' => 'login'])

@section('auth_body')
    <p class="login-box-msg">{{ __('adminlte.two_factor_auth') }}</p>

    @if ($errors->has('code'))
        <div class="alert alert-danger">{{ $errors->first('code') }}</div>
    @endif

    <form action="{{ route('two-factor.login') }}" method="post">
        @csrf

        <div class="input-group mb-3">
            <input type="text" name="code" autocomplete="one-time-code"
                   class="form-control @error('code') is-invalid @enderror"
                   placeholder="{{ __('adminlte.two_factor_code') }}" required autofocus inputmode="numeric">
            <div class="input-group-text"><span class="bi bi-shield-lock"></span></div>
            @error('code')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>

        <button type="submit" class="btn btn-primary w-100">{{ __('adminlte.verify') }}</button>
    </form>

    <form action="{{ route('two-factor.login') }}" method="post" class="mt-3">
        @csrf

        <div class="input-group mb-3">
            <input type="text" name="recovery_code" autocomplete="one-time-code"
                   class="form-control @error('recovery_code') is-invalid @enderror"
                   placeholder="{{ __('adminlte.two_factor_recovery_code') }}">
            <div class="input-group-text"><span class="bi bi-key"></span></div>
            @error('recovery_code')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>

        <button type="submit" class="btn btn-outline-secondary w-100">{{ __('adminlte.recover_with_backup_code') }}</button>
    </form>
@endsection
