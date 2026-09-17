@extends('adminlte::page')
@section('title', 'Domain Pricing â€” '.$pricing->tld)
@section('content_header')
    <x-ui.page-header title=".{{ $pricing->tld }}" subtitle="View domain pricing details" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Domain Pricing','url' => route('admin.domain-pricing.index')],['label' => '.' . $pricing->tld,'active' => true]]" />
@stop
@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif
    <div class="d-flex justify-content-end mb-3"><a href="{{ route('admin.domain-pricing.edit', $pricing) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i> Edit</a></div>
    <x-adminlte-card icon="bi bi-info-circle" title="Details">
        <table class="table table-sm table-borderless mb-0">
            <tbody>
                <tr><th class="text-muted w-25">TLD</th><td>.{{ $pricing->tld }}</td></tr>
                <tr><th class="text-muted">Register Price</th><td>{{ number_format($pricing->register_price, 2) }}</td></tr>
                <tr><th class="text-muted">Renew Price</th><td>{{ number_format($pricing->renew_price, 2) }}</td></tr>
                <tr><th class="text-muted">Transfer Price</th><td>{{ number_format($pricing->transfer_price, 2) }}</td></tr>
                <tr><th class="text-muted">Currency</th><td>{{ $pricing->currency }}</td></tr>
                <tr><th class="text-muted">Premium</th><td><span class="badge {{ $pricing->premium ? 'text-bg-warning' : 'text-bg-secondary' }}">{{ $pricing->premium ? 'Yes' : 'No' }}</span></td></tr>
                <tr><th class="text-muted">Enabled</th><td><span class="badge {{ $pricing->enabled ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $pricing->enabled ? 'Yes' : 'No' }}</span></td></tr>
                <tr><th class="text-muted">Synced At</th><td>{{ $pricing->synced_at?->diffForHumans() ?? 'â€”' }}</td></tr>
            </tbody>
        </table>
    </x-adminlte-card>
    @if ($pricing->terms->count())
        <x-adminlte-card icon="bi bi-list-ol" title="Term Pricing" class="mt-3">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Years</th><th>Register Price</th><th>Renew Price</th></tr></thead>
                    <tbody>
                        @foreach ($pricing->terms->sortBy('term_years') as $term)
                            <tr>
                                <td>{{ $term->term_years }}</td>
                                <td>{{ number_format($term->register_price, 2) }}</td>
                                <td>{{ number_format($term->renew_price, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-adminlte-card>
    @endif
@stop

