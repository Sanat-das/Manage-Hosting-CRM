@php
    $title = trim(($title ?? config('adminlte.title', 'AdminLTE 4')));
    $authType = $authType ?? 'login'; // login | register
    $rtl = config('adminlte.layout_rtl', false);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    @include('adminlte::partials.head')
</head>
<body class="{{ $authType }}-page bg-body-secondary">
    <div class="{{ $authType }}-box">
        <div class="card card-outline card-primary">
            <div class="card-header text-center">
                <a href="{{ url('/') }}" class="h1">
                    {!! config('adminlte.logo', '<b>Admin</b>LTE') !!}
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
