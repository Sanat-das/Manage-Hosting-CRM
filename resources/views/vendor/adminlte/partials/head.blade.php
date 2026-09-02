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

{{-- Compiled AdminLTE + Bootstrap from Vite pipeline --}}
@vite(['resources/css/adminlte.css', 'resources/js/adminlte.js'])

@if ($rtl)
    {{-- AdminLTE ships a prebuilt RTL stylesheet; published by adminlte:install. --}}
    <link rel="stylesheet" href="{{ asset('vendor/adminlte/css/adminlte.rtl.min.css') }}">
@endif

@stack('css')
@yield('css')
@pluginStyles
