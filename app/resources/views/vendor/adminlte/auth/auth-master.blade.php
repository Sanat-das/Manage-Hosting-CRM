@php
    $title = trim(($title ?? config('adminlte.title', 'AdminLTE 4')));
    $authType = $authType ?? 'login'; // login | register
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }}</title>
    {{-- Branded AdminLTE build (tokens + auth styles) ships via the Vite bundle --}}
    @vite(['resources/css/adminlte.scss', 'resources/js/adminlte.js'])
    @stack('css')
</head>
<body class="auth-shell">
    <div class="auth-shell__inner">
        <aside class="auth-brand">
            @include('adminlte::auth.partials.brand-panel')
        </aside>
        <main class="auth-form">
            <div class="auth-form__card">
                @yield('auth_body')
            </div>
        </main>
    </div>
    @stack('js')
</body>
</html>