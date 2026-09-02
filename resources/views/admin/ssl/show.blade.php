@extends('adminlte::page')

@section('title', $ssl->domain_name.' SSL Certificate')

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">{{ $ssl->domain_name }}</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.ssl.index') }}">SSL Certificates</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $ssl->domain_name }}</li>
            </ol>
        </div>
    </div>
@stop

@php
    $typeLabels = ['single' => 'Single', 'wildcard' => 'Wildcard', 'multidomain' => 'Multi-Domain'];
@endphp

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif
    @if (session('error'))
        <x-adminlte-alert theme="danger" dismissible>{{ session('error') }}</x-adminlte-alert>
    @endif

    {{-- Header --}}
    <x-adminlte-card>
        <div class="d-flex flex-wrap align-items-center gap-3">
            <div class="d-flex align-items-center justify-content-center rounded-circle bg-primary text-white"
                 style="width: 56px; height: 56px; font-size: 1.25rem; flex-shrink: 0;">
                <i class="bi bi-shield-lock" aria-hidden="true"></i>
            </div>
            <div class="flex-grow-1">
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <h4 class="mb-0">{{ $ssl->domain_name }}</h4>
                    <x-adminlte.partials.status-badge :status="$ssl->status" />
                    <span class="badge text-bg-info">{{ $typeLabels[$ssl->certificate_type] ?? ucfirst($ssl->certificate_type) }}</span>
                    @if ($ssl->provider)
                        <span class="badge text-bg-secondary">{{ $ssl->provider }}</span>
                    @endif
                </div>
                <div class="text-muted mt-1">
                    @if ($ssl->customer)
                        <i class="bi bi-person me-1" aria-hidden="true"></i>
                        <a href="{{ route('admin.customers.show', $ssl->customer) }}">{{ $ssl->customer->full_name }}</a>
                        <span class="text-muted">{{ $ssl->customer->display_id }}</span>
                    @endif
                </div>
            </div>
            <div class="d-flex gap-2">
                @can('settings.edit')
                    <a href="{{ route('admin.ssl.edit', $ssl) }}" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-pencil me-1" aria-hidden="true"></i> Edit
                    </a>
                    <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal"
                            data-bs-target="#delete-ssl-modal">
                        <i class="bi bi-trash me-1" aria-hidden="true"></i> Delete
                    </button>
                @endcan
            </div>
        </div>
    </x-adminlte-card>

    {{-- Details --}}
    <x-adminlte-card :title="'Certificate details'" icon="bi bi-file-earmark-lock2">
        <div class="row">
            <div class="col-md-6">
                <table class="table table-sm table-borderless">
                    <tbody>
                        <tr><th class="w-25 text-muted">Domain</th><td>{{ $ssl->domain_name }}</td></tr>
                        <tr><th class="text-muted">Type</th><td>{{ $typeLabels[$ssl->certificate_type] ?? ucfirst($ssl->certificate_type) }}</td></tr>
                        <tr><th class="text-muted">Provider</th><td>{{ $ssl->provider ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Customer</th>
                            <td>
                                @if ($ssl->customer)
                                    <a href="{{ route('admin.customers.show', $ssl->customer) }}">{{ $ssl->customer->full_name }}</a>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-sm table-borderless">
                    <tbody>
                        <tr><th class="w-25 text-muted">Issue date</th><td>{{ $ssl->issue_date?->format('M j, Y') ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Expiry date</th>
                            <td>
                                @if ($ssl->expiry_date === null)
                                    —
                                @elseif ($ssl->isExpired())
                                    <span class="text-danger">{{ $ssl->expiry_date->format('M j, Y') }}</span>
                                @elseif ($ssl->isExpiringSoon())
                                    <span class="text-warning">{{ $ssl->expiry_date->format('M j, Y') }}
                                        <span class="badge text-bg-warning ms-1">Expiring soon</span>
                                    </span>
                                @else
                                    {{ $ssl->expiry_date->format('M j, Y') }}
                                @endif
                            </td>
                        </tr>
                        <tr><th class="text-muted">Order</th>
                            <td>
                                @if ($ssl->order && \Illuminate\Support\Facades\Route::has('admin.orders.show'))
                                    <a href="{{ route('admin.orders.show', $ssl->order) }}">#{{ $ssl->order->id }}</a>
                                @else
                                    {{ $ssl->order_id ?? '—' }}
                                @endif
                            </td>
                        </tr>
                        <tr><th class="text-muted">Added</th><td>{{ $ssl->created_at?->format('M j, Y H:i') }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        @if ($ssl->notes)
            <div class="mt-2">
                <strong class="text-muted">Notes</strong>
                <p class="mb-0 mt-1">{{ $ssl->notes }}</p>
            </div>
        @endif
    </x-adminlte-card>

    @can('settings.edit')
        <x-adminlte.partials.confirm-modal
            id="delete-ssl-modal"
            title="Delete SSL certificate"
            :message="'Delete the SSL certificate for ' . $ssl->domain_name . '? This cannot be undone.'"
            :action="route('admin.ssl.destroy', $ssl)"
            confirm-label="Delete certificate"
        />
    @endcan
@stop
