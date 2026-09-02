@extends('adminlte::page')

@section('title', 'Access Denied')

@section('content_header')
@endsection

@section('content')
<div class="d-flex flex-column align-items-center justify-content-center py-5" style="min-height: 55vh;">
    <div class="text-center" style="max-width: 480px;">
        <div class="mb-4">
            <i class="bi bi-shield-lock text-danger" style="font-size: 5rem; opacity: .85;"></i>
        </div>
        <h1 class="fw-bold mb-1" style="font-size: 5rem; line-height: 1; color: var(--bs-danger);">403</h1>
        <h2 class="fw-semibold mb-2">Access Denied</h2>
        <p class="text-muted mb-4">
            You don't have permission to view this page.<br>
            Contact your administrator if you believe this is a mistake.
        </p>
        <div class="d-flex gap-2 justify-content-center flex-wrap">
            @auth
                <a href="{{ route('admin.dashboard') }}" class="btn btn-primary">
                    <i class="bi bi-speedometer2 me-1"></i> Dashboard
                </a>
            @else
                <a href="{{ route('admin.login') }}" class="btn btn-primary">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
                </a>
            @endauth
            <button onclick="history.back()" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> Go Back
            </button>
        </div>
    </div>
</div>
@endsection

@push('css')
<style>
    .content-wrapper { background: transparent; }
</style>
@endpush
