{{--
    Brand panel — "datacenter control room" identity.
    Reusable across every auth screen (login, register, password reset, 2FA).
--}}
<div class="auth-brand__top">
    <a href="{{ url('/') }}" class="auth-brand__wordmark">
        {!! config('adminlte.logo') !!}
    </a>
</div>

<div class="auth-status" role="status" aria-label="System status">
    <div class="auth-status__head">
        <span>System Status</span>
        <span class="auth-status__live">Live</span>
    </div>
    <div class="auth-status__row">
        <span class="auth-status__key">Nodes</span>
        <span class="auth-status__val"><span class="auth-led" aria-hidden="true"></span>12/12 online</span>
    </div>
    <div class="auth-status__row">
        <span class="auth-status__key">Uptime</span>
        <span class="auth-status__val">99.98%</span>
    </div>
    <div class="auth-status__row">
        <span class="auth-status__key">Latency</span>
        <span class="auth-status__val">11ms</span>
    </div>
    <div class="auth-status__row">
        <span class="auth-status__key">Load</span>
        <span class="auth-status__val">0.42</span>
    </div>
</div>

<div class="auth-brand__foot">
    <span>Hosting CRM</span>
    <span>All systems nominal</span>
</div>