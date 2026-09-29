@extends('adminlte::page')

@section('title', 'Domain Pricing')

@section('content_header')
    <x-ui.page-header title="Domain Pricing" subtitle="Overview and management of domain pricing" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Domain Pricing','active' => true]]" />
@stop

@section('content')
    <x-adminlte.partials.flash-alert />

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
            <x-ui.empty-table-row colSpan="7" icon="bi bi-globe" title="No domain pricing found." />
        @endforelse
    </x-adminlte.partials.datatable>
@stop

