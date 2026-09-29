@extends('adminlte::layouts.errors-master')

@section('title', '404 Not Found')

@section('content')
    @php
        // Error pages must never throw, hence the guard rather than a bare
        // hasRole() call: a guest, or any future user model without the trait,
        // still gets a working link instead of a second exception.
        $user = auth()->user();
        $home = url('/');
        if ($user && method_exists($user, 'hasRole')) {
            $home = $user->hasRole('client') ? url('/client') : url('/admin/dashboard');
        }
    @endphp

    <div class="text-center">
        <div class="mb-3">
            <i class="bi bi-signpost-2" style="font-size:4rem;color:var(--color-text-faint);opacity:.9" aria-hidden="true"></i>
        </div>
        <p class="mb-1 fw-bold" style="font-size:4.5rem;line-height:1;letter-spacing:-.03em;color:var(--color-text-faint)">404</p>
        <h1 class="h2 mb-2">{{ __('Page not found') }}</h1>
        <p class="mb-4 mx-auto" style="max-width:34rem;color:var(--bs-secondary-color)">
            {{ __("The page you asked for doesn't exist. It may have been moved or renamed, or the link that brought you here is out of date.") }}
        </p>
        <div class="d-flex gap-2 flex-wrap justify-content-center">
            <a href="{{ $home }}" class="btn btn-primary">
                <i class="bi bi-house-door me-1" aria-hidden="true"></i>
                {{ $user ? __('Go to dashboard') : __('Go to home page') }}
            </a>
            <button type="button" data-history-back class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>
                {{ __('Go back') }}
            </button>
        </div>
    </div>
@endsection

@push('js')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-history-back]').forEach(function (btn) {
            btn.addEventListener('click', function () { window.history.back(); });
        });
    });
</script>
@endpush
