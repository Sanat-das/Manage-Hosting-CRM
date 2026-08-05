@extends('adminlte::auth.auth-master', ['authType' => 'register'])

@section('auth_body')
    <p class="login-box-msg">{{ __('adminlte.register_new_membership') }}</p>

    <form action="{{ route('register') }}" method="post">
        @csrf

        <div class="row">
            <div class="col-6">
                <div class="input-group mb-3">
                    <input type="text" name="first_name" value="{{ old('first_name') }}"
                           class="form-control @error('first_name') is-invalid @enderror"
                           placeholder="{{ __('adminlte.first_name') }}" required autofocus>
                    <div class="input-group-text"><span class="bi bi-person"></span></div>
                    @error('first_name')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="col-6">
                <div class="input-group mb-3">
                    <input type="text" name="last_name" value="{{ old('last_name') }}"
                           class="form-control @error('last_name') is-invalid @enderror"
                           placeholder="{{ __('adminlte.last_name') }}" required>
                    <div class="input-group-text"><span class="bi bi-person"></span></div>
                    @error('last_name')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>

        <div class="input-group mb-3">
            <input type="email" name="email" value="{{ old('email') }}"
                   class="form-control @error('email') is-invalid @enderror"
                   placeholder="{{ __('adminlte.email') }}" required>
            <div class="input-group-text"><span class="bi bi-envelope"></span></div>
            @error('email')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>

        <div class="input-group mb-3">
            <input type="password" name="password"
                   class="form-control @error('password') is-invalid @enderror"
                   placeholder="{{ __('adminlte.password') }}" required>
            <div class="input-group-text"><span class="bi bi-lock-fill"></span></div>
            @error('password')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>

        <div class="input-group mb-3">
            <input type="password" name="password_confirmation"
                   class="form-control" placeholder="{{ __('adminlte.confirm_password') }}" required>
            <div class="input-group-text"><span class="bi bi-lock-fill"></span></div>
        </div>

        <button type="submit" class="btn btn-primary w-100">{{ __('adminlte.register') }}</button>
    </form>

    <p class="mb-0 mt-3">
        <a href="{{ route('login') }}" class="text-center">{{ __('adminlte.i_already_have_membership') }}</a>
    </p>
@endsection
