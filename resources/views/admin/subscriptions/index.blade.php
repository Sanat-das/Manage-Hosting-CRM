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

    <x-adminlte.partials.datatable
        icon="bi bi-arrow-repeat"
        title="All Subscriptions"
        :search-value="$search"
        search-placeholder="Search service domain or username..."
        :status-options="['active' => 'Active', 'suspended' => 'Suspended', 'cancelled' => 'Cancelled', 'expired' => 'Expired']"
        :status-value="$status"
        :columns="[
            ['label' => 'ID', 'sort' => 'id'],
            ['label' => 'Service', 'sort' => 'service'],
            ['label' => 'Customer'],
            ['label' => 'Amount', 'sort' => 'amount'],
            ['label' => 'Period', 'sort' => 'billing_cycle'],
            ['label' => 'Next Bill'],
            ['label' => 'Status', 'sort' => 'status'],
        ]"
        :pagination="$subscriptions"
    >
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
    </x-adminlte.partials.datatable>
@stop
