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

    <x-adminlte-card icon="bi bi-globe" title="All Domain Pricing">
        <div class="text-end mb-3">
            <a href="{{ route('admin.domain-pricing.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Domain Pricing</a>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>TLD</th><th>Register Price</th><th>Renew Price</th><th>Transfer Price</th><th>Premium</th><th>Enabled</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                    @forelse ($pricings as $pricing)
                        <tr>
                            <td><a href="{{ route('admin.domain-pricing.show', $pricing) }}"><strong>.{{ $pricing->tld }}</strong></a></td>
                            <td>{{ number_format($pricing->register_price, 2) }}</td>
                            <td>{{ number_format($pricing->renew_price, 2) }}</td>
                            <td>{{ number_format($pricing->transfer_price, 2) }}</td>
                            <td><span class="badge {{ $pricing->premium ? 'bg-warning' : 'bg-secondary' }}">{{ $pricing->premium ? 'Yes' : 'No' }}</span></td>
                            <td><span class="badge {{ $pricing->enabled ? 'bg-success' : 'bg-secondary' }}">{{ $pricing->enabled ? 'Yes' : 'No' }}</span></td>
                            <td class="text-end">
                                <a href="{{ route('admin.domain-pricing.edit', $pricing) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">No domain pricing found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $pricings->links() }}
    </x-adminlte-card>
@stop
