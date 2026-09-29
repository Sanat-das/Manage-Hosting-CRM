@extends('adminlte::page')

@section('title', 'Transactions')

@section('content_header')
    <x-ui.page-header title="Transactions" subtitle="Browse and manage all financial transactions" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')], ['label' => 'Transactions', 'active' => true]]" />
@stop

@section('content')
    <x-adminlte.partials.flash-alert />

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
            <x-ui.empty-table-row colSpan="9" title="No transactions found." />
        @endforelse
    </x-adminlte.partials.datatable>
@stop
