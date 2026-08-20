@extends('adminlte::auth.auth-master', ['authType' => 'login'])

@section('auth_body')
    <header class="auth-head">
        <p class="auth-head__eyebrow">Security Check</p>
        <h1 class="auth-head__title">{{ __('adminlte.confirm_password_message') }}</h1>
    </header>

    <form action="{{ route('password.confirm') }}" method="post">
        @csrf

        <div class="auth-field">
            <label class="auth-field__label" for="password">{{ __('adminlte.password') }}</label>
            <div class="auth-input">
                <input type="password" name="password" id="password"
                       class="auth-input__control @error('password') is-invalid @enderror"
                       placeholder="{{ __('adminlte.password') }}" required autofocus autocomplete="current-password">
                <span class="auth-input__icon bi bi-lock-fill"></span>
            </div>
            @error('password')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>

        <button type="submit" class="btn btn-primary auth-submit w-100">{{ __('adminlte.confirm') }}</button>
    </form>
@endsection