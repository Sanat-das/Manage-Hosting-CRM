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

{{-- Compiled AdminLTE + Bootstrap from Vite pipeline — branding.css last so :root wins --}}
@vite(['resources/css/adminlte.css', 'resources/css/branding.css', 'resources/js/adminlte.js'])

{{-- HostVexa branding: dynamic favicon, OG, theme-color, and primary/accent CSS overrides --}}
@php
    $_branding = $branding ?? \App\Support\Branding::all();
    $_brandingFavicon = $_branding['favicon_url'] ?? \App\Support\Branding::faviconUrl();
    $_brandingOg = $_branding['og_url'] ?? \App\Support\Branding::ogUrl();
    $_brandingPrimary = $_branding['primary_color'] ?? \App\Support\Branding::primaryColor();
    $_brandingAccent = $_branding['accent_color'] ?? \App\Support\Branding::accentColor();
    $_brandingAppName = $_branding['app_name'] ?? config('app.name', 'HostVexa');
    $_brandingInline = \App\Support\Branding::inlineStyle();
@endphp
<link rel="icon" type="image/svg+xml" href="{{ $_brandingFavicon }}">
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
