@extends('adminlte::page')

@section('title', 'Subscription #'.$subscription->id)

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Subscription #{{ $subscription->id }}</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.subscriptions.index') }}">Subscriptions</a></li>
                <li class="breadcrumb-item active" aria-current="page">#{{ $subscription->id }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif

    <div class="row">
        <div class="col-md-8">
            <x-adminlte-card icon="bi bi-info-circle" title="Details">
                <table class="table table-sm table-borderless mb-0">
                    <tbody>
                        <tr><th class="text-muted w-25">ID</th><td>#{{ $subscription->id }}</td></tr>
                        <tr><th class="text-muted">Service</th><td>{{ $subscription->service?->domain ?? $subscription->service?->username ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Customer</th><td>{{ $subscription->service?->customer?->full_name ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Amount</th><td>${{ number_format($subscription->amount, 2) }}</td></tr>
                        <tr><th class="text-muted">Billing Cycle</th><td>{{ ucfirst(str_replace('_', ' ', $subscription->billing_cycle)) }}</td></tr>
                        <tr><th class="text-muted">Next Billing</th><td>{{ $subscription->next_billing_date?->format('Y-m-d') ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Start Date</th><td>{{ $subscription->start_date?->format('Y-m-d') ?? '—' }}</td></tr>
                        <tr><th class="text-muted">End Date</th><td>{{ $subscription->end_date?->format('Y-m-d') ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Status</th><td><x-adminlte.partials.status-badge :status="$subscription->status" /></td></tr>
                    </tbody>
                </table>
            </x-adminlte-card>
        </div>
        <div class="col-md-4">
            <x-adminlte-card icon="bi bi-arrow-repeat" title="Update Status">
                <form method="POST" action="{{ route('admin.subscriptions.update', $subscription) }}">
                    @csrf @method('PUT')
                    <x-adminlte-select name="status" label="Status">
                        @foreach (['active' => 'Active', 'suspended' => 'Suspended', 'cancelled' => 'Cancelled', 'expired' => 'Expired'] as $val => $lbl)
                            <option value="{{ $val }}" @selected($subscription->status === $val)>{{ $lbl }}</option>
                        @endforeach
                    </x-adminlte-select>
                    <x-adminlte-input name="amount" label="Amount ($)" type="number" step="0.01" min="0" value="{{ $subscription->amount }}" />
                    <button type="submit" class="btn btn-primary w-100">Update</button>
                </form>
            </x-adminlte-card>
        </div>
    </div>
@stop
