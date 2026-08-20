@extends('adminlte::page')

@section('title', 'Subscriptions')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Subscriptions</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">Subscriptions</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif

    <x-adminlte-card icon="bi bi-arrow-repeat" title="All Subscriptions">
        <div class="row mb-3">
            <div class="col-md-4">
                <form method="GET" action="{{ route('admin.subscriptions.index') }}">
                    <x-adminlte-select name="status" label="Filter by Status">
                        <option value="">All Statuses</option>
                        @foreach (['active' => 'Active', 'suspended' => 'Suspended', 'cancelled' => 'Cancelled', 'expired' => 'Expired'] as $val => $lbl)
                            <option value="{{ $val }}" @selected(request('status') === $val)>{{ $lbl }}</option>
                        @endforeach
                    </x-adminlte-select>
                    <button type="submit" class="btn btn-sm btn-outline-primary">Filter</button>
                </form>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr><th>ID</th><th>Service</th><th>Customer</th><th>Amount</th><th>Period</th><th>Next Bill</th><th>Status</th></tr>
                </thead>
                <tbody>
                    @forelse ($subscriptions as $sub)
                        <tr>
                            <td><a href="{{ route('admin.subscriptions.show', $sub) }}"><strong>#{{ $sub->id }}</strong></a></td>
                            <td>{{ $sub->service?->domain ?? $sub->service?->username ?? '—' }}</td>
                            <td>{{ $sub->service?->customer?->full_name ?? '—' }}</td>
                            <td>${{ number_format($sub->amount, 2) }}</td>
                            <td>{{ ucfirst(str_replace('_', ' ', $sub->billing_cycle)) }}</td>
                            <td>{{ $sub->next_billing_date?->format('Y-m-d') ?? '—' }}</td>
                            <td><x-adminlte.partials.status-badge :status="$sub->status" /></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">No subscriptions found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $subscriptions->links() }}
    </x-adminlte-card>
@stop
