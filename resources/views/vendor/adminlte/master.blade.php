@php
    $layoutFixed = config('adminlte.layout_fixed_sidebar');
    $fixedHeader = config('adminlte.layout_fixed_navbar');
    $fixedFooter = config('adminlte.layout_fixed_footer');
    $rtl = config('adminlte.layout_rtl', false);
    $sidebarBreakpoint = config('adminlte.sidebar_breakpoint', 'lg');
    $sidebarMini = config('adminlte.sidebar_mini');
    $sidebarCollapse = config('adminlte.sidebar_collapse');

    $bodyClasses = collect([
        $layoutFixed ? 'layout-fixed' : null,
        $fixedHeader ? 'fixed-header' : null,
        $fixedFooter ? 'fixed-footer' : null,
        'sidebar-expand-'.$sidebarBreakpoint,
        $sidebarMini ? 'sidebar-mini' : null,
        $sidebarCollapse ? 'sidebar-collapse' : null,
        'bg-body-tertiary',
        config('adminlte.classes_body'),
    ])->filter()->implode(' ');

    $titlePrefix = config('adminlte.title_prefix', '');
    $titlePostfix = config('adminlte.title_postfix', '');
    $title = trim($titlePrefix.' '.($title ?? config('adminlte.title', 'AdminLTE 4')).' '.$titlePostfix);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      dir="{{ $rtl ? 'rtl' : 'ltr' }}"
      @isset($darkMode) data-bs-theme="dark" @endisset>
<head>
    @include('adminlte::partials.head')
</head>
<body class="{{ $bodyClasses }}">
    @include('adminlte::partials.preloader')

    @include('adminlte::partials.impersonation-banner')

    <div class="app-wrapper">
        @include('adminlte::partials.navbar')
        @include('adminlte::partials.sidebar')

        <main class="app-main">
            {{-- Full-bleed opt-out: @section('content_header_full') bypasses container & header chrome.
                 Useful for dashboards, reports, or canvas-style pages that need edge-to-edge layout.
                 Existing @section('content_header') keeps working unchanged. --}}
            @hasSection('content_header_full')
                @yield('content_header_full')
            @else
                @hasSection('content_header')
                    <div class="app-content-header {{ config('adminlte.classes_content_header') }}">
                        <div class="container-fluid">
                            @yield('content_header')
                        </div>
                    </div>
                @endif
            @endif

            @hasSection('content_full')
                @yield('content_full')
            @else
                <div class="app-content {{ config('adminlte.classes_content') }}">
                    <div class="container-fluid">
                        @yield('content')
                    </div>
                </div>
            @endif
        </main>

        @include('adminlte::partials.footer')
        @include('adminlte::partials.control-sidebar')
    </div>

    @pluginScripts
    @stack('js')
    @yield('js')
</body>
</html>
