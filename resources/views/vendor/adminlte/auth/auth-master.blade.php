@php
    $title = trim(($title ?? config('adminlte.title', 'AdminLTE 4')));
    $authType = $authType ?? 'login'; // login | register
    $rtl = config('adminlte.layout_rtl', false);
    $_authBranding = $branding ?? \App\Support\Branding::all();
    $_authLogoUrl = $_authBranding['mark_url'] ?? \App\Support\Branding::markUrl();
    $_authAppName = $_authBranding['app_name'] ?? config('app.name', 'HostVexa');
    $_authTagline = $_authBranding['tagline'] ?? \App\Support\Branding::tagline();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    @include('adminlte::partials.head')
</head>
<body class="{{ $authType }}-page bg-body-secondary">
    <div class="{{ $authType }}-box">
        <div class="card card-outline card-primary">
            <div class="card-header text-center py-3">
                <a href="{{ url('/') }}" class="text-decoration-none d-inline-flex flex-column align-items-center gap-1">
                    <span class="d-inline-flex align-items-center gap-2">
                        <img src="{{ $_authLogoUrl }}" alt="{{ $_authAppName }}" width="42" height="42" style="object-fit:contain;border-radius:10px;box-shadow:0 1px 8px rgba(14,165,233,.18)" loading="eager">
                        <span class="fw-bold" style="font-family:var(--hostvexa-font, 'Instrument Sans', system-ui);font-size:1.65rem;letter-spacing:-.03em;color:var(--hostvexa-navy,#0F172A);line-height:1">{{ $_authAppName }}</span>
                    </span>
                    @if($_authTagline !== '')
                        <small class="text-muted" style="font-size:.72rem;letter-spacing:.08em;text-transform:uppercase">{{ $_authTagline }}</small>
                    @endif
                </a>
            </div>
            <div class="card-body">
                @yield('auth_body')
            </div>
        </div>
    </div>
    @stack('js')
</body>
</html>
