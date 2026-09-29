@extends('adminlte::layouts.errors-master')

@section('title', '500 Server Error')

@section('content')
    @php
        // Guarded for the same reason as 404: an error page that throws is worse
        // than the error it was reporting.
        $user = auth()->user();
        $home = url('/');
        if ($user && method_exists($user, 'hasRole')) {
            $home = $user->hasRole('client') ? url('/client') : url('/admin/dashboard');
        }
    @endphp

    <div class="text-center">
        <div class="mb-3">
            <i class="bi bi-exclamation-octagon" style="font-size:4rem;color:var(--color-danger);opacity:.9" aria-hidden="true"></i>
        </div>
        <p class="mb-1 fw-bold" style="font-size:4.5rem;line-height:1;letter-spacing:-.03em;color:var(--color-danger)">500</p>
        <h1 class="h2 mb-2">{{ __('Something went wrong') }}</h1>
        <p class="mb-4 mx-auto" style="max-width:34rem;color:var(--bs-secondary-color)">
            {{ __('The request could not be completed because of a server error. It has been logged — try again in a moment, and contact support if it keeps happening.') }}
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
