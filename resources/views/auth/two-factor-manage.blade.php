@php
    $user = auth()->user();
    $twoFactorEnabled = ! is_null($user->two_factor_secret);
    $twoFactorConfirmed = ! is_null($user->two_factor_confirmed_at);
@endphp

<x-adminlte-card icon="bi bi-shield-lock" title="Two-Factor Authentication (2FA)">
    @if (! $twoFactorEnabled)
        {{-- 2FA disabled: offer to enable --}}
        <p>
            Two-factor authentication adds an extra layer of security to your account.
            When enabled, you will need your password <em>and</em> a one-time code from
            your authenticator app to sign in.
        </p>

        <form method="POST" action="{{ route('two-factor.enable') }}">
            @csrf
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-shield-check me-1" aria-hidden="true"></i> Enable 2FA
            </button>
        </form>
    @elseif (! $twoFactorConfirmed)
        {{-- Enabled but not yet confirmed: show QR code + secret + confirm form --}}
        <p>
            Scan the QR code below with your authenticator app (e.g. Google Authenticator,
            Authy, 1Password), then enter the 6-digit code to confirm setup.
        </p>

        <div class="text-center my-3" id="two-factor-qr" aria-live="polite">
            <div class="text-muted small">Loading QR code…</div>
        </div>

        <div class="alert alert-secondary small" id="two-factor-secret" aria-live="polite">
            <strong>Manual entry key:</strong> <span class="text-muted">Loading…</span>
        </div>

        <form method="POST" action="{{ route('two-factor.confirm') }}">
            @csrf
            <div class="input-group mb-3">
                <input type="text" name="code" class="form-control @error('code', 'confirmTwoFactorAuthentication') is-invalid @enderror"
                       placeholder="6-digit code" inputmode="numeric" maxlength="6" required autocomplete="one-time-code">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-check-lg me-1" aria-hidden="true"></i> Confirm
                </button>
                @error('code', 'confirmTwoFactorAuthentication')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
        </form>
    @else
        {{-- Enabled and confirmed --}}
        <p>
            Two-factor authentication is <strong class="text-success">enabled</strong> on
            your account. Use your authenticator app to generate the code required at sign in.
        </p>

        <h6 class="mt-3">
            <i class="bi bi-key me-1" aria-hidden="true"></i> Recovery codes
        </h6>
        <p class="small text-muted">
            Recovery codes can be used once each to sign in when you lose access to your
            authenticator app. Store them somewhere safe.
        </p>
        <div id="two-factor-recovery-codes" class="mb-3" aria-live="polite">
            <div class="text-muted small">Loading recovery codes…</div>
        </div>

        <form method="POST" action="{{ route('two-factor.regenerate-recovery-codes') }}" class="d-inline">
            @csrf
            <button type="submit" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i> Regenerate codes
            </button>
        </form>

        <button type="button" class="btn btn-outline-danger"
                data-bs-toggle="modal" data-bs-target="#disable-2fa-modal">
            <i class="bi bi-shield-x me-1" aria-hidden="true"></i> Disable 2FA
        </button>
    @endif
</x-adminlte-card>

@if ($enabled ?? true)
    <x-adminlte.partials.confirm-modal
        id="disable-2fa-modal"
        title="Disable two-factor authentication"
        message="Disable two-factor authentication for your account?"
        :action="route('two-factor.disable')"
        confirm-label="Disable 2FA"
    />
@endif

@once
@push('js')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const qrEl = document.getElementById('two-factor-qr');
        const secretEl = document.getElementById('two-factor-secret');
        const codesEl = document.getElementById('two-factor-recovery-codes');

        if (qrEl && secretEl) {
            fetch('{{ route('two-factor.qr-code') }}', {headers: {Accept: 'application/json'}})
                .then(r => r.json())
                .then(data => { qrEl.innerHTML = data.svg || '<div class="text-danger">Could not load QR code.</div>'; })
                .catch(() => { qrEl.innerHTML = '<div class="text-danger">Could not load QR code.</div>'; });

            fetch('{{ route('two-factor.secret-key') }}', {headers: {Accept: 'application/json'}})
                .then(r => r.json())
                .then(data => {
                    const span = secretEl.querySelector('span');
                    if (span) span.textContent = data.secretKey || 'unavailable';
                })
                .catch(() => {
                    const span = secretEl.querySelector('span');
                    if (span) span.textContent = 'unavailable';
                });
        }

        if (codesEl) {
            fetch('{{ route('two-factor.recovery-codes') }}', {headers: {Accept: 'application/json'}})
                .then(r => r.json())
                .then(codes => {
                    if (!Array.isArray(codes) || codes.length === 0) {
                        codesEl.innerHTML = '<div class="text-muted small">No recovery codes generated yet.</div>';
                        return;
                    }
                    const list = document.createElement('div');
                    list.className = 'row row-cols-2 g-1 small';
                    codes.forEach(code => {
                        const col = document.createElement('div');
                        col.className = 'col';
                        col.innerHTML = '<code>' + code + '</code>';
                        list.appendChild(col);
                    });
                    codesEl.innerHTML = '';
                    codesEl.appendChild(list);
                })
                .catch(() => { codesEl.innerHTML = '<div class="text-danger">Could not load recovery codes.</div>'; });
        }
    });
</script>
@endpush
@endonce
