@extends('adminlte::page')

@section('title', 'Domain Report')

@section('content_header')
    <x-ui.page-header title="Domain Report" subtitle="Domain registration and status overview" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Reports'],['label' => 'Domain Report','active' => true]]" />
@stop

@section('content')
    <x-adminlte-card icon="bi bi-clock-history" title="Expiring Domains (Next 30 Days)">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Domain</th><th>Customer</th><th>Expiry</th></tr></thead>
            <tbody>
                @forelse ($expiring as $d)
                    <tr>
                        <td><strong>{{ $d->name }}</strong></td>
                        <td>{{ $d->customer?->full_name ?? 'â€”' }}</td>
                        <td class="text-warning fw-bold">{{ $d->expiry_date?->format('M j, Y') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="text-center text-muted py-4">No domains expiring soon.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-adminlte-card>

    <x-adminlte-card icon="bi bi-pie-chart" title="Domain Status Distribution">
        <table class="table table-sm">
            @foreach ($byStatus as $s => $c)
                <tr><td class="text-capitalize">{{ $s }}</td><td class="text-end fw-bold">{{ $c }}</td></tr>
            @endforeach
        </table>
    </x-adminlte-card>
@stop

