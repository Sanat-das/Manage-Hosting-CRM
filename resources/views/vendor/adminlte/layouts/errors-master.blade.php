@php
    $rtl = config('adminlte.layout_rtl', false);
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
<body class="bg-body-tertiary">
    <div class="d-flex align-items-center justify-content-center min-vh-100">
        <div class="container">
            @yield('content')
        </div>
    </div>

    @stack('js')
    @yield('js')
</body>
</html>
