{{-- Shared <head> boilerplate — included by master, auth-master and errors-master.
     Expects $title (optional) and $rtl (optional) to be set by the caller.
     Vite inputs are unified: resources/css/adminlte.css + resources/js/adminlte.js
     (Bootstrap Icons + Overlayscrollbars are imported via CSS). --}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<meta name="csrf-token" content="{{ csrf_token() }}">

@php
    // Fallback title when caller didn't prepare one (e.g. direct include).
    if (! isset($title)) {
        $titlePrefix = $titlePrefix ?? config('adminlte.title_prefix', '');
        $titlePostfix = $titlePostfix ?? config('adminlte.title_postfix', '');
        $title = trim($titlePrefix.' '.config('adminlte.title', 'AdminLTE 4').' '.$titlePostfix);
    }
    $rtl = $rtl ?? config('adminlte.layout_rtl', false);
@endphp
<title>{{ $title }}</title>

@hasSection('adminlte_css')
    @yield('adminlte_css')
@endif

{{-- Reverb realtime config — resolved at RUNTIME from Laravel config so the
     committed `public/build/` bundle does not need to be rebuilt per install.
     `window.__REVERB__` is the ONLY public Reverb surface; the secret never
     leaves the server (see App\Support\ReverbConfig). When no key is configured
     nothing is emitted and the JS degrades to polling. --}}
@php
    $_reverb = \App\Support\ReverbConfig::forClient();
@endphp
@if ($_reverb !== null)
<script>window.__REVERB__ = @json($_reverb)</script>
@endif

{{-- Compiled AdminLTE + Bootstrap from Vite pipeline — branding.css last so :root wins --}}
@vite(['resources/css/adminlte.css', 'resources/css/branding.css', 'resources/js/adminlte.js', 'resources/js/echo.js'])

{{-- HostVexa branding: dynamic favicon, OG, theme-color, and primary/accent CSS overrides --}}
@php
    $_branding = $branding ?? \App\Support\Branding::all();
    $_brandingFavicon = $_branding['favicon_url'] ?? \App\Support\Branding::faviconUrl();
    $_brandingFaviconWebp = $_branding['favicon_webp_url'] ?? \App\Support\Branding::faviconWebpUrl();
    $_brandingOg = $_branding['og_url'] ?? \App\Support\Branding::ogUrl();
    $_brandingPrimary = $_branding['primary_color'] ?? \App\Support\Branding::primaryColor();
    $_brandingAccent = $_branding['accent_color'] ?? \App\Support\Branding::accentColor();
    $_brandingAppName = $_branding['app_name'] ?? config('app.name', 'HostVexa');
    $_brandingInline = \App\Support\Branding::inlineStyle();
@endphp
@php $_faviconExt = strtolower(pathinfo(parse_url($_brandingFavicon, PHP_URL_PATH) ?? $_brandingFavicon, PATHINFO_EXTENSION)); $_faviconType = $_faviconExt === 'svg' ? 'image/svg+xml' : ($_faviconExt === 'png' ? 'image/png' : ($_faviconExt === 'ico' ? 'image/x-icon' : 'image/png')); @endphp
<link rel="icon" type="{{ $_faviconType }}" href="{{ $_brandingFavicon }}">
@if($_brandingFaviconWebp !== $_brandingFavicon)
<link rel="icon" type="image/webp" href="{{ $_brandingFaviconWebp }}">
@endif
<link rel="alternate icon" href="{{ $_brandingFavicon }}">
<meta name="theme-color" content="{{ $_brandingPrimary }}">
<meta property="og:site_name" content="{{ $_brandingAppName }}">
<meta property="og:image" content="{{ $_brandingOg }}">
@if($_brandingInline !== '')
<style id="hostvexa-branding-override">:root{!! $_brandingInline !!}</style>
@endif

@if ($rtl)
    {{-- AdminLTE ships a prebuilt RTL stylesheet; published by adminlte:install. --}}
    <link rel="stylesheet" href="{{ asset('vendor/adminlte/css/adminlte.rtl.min.css') }}">
@endif

@stack('css')
@yield('css')
@pluginStyles
