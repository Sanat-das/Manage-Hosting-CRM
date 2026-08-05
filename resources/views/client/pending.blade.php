@extends('adminlte::page')

@section('title', 'Account Pending')

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Account Pending</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">Account Pending</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    <x-adminlte-card theme="info" icon="bi bi-hourglass-split" title="Your account is being set up">
        <p class="mb-3">
            Hi <strong>{{ $user->full_name }}</strong> — your account has been created but is not linked to a
            customer record yet. This usually means we're still provisioning your services.
        </p>
        <p class="mb-4">
            Please check back shortly. If this persists, contact our support team and we'll get you sorted.
        </p>

        <div class="d-flex gap-2">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn btn-outline-secondary">
                    <i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i> Sign out
                </button>
            </form>
            <a href="{{ url('/') }}" class="btn btn-outline-info">
                <i class="bi bi-house me-1" aria-hidden="true"></i> Back to home
            </a>
        </div>
    </x-adminlte-card>
@stop
