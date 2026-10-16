@extends('adminlte::auth.auth-master', ['authType' => 'login'])

@section('auth_body')
    <p class="login-box-msg">{{ __('adminlte.recover_password_now') }}</p>

    <form action="{{ route('password.update') }}" method="post">
        @csrf
        <input type="hidden" name="token" value="{{ $token ?? request()->route('token') }}">

        <div class="input-group mb-3">
            <input type="email" name="email" value="{{ old('email', $email ?? '') }}"
                   class="form-control @error('email') is-invalid @enderror"
                   placeholder="{{ __('adminlte.email') }}" required autofocus>
            <div class="input-group-text"><span class="bi bi-envelope"></span></div>
            @error('email')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>

        <div class="input-group mb-3" data-password-field data-password-confirm="password_confirmation">
            <input type="password" name="password" id="password" data-password-input
                   class="form-control @error('password') is-invalid @enderror"
                   placeholder="{{ __('adminlte.password') }}" required>
            <div class="input-group-text"><span class="bi bi-lock-fill"></span></div>
            <x-password-actions />
            @error('password')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>

        <div class="input-group mb-3" data-password-field data-password-confirm="password">
            <input type="password" name="password_confirmation" id="password_confirmation" data-password-input
                   class="form-control" placeholder="{{ __('adminlte.confirm_password') }}" required>
            <div class="input-group-text"><span class="bi bi-lock-fill"></span></div>
            <x-password-actions />
        </div>

        <button type="submit" class="btn btn-primary w-100">{{ __('adminlte.change_password') }}</button>
    </form>
@endsection
