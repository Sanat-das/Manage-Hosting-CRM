@extends('adminlte::page')

@section('title', 'Transactions')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Transactions</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active">Transactions</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif

    <x-adminlte.partials.datatable icon="bi bi-arrow-left-right" title="All Transactions"
        :search-value="$search" search-placeholder="Search transaction ID or customer..."
        :status-options="$statuses" :status-value="$status"
        :columns="[
            ['label' => '#', 'sort' => 'id'], ['label' => 'Customer', 'sort' => 'customer'], ['label' => 'Invoice', 'sort' => 'invoice'],
            ['label' => 'Amount', 'sort' => 'amount', 'class' => 'text-end'], ['label' => 'Fee', 'sort' => 'fee', 'class' => 'text-end'],
            ['label' => 'Net', 'sort' => 'net_amount', 'class' => 'text-end'], ['label' => 'Method', 'sort' => 'method'],
            ['label' => 'Status', 'sort' => 'status'], ['label' => 'Date', 'sort' => 'created_at'],
        ]" :pagination="$transactions">
        @forelse ($transactions as $tx)
            <tr>
                <td><a href="{{ route('admin.transactions.show', $tx) }}">{{ $tx->id }}</a></td>
                <td>{{ $tx->customer?->full_name ?? '—' }}</td>
                <td>
                    @if ($tx->invoice)
                        <a href="{{ route('admin.invoices.show', $tx->invoice) }}">{{ $tx->invoice->invoice_no }}</a>
                    @else
                        —
                    @endif
                </td>
                <td class="text-end">{{ number_format($tx->amount, 2) }}</td>
                <td class="text-end text-muted">{{ number_format($tx->fee, 2) }}</td>
                <td class="text-end fw-bold">{{ number_format($tx->net_amount, 2) }}</td>
                <td>{{ $methods[$tx->payment_method] ?? ucfirst(str_replace('_', ' ', $tx->payment_method)) }}</td>
                <td><x-adminlte.partials.status-badge :status="$tx->status" /></td>
                <td class="text-muted">{{ $tx->created_at?->format('M j, Y') }}</td>
            </tr>
        @empty
            <tr><td colspan="9" class="text-center text-muted py-4">No transactions found.</td></tr>
        @endforelse
    </x-adminlte.partials.datatable>
@stop
