@extends('adminlte::auth.auth-master', ['authType' => 'login'])

@section('auth_body')
    <header class="auth-head">
        <p class="auth-head__eyebrow">Account Recovery</p>
        <h1 class="auth-head__title">{{ __('adminlte.recover_password_now') }}</h1>
    </header>

    <form action="{{ route('password.update') }}" method="post">
        @csrf
        <input type="hidden" name="token" value="{{ $token ?? request()->route('token') }}">

        <div class="auth-field">
            <label class="auth-field__label" for="email">{{ __('adminlte.email') }}</label>
            <div class="auth-input">
                <input type="email" name="email" id="email" value="{{ old('email', $email ?? '') }}"
                       class="auth-input__control @error('email') is-invalid @enderror"
                       placeholder="{{ __('adminlte.email') }}" required autofocus>
                <span class="auth-input__icon bi bi-envelope"></span>
            </div>
            @error('email')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>

        <div class="auth-field">
            <label class="auth-field__label" for="password">{{ __('adminlte.password') }}</label>
            <div class="auth-input">
                <input type="password" name="password" id="password"
                       class="auth-input__control @error('password') is-invalid @enderror"
                       placeholder="{{ __('adminlte.password') }}" required>
                <span class="auth-input__icon bi bi-lock-fill"></span>
            </div>
            @error('password')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>

        <div class="auth-field">
            <label class="auth-field__label" for="password_confirmation">{{ __('adminlte.confirm_password') }}</label>
            <div class="auth-input">
                <input type="password" name="password_confirmation" id="password_confirmation"
                       class="auth-input__control" placeholder="{{ __('adminlte.confirm_password') }}" required>
                <span class="auth-input__icon bi bi-lock-fill"></span>
            </div>
        </div>

        <button type="submit" class="btn btn-primary auth-submit w-100">{{ __('adminlte.change_password') }}</button>
    </form>
@endsection