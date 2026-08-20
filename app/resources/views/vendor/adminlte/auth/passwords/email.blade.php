@extends('adminlte::auth.auth-master', ['authType' => 'login'])

@section('auth_body')
    <header class="auth-head">
        <p class="auth-head__eyebrow">Account Recovery</p>
        <h1 class="auth-head__title">{{ __('adminlte.you_forgot_password') }}</h1>
    </header>

    @if (session('status'))
        <div class="alert alert-success auth-alert">{{ session('status') }}</div>
    @endif

    <form action="{{ route('password.email') }}" method="post">
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

        <button type="submit" class="btn btn-primary auth-submit w-100">{{ __('adminlte.request_new_password') }}</button>
    </form>

    <p class="auth-alt">
        <a href="{{ route('login') }}" class="auth-link">{{ __('adminlte.login') }}</a>
    </p>
@endsection