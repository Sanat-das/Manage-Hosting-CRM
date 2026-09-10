@php
    $title = trim(($title ?? config('adminlte.title', 'AdminLTE 4')));
    $authType = $authType ?? 'login'; // login | register
    $rtl = config('adminlte.layout_rtl', false);
    $_authBranding = $branding ?? \App\Support\Branding::all();
    $_authLogoUrl = $_authBranding['logo_url'] ?? \App\Support\Branding::logoUrl();
    $_authLogoWebpUrl = $_authBranding['logo_webp_url'] ?? \App\Support\Branding::logoWebpUrl();
    $_authLogoPath = $_authBranding['logo_path'] ?? \App\Support\Branding::logoPath();
    // Show wordmark image when either a custom upload exists OR the shipped
    // default (now the promoted PNG) is available via logoUrl(). logoPath is
    // empty after "make current as default", but logoUrl still resolves to
    // DEFAULT_LOGO, so we must check the URL, not just the path.
    $_authHasLogo = (is_string($_authLogoPath) && trim($_authLogoPath) !== '') || (is_string($_authLogoUrl) && trim($_authLogoUrl) !== '');
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
                <a href="{{ url('/') }}" class="text-decoration-none d-inline-flex flex-column align-items-center gap-1 brand-logo-hostvexa">
                    @if($_authHasLogo)
                        <picture>
                            @if($_authLogoWebpUrl !== $_authLogoUrl)
                            <source srcset="{{ $_authLogoWebpUrl }}" type="image/webp">
                            @endif
                            <img src="{{ $_authLogoUrl }}" alt="{{ $_authAppName }}" style="height:32px;width:auto;vertical-align:middle;object-fit:contain" loading="eager">
                        </picture>
                    @else
                        <span class="d-inline-flex align-items-center gap-2 fw-bold" style="font-family:var(--hostvexa-font, 'Instrument Sans', system-ui);font-size:1.65rem;letter-spacing:-.03em;color:var(--hostvexa-navy,#0F172A);line-height:1"><i class="bi bi-hdd-rack"></i> {{ $_authAppName }}</span>
                    @endif
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
