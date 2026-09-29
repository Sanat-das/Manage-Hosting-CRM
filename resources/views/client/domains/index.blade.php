@extends('adminlte::page')

@section('title', 'My Domains')

@section('content_header')
    <x-ui.page-header title="My Domains" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Dashboard', 'url' => route('client.dashboard')],
        ['label' => 'Domains', 'active' => true],
    ]" />
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
                    <x-ui.empty-table-row colSpan="6" icon="bi bi-globe" title="No domains registered." actionLabel="Register New Domain" :actionUrl="route('client.domains.register')" />
                @endforelse
    </x-adminlte.partials.datatable>
@stop
