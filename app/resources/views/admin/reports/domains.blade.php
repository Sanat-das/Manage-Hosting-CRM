@extends('adminlte::page')

@section('title', 'Domain Report')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Domain Report</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active">Reports</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    <x-adminlte-card icon="bi bi-clock-history" title="Expiring Domains (Next 30 Days)">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Domain</th><th>Customer</th><th>Expiry</th></tr></thead>
            <tbody>
                @forelse ($expiring as $d)
                    <tr>
                        <td><strong>{{ $d->name }}</strong></td>
                        <td>{{ $d->customer?->full_name ?? '—' }}</td>
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
