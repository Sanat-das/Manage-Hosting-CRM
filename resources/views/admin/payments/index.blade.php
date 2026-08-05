@extends('adminlte::page')

@section('title', 'Payments')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Payments</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active">Payments</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif

    <x-adminlte.partials.datatable icon="bi bi-credit-card" title="All Payments"
        :search-value="$search" search-placeholder="Search transaction ID or invoice..."
        :columns="[
            ['label' => '#'],
            ['label' => 'Invoice'],
            ['label' => 'Customer'],
            ['label' => 'Method'],
            ['label' => 'Transaction ID'],
            ['label' => 'Amount', 'class' => 'text-end'],
            ['label' => 'Date'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]" :pagination="$payments">
        <x-slot name="tools">
            <form method="GET" action="{{ url()->current() }}" class="d-inline-flex align-items-center gap-2 me-2">
                <input type="hidden" name="search" value="{{ $search }}">
                <select name="method" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All methods</option>
                    @foreach ($methods as $k => $v)
                        <option value="{{ $k }}" @selected($method === $k)>{{ $v }}</option>
                    @endforeach
                </select>
            </form>
            @can('payments.create')
                <a href="{{ route('admin.payments.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> Record Payment</a>
            @endcan
        </x-slot>

        @forelse ($payments as $payment)
            <tr>
                <td>{{ $payment->id }}</td>
                <td><a href="{{ route('admin.invoices.show', $payment->invoice) }}">{{ $payment->invoice?->invoice_no ?? '—' }}</a></td>
                <td>{{ $payment->invoice?->customer?->full_name ?? '—' }}</td>
                <td>{{ ucfirst(str_replace('_', ' ', $payment->method)) }}</td>
                <td class="text-muted">{{ $payment->transaction_id ?? '—' }}</td>
                <td class="text-end fw-bold">{{ number_format($payment->amount, 2) }}</td>
                <td class="text-muted">{{ $payment->created_at?->format('M j, Y H:i') }}</td>
                <td class="text-end"><a href="{{ route('admin.payments.show', $payment) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a></td>
            </tr>
        @empty
            <tr><td colspan="8" class="text-center text-muted py-4">No payments found.</td></tr>
        @endforelse
    </x-adminlte.partials.datatable>
@stop
