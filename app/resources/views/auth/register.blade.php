@extends('adminlte::auth.auth-master', ['authType' => 'register'])

@section('auth_body')
    <header class="auth-head">
        <p class="auth-head__eyebrow">New Account</p>
        <h1 class="auth-head__title">{{ __('adminlte.register_new_membership') }}</h1>
    </header>

    <form action="{{ route('register') }}" method="post">
        @csrf

        <div class="auth-field-row">
            <div class="auth-field">
                <label class="auth-field__label" for="first_name">{{ __('adminlte.first_name') }}</label>
                <div class="auth-input">
                    <input type="text" name="first_name" id="first_name" value="{{ old('first_name') }}"
                           class="auth-input__control @error('first_name') is-invalid @enderror"
                           placeholder="{{ __('adminlte.first_name') }}" required autofocus>
                    <span class="auth-input__icon bi bi-person"></span>
                </div>
                @error('first_name')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>

            <div class="auth-field">
                <label class="auth-field__label" for="last_name">{{ __('adminlte.last_name') }}</label>
                <div class="auth-input">
                    <input type="text" name="last_name" id="last_name" value="{{ old('last_name') }}"
                           class="auth-input__control @error('last_name') is-invalid @enderror"
                           placeholder="{{ __('adminlte.last_name') }}" required>
                    <span class="auth-input__icon bi bi-person"></span>
                </div>
                @error('last_name')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="auth-field">
            <label class="auth-field__label" for="email">{{ __('adminlte.email') }}</label>
            <div class="auth-input">
                <input type="email" name="email" id="email" value="{{ old('email') }}"
                       class="auth-input__control @error('email') is-invalid @enderror"
                       placeholder="{{ __('adminlte.email') }}" required>
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

        <button type="submit" class="btn btn-primary auth-submit w-100">{{ __('adminlte.register') }}</button>
    </form>

    <p class="auth-alt">
        {{ __('adminlte.i_already_have_membership') }}
        <a href="{{ route('login') }}" class="auth-link">{{ __('adminlte.login') }}</a>
    </p>
@endsection