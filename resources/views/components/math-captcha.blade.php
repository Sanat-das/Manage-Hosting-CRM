{{-- Math Captcha — shown only when security_math_captcha_enabled is true --}}
@if(\App\Support\AppSettings::bool('security_math_captcha_enabled', false))
    @php
        // Lazily ensure a question exists in session for this request.
        // Uses current request instance; no controller change needed.
        if (! session()->has('math_captcha_question') || ! session()->has('math_captcha_answer')) {
            \App\Support\MathCaptcha::generate(request());
        }
        $captchaQuestion = session('math_captcha_question');
    @endphp
    <div class="mb-3">
        <label for="math_captcha" class="form-label">
            Security check: <strong>{{ $captchaQuestion }}</strong>
        </label>
        <div class="input-group">
            <input
                type="text"
                name="math_captcha"
                id="math_captcha"
                value="{{ old('math_captcha') }}"
                class="form-control @error('math_captcha') is-invalid @enderror"
                placeholder="Your answer"
                inputmode="numeric"
                autocomplete="off"
                required
                aria-label="Math captcha answer"
            >
            <div class="input-group-text"><span class="bi bi-shield-check"></span></div>
            @error('math_captcha')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>
    </div>
@endif
