@extends('adminlte::page')

@section('title', 'Domains')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Domains</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active">Domains</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif

    <x-adminlte.partials.metric-cards :items="[
        ['title' => $stats->sum(), 'text' => 'Total', 'icon' => 'bi bi-globe', 'theme' => 'primary'],
        ['title' => $stats->get('active', 0), 'text' => 'Active', 'icon' => 'bi bi-check-circle', 'theme' => 'success'],
        ['title' => $expiring, 'text' => 'Expiring (30d)', 'icon' => 'bi bi-clock-history', 'theme' => 'warning'],
        ['title' => $stats->get('expired', 0), 'text' => 'Expired', 'icon' => 'bi bi-x-circle', 'theme' => 'danger'],
    ]" />

    <x-adminlte.partials.datatable icon="bi bi-globe" title="All Domains"
        :search-value="$search" search-placeholder="Search domain name..."
        :status-options="$statuses" :status-value="$status"
        :columns="[
            ['label' => 'Domain'], ['label' => 'Customer'], ['label' => 'Registrar'],
            ['label' => 'Expiry'], ['label' => 'Auto-renew'],
            ['label' => 'Status'], ['label' => 'Actions', 'class' => 'text-end'],
        ]" :pagination="$domains">
        <x-slot name="tools">
            @can('domains.manage')
                <a href="{{ route('admin.domains.search') }}" class="btn btn-sm btn-outline-primary me-2"><i class="bi bi-search me-1"></i> Search</a>
                <a href="{{ route('admin.domains.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> Register</a>
            @endcan
        </x-slot>
        @forelse ($domains as $domain)
            <tr>
                <td><a href="{{ route('admin.domains.show', $domain) }}"><strong>{{ $domain->name }}</strong></a></td>
                <td>{{ $domain->customer?->full_name ?? '—' }}</td>
                <td class="text-muted">{{ $domain->registrar ?? '—' }}</td>
                <td class="text-muted {{ $domain->isExpiringSoon() ? 'text-warning fw-bold' : '' }}">{{ $domain->expiry_date?->format('M j, Y') ?? '—' }}</td>
                <td>{{ $domain->auto_renew ? 'Yes' : 'No' }}</td>
                <td><x-adminlte.partials.status-badge :status="$domain->status" /></td>
                <td class="text-end">
                    <a href="{{ route('admin.domains.show', $domain) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
                    @can('domains.manage')
                        <a href="{{ route('admin.domains.edit', $domain) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                    @endcan
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-center text-muted py-4">No domains found.</td></tr>
        @endforelse
    </x-adminlte.partials.datatable>
@stop
