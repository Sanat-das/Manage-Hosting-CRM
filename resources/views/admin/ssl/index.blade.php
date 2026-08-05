@extends('adminlte::page')

@section('title', 'SSL Certificates')

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">SSL Certificates</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">SSL Certificates</li>
            </ol>
        </div>
    </div>
@stop

@php
    $typeLabels = ['single' => 'Single', 'wildcard' => 'Wildcard', 'multidomain' => 'Multi-Domain'];
    $filters = array_filter(request()->only('search', 'status'));
    $expiringUrl = route('admin.ssl.index', array_merge($filters, ['expiring' => 1]));
@endphp

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif
    @if (session('error'))
        <x-adminlte-alert theme="danger" dismissible>{{ session('error') }}</x-adminlte-alert>
    @endif

    <x-adminlte.partials.datatable
        icon="bi bi-shield-lock"
        title="{{ $expiring ? 'Certificates expiring within 30 days' : 'All SSL Certificates' }}"
        :search-value="$search"
        search-placeholder="Search domain..."
        :status-options="['active' => 'Active', 'pending' => 'Pending', 'expired' => 'Expired', 'revoked' => 'Revoked', 'failed' => 'Failed']"
        :status-value="$status"
        :columns="[
            ['label' => '#'],
            ['label' => 'Domain'],
            ['label' => 'Customer'],
            ['label' => 'Type'],
            ['label' => 'Expiry', 'class' => 'text-end'],
            ['label' => 'Status'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$certificates"
    >
        <x-slot name="tools">
            <a href="{{ $expiringUrl }}" class="btn btn-sm {{ $expiring ? 'btn-warning' : 'btn-outline-warning' }}"
               title="Active certificates expiring within 30 days">
                <i class="bi bi-clock-history me-1" aria-hidden="true"></i> Expiring soon
            </a>
            @if ($expiring)
                <a href="{{ route('admin.ssl.index') }}" class="btn btn-sm btn-outline-secondary" title="Clear filter">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </a>
            @endif
            @can('settings.edit')
                <a href="{{ route('admin.ssl.create') }}" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Add Certificate
                </a>
            @endcan
        </x-slot>

        @forelse ($certificates as $certificate)
            <tr>
                <td class="text-muted">{{ $certificate->id }}</td>
                <td>
                    <a href="{{ route('admin.ssl.show', $certificate) }}"><strong>{{ $certificate->domain_name }}</strong></a>
                </td>
                <td>
                    @if ($certificate->customer)
                        <a href="{{ route('admin.customers.show', $certificate->customer) }}">
                            {{ $certificate->customer->full_name }}
                        </a>
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </td>
                <td class="text-muted">{{ $typeLabels[$certificate->certificate_type] ?? ucfirst($certificate->certificate_type) }}</td>
                <td class="text-end">
                    @if ($certificate->expiry_date === null)
                        <span class="text-muted">—</span>
                    @elseif ($certificate->isExpired())
                        <span class="text-danger">{{ $certificate->expiry_date->format('M j, Y') }}</span>
                    @elseif ($certificate->isExpiringSoon())
                        <span class="text-warning" title="Expiring soon">{{ $certificate->expiry_date->format('M j, Y') }}</span>
                    @else
                        {{ $certificate->expiry_date->format('M j, Y') }}
                    @endif
                </td>
                <td>
                    <x-adminlte.partials.status-badge :status="$certificate->status" />
                </td>
                <td class="text-end">
                    <a href="{{ route('admin.ssl.show', $certificate) }}"
                       class="btn btn-sm btn-outline-secondary" title="View">
                        <i class="bi bi-eye" aria-hidden="true"></i>
                    </a>
                    @can('settings.edit')
                        <a href="{{ route('admin.ssl.edit', $certificate) }}"
                           class="btn btn-sm btn-outline-secondary" title="Edit">
                            <i class="bi bi-pencil" aria-hidden="true"></i>
                        </a>
                    @endcan
                    @can('settings.edit')
                        <form method="POST" action="{{ route('admin.ssl.destroy', $certificate) }}"
                              onsubmit="return confirm('Delete SSL certificate for {{ $certificate->domain_name }}? This cannot be undone.');"
                              class="d-inline">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger" title="Delete">
                                <i class="bi bi-trash" aria-hidden="true"></i>
                            </button>
                        </form>
                    @endcan
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="7" class="text-center text-muted py-4">
                    No SSL certificates found.
                </td>
            </tr>
        @endforelse
    </x-adminlte.partials.datatable>
@stop
