@extends('adminlte::page')
@section('title', 'Tax Rate — '.($rate->name ?? 'Unnamed'))
@section('content_header')
    <x-ui.page-header title="{{ $rate->name ?? 'Unnamed' }}" subtitle="View tax rate details and applicability" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')], ['label' => 'Tax Rates', 'url' => route('admin.tax-rates.index')], ['label' => $rate->name ?? 'Unnamed', 'active' => true]]" />
@stop
@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif
    <div class="d-flex justify-content-end mb-3"><a href="{{ route('admin.tax-rates.edit', $rate) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i> Edit</a></div>
    <x-adminlte-card icon="bi bi-info-circle" title="Details">
        <table class="table table-sm table-borderless mb-0">
            <tbody>
                <tr><th class="text-muted w-25">Name</th><td>{{ $rate->name ?? '—' }}</td></tr>
                <tr><th class="text-muted">Rate</th><td>{{ $rate->rate }}%</td></tr>
                <tr><th class="text-muted">Status</th><td>
                    <x-adminlte.partials.status-badge :status="$rate->is_active ? 'active' : 'inactive'" />
                </td></tr>
            </tbody>
        </table>
    </x-adminlte-card>
@stop
