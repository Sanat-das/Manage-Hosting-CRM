@extends('adminlte::page')

@section('title', __('adminlte.dashboard'))

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">{{ __('adminlte.dashboard') }}</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ __('adminlte.dashboard') }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif

    <x-adminlte.partials.metric-cards :items="[
        ['title' => '0', 'text' => 'Customers', 'icon' => 'bi bi-people', 'theme' => 'primary', 'url' => '#', 'url-text' => 'View all'],
        ['title' => '0', 'text' => 'Invoices', 'icon' => 'bi bi-receipt', 'theme' => 'warning', 'url' => '#', 'url-text' => 'View all'],
        ['title' => '0', 'text' => 'Tickets', 'icon' => 'bi bi-life-preserver', 'theme' => 'success', 'url' => '#', 'url-text' => 'View all'],
        ['title' => '$0.00', 'text' => 'Revenue', 'icon' => 'bi bi-currency-dollar', 'theme' => 'info', 'url' => '#', 'url-text' => 'View report'],
    ]" />

    <x-adminlte-card icon="bi bi-speedometer2" title="{{ __('adminlte.dashboard') }}">
        <p class="text-muted mb-0">
            Welcome back, <strong>{{ auth()->user()->full_name }}</strong>.
            The dashboard metrics will be populated as modules are built out.
        </p>
    </x-adminlte-card>
@stop
