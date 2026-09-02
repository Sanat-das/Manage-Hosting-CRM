@extends('adminlte::page')

@section('title', 'My Domains')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">My Domains</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('client.dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item active">Domains</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    <x-adminlte.partials.datatable
        icon="bi bi-globe"
        title="My Domains"
        :search-value="$search"
        search-placeholder="Search domain or registrar..."
        :columns="[
            ['label' => 'Domain', 'sort' => 'name'],
            ['label' => 'Registrar', 'sort' => 'registrar'],
            ['label' => 'Expiry', 'sort' => 'expiry_date'],
            ['label' => 'Auto-renew', 'sort' => 'auto_renew'],
            ['label' => 'Status', 'sort' => 'status'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$domains"
    >
        <x-slot name="tools">
            <a href="{{ route('client.domains.register') }}" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-circle me-1"></i> Register New Domain
            </a>
        </x-slot>

                @forelse ($domains as $domain)
                    <tr>
                        <td><strong>{{ $domain->name }}</strong></td>
                        <td class="text-muted">{{ $domain->registrar ?? '—' }}</td>
                        <td class="{{ $domain->isExpiringSoon() ? 'text-warning fw-bold' : 'text-muted' }}">
                            {{ $domain->expiry_date?->format('M j, Y') ?? '—' }}
                        </td>
                        <td>{{ $domain->auto_renew ? 'Yes' : 'No' }}</td>
                        <td>
                            <x-adminlte.partials.status-badge :status="$domain->status" />
                        </td>
                        <td class="text-end">
                            <div class="table-actions">
                                <a href="{{ route('client.domains.show', $domain) }}" class="btn btn-sm btn-outline-primary btn-icon" title="Details" aria-label="Details"><i class="bi bi-eye"></i></a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No domains registered.</td></tr>
                @endforelse
    </x-adminlte.partials.datatable>
@stop
