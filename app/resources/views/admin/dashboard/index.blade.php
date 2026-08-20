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

    <div class="d-flex justify-content-between align-items-center mb-3">
        <small class="text-muted">Drag widgets to rearrange. Your layout is saved automatically.</small>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#dashboardWidgetPicker">
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Add widget
        </button>
    </div>

    @if (!empty($widgets))
        <div class="row" data-dashboard-grid>
            @foreach ($widgets as $widget)
                @include('admin.dashboard._widget-shell', ['widget' => $widget])
            @endforeach
        </div>
    @else
        <x-adminlte-card icon="bi bi-grid" title="No widgets">
            <p class="text-muted mb-3">Your dashboard is empty — add a widget to get started.</p>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#dashboardWidgetPicker">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Add widget
            </button>
        </x-adminlte-card>
    @endif

    @include('admin.dashboard._add-widget-modal', ['available' => $available ?? []])

    @push('js')
        @vite('resources/js/dashboard.js')
    @endpush
@stop
