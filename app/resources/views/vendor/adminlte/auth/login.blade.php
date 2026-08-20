@extends('adminlte::auth.auth-master', ['authType' => 'login'])

@section('auth_body')
    <header class="auth-head">
        <p class="auth-head__eyebrow">Staff Access</p>
        <h1 class="auth-head__title">{{ __('adminlte.sign_in_to_start_session') }}</h1>
    </header>

    <form action="{{ route('login') }}" method="post">
        @csrf

        <div class="auth-field">
            <label class="auth-field__label" for="email">{{ __('adminlte.email') }}</label>
            <div class="auth-input">
                <input type="email" name="email" id="email" value="{{ old('email') }}"
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

        <div class="auth-options">
            <label class="auth-check">
                <input type="checkbox" name="remember" class="form-check-input" id="remember">
                <span>{{ __('adminlte.remember_me') }}</span>
            </label>
            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}" class="auth-link">{{ __('adminlte.i_forgot_my_password') }}</a>
            @endif
        </div>

        <button type="submit" class="btn btn-primary auth-submit w-100">{{ __('adminlte.sign_in') }}</button>
    </form>

    @if (Route::has('register'))
        <p class="auth-alt">
            <a href="{{ route('register') }}" class="auth-link">{{ __('adminlte.register_new_membership') }}</a>
        </p>
    @endif
@endsection