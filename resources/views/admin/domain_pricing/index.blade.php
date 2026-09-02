@extends('adminlte::page')

@section('title', 'Domain Pricing')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Domain Pricing</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">Domain Pricing</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif

    <x-adminlte.partials.datatable
        icon="bi bi-globe"
        title="All Domain Pricing"
        :search-value="$search"
        search-placeholder="Search TLD..."
        status-placeholder="All TLDs"
        :status-options="['enabled' => 'Enabled', 'disabled' => 'Disabled']"
        :status-value="$status"
        :columns="[
            ['label' => 'TLD', 'sort' => 'tld'],
            ['label' => 'Register Price', 'sort' => 'register_price'],
            ['label' => 'Renew Price', 'sort' => 'renew_price'],
            ['label' => 'Transfer Price', 'sort' => 'transfer_price'],
            ['label' => 'Premium', 'sort' => 'premium'],
            ['label' => 'Enabled', 'sort' => 'enabled'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$pricings"
    >
        <x-slot name="tools">
            <a href="{{ route('admin.domain-pricing.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Domain Pricing</a>
        </x-slot>

        @forelse ($pricings as $pricing)
            <tr>
                <td><a href="{{ route('admin.domain-pricing.show', $pricing) }}"><strong>.{{ $pricing->tld }}</strong></a></td>
                <td>{{ number_format($pricing->register_price, 2) }}</td>
                <td>{{ number_format($pricing->renew_price, 2) }}</td>
                <td>{{ number_format($pricing->transfer_price, 2) }}</td>
                <td><span class="badge {{ $pricing->premium ? 'text-bg-warning' : 'text-bg-secondary' }}">{{ $pricing->premium ? 'Yes' : 'No' }}</span></td>
                <td><span class="badge {{ $pricing->enabled ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $pricing->enabled ? 'Yes' : 'No' }}</span></td>
                <td class="text-end">
                    <div class="table-actions">
                        <a href="{{ route('admin.domain-pricing.edit', $pricing) }}" class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit"><i class="bi bi-pencil"></i></a>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-center text-muted py-4">No domain pricing found.</td></tr>
        @endforelse
    </x-adminlte.partials.datatable>
@stop
