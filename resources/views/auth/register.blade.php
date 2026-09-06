@extends('adminlte::auth.auth-master', ['authType' => 'register'])

@section('auth_body')
    <p class="login-box-msg">{{ __('adminlte.register_new_membership') }}</p>

    <form action="{{ route('register') }}" method="post">
        @csrf
        {{-- Honeypot trap — must stay empty; bots filling website will be rejected via filled("website") check in controller --}}
        <div style="position: absolute; left: -9999px;" aria-hidden="true">
            <label for="website" class="visually-hidden">Leave this field empty</label>
            <input type="text" name="website" id="website" tabindex="-1" autocomplete="off" aria-label="Leave this field empty" value="">
        </div>

        <div class="row">
            <div class="col-6">
                <div class="input-group mb-3">
                    <input type="text" name="first_name" value="{{ old('first_name') }}"
                           class="form-control @error('first_name') is-invalid @enderror"
                           placeholder="{{ __('adminlte.first_name') }}" required autofocus
                           autocomplete="given-name" aria-label="{{ __('adminlte.first_name') }}">
                    <div class="input-group-text"><span class="bi bi-person"></span></div>
                    @error('first_name')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="col-6">
                <div class="input-group mb-3">
                    <input type="text" name="last_name" value="{{ old('last_name') }}"
                           class="form-control @error('last_name') is-invalid @enderror"
                           placeholder="{{ __('adminlte.last_name') }}" required
                           autocomplete="family-name" aria-label="{{ __('adminlte.last_name') }}">
                    <div class="input-group-text"><span class="bi bi-person"></span></div>
                    @error('last_name')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>

        <div class="input-group mb-3">
            <input type="email" name="email" value="{{ old('email') }}"
                   class="form-control @error('email') is-invalid @enderror"
                   placeholder="{{ __('adminlte.email') }}" required
                   autocomplete="email" aria-label="{{ __('adminlte.email') }}">
            <div class="input-group-text"><span class="bi bi-envelope"></span></div>
            @error('email')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>

        <x-ui.phone-input name="phone" label="Phone Number" :value="old('phone')" placeholder="98007 44827" />

        <div class="input-group mb-3">
            <input type="password" name="password"
                   class="form-control @error('password') is-invalid @enderror"
                   placeholder="{{ __('adminlte.password') }}" required
                   autocomplete="new-password" aria-label="{{ __('adminlte.password') }}">
            <div class="input-group-text"><span class="bi bi-lock-fill"></span></div>
            @error('password')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>

        <div class="input-group mb-3">
            <input type="password" name="password_confirmation"
                   class="form-control" placeholder="{{ __('adminlte.confirm_password') }}" required
                   autocomplete="new-password" aria-label="{{ __('adminlte.confirm_password') }}">
            <div class="input-group-text"><span class="bi bi-lock-fill"></span></div>
        </div>

        <div class="border rounded p-3 mb-3 bg-light">
            <div class="d-flex align-items-center gap-2 mb-2">
                <i class="bi bi-geo-alt text-primary"></i>
                <small class="fw-semibold text-muted">Billing Address <span class="fw-normal">(optional — for invoices)</span></small>
            </div>
            <div class="mb-2">
                <input type="text" name="address_line1" value="{{ old('address_line1') }}"
                       class="form-control form-control-sm @error('address_line1') is-invalid @enderror"
                       placeholder="Street address — House no., street, area" autocomplete="street-address">
                @error('address_line1')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
            <div class="mb-2">
                <input type="text" name="address_line2" value="{{ old('address_line2') }}"
                       class="form-control form-control-sm @error('address_line2') is-invalid @enderror"
                       placeholder="Apartment, suite, landmark (optional)" autocomplete="address-line2">
                @error('address_line2')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
            <div class="row g-2 mb-2">
                <div class="col-6">
                    <input type="text" name="city" value="{{ old('city') }}"
                           class="form-control form-control-sm @error('city') is-invalid @enderror"
                           placeholder="City" autocomplete="address-level2">
                    @error('city')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
                <div class="col-6">
                    <input type="text" name="state" value="{{ old('state') }}"
                           class="form-control form-control-sm @error('state') is-invalid @enderror"
                           placeholder="State / Province" autocomplete="address-level1">
                    @error('state')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="row g-2">
                <div class="col-6">
                    <input type="text" name="postcode" value="{{ old('postcode') }}"
                           class="form-control form-control-sm @error('postcode') is-invalid @enderror"
                           placeholder="Postcode / ZIP" autocomplete="postal-code">
                    @error('postcode')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
                <div class="col-6">
                    <select name="country" class="form-select form-select-sm @error('country') is-invalid @enderror">
                        @php $countries = ['India','United States','United Kingdom','Canada','Australia','Singapore','United Arab Emirates','Germany','France','Other']; @endphp
                        @foreach ($countries as $c)
                            <option value="{{ $c }}" @selected(old('country','India') === $c)>{{ $c }}</option>
                        @endforeach
                    </select>
                    @error('country')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>

        @include('components.math-captcha')

        <button type="submit" class="btn btn-primary w-100">{{ __('adminlte.register') }}</button>
    </form>

    <p class="mb-0 mt-3">
        <a href="{{ route('login') }}" class="text-center">{{ __('adminlte.i_already_have_membership') }}</a>
    </p>
@endsection
