@extends('adminlte::page')

@section('title', 'Invoices')

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Invoices</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">Invoices</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif

    <x-adminlte.partials.metric-cards :items="[
        ['title' => $stats->get('draft', 0), 'text' => 'Draft', 'icon' => 'bi bi-pencil', 'theme' => 'secondary'],
        ['title' => $stats->get('sent', 0), 'text' => 'Sent', 'icon' => 'bi bi-envelope', 'theme' => 'primary'],
        ['title' => $stats->get('paid', 0), 'text' => 'Paid', 'icon' => 'bi bi-check-circle', 'theme' => 'success'],
        ['title' => $stats->get('overdue', 0), 'text' => 'Overdue', 'icon' => 'bi bi-exclamation-triangle', 'theme' => 'danger'],
    ]" />

    <x-adminlte.partials.datatable
        icon="bi bi-receipt"
        title="All Invoices"
        :search-value="$search"
        search-placeholder="Search invoice #, customer..."
        :status-options="$statuses"
        :status-value="$status"
        :columns="[
            ['label' => 'Invoice #'],
            ['label' => 'Customer'],
            ['label' => 'Amount', 'class' => 'text-end'],
            ['label' => 'Tax', 'class' => 'text-end'],
            ['label' => 'Total', 'class' => 'text-end'],
            ['label' => 'Paid', 'class' => 'text-end'],
            ['label' => 'Status'],
            ['label' => 'Due date'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$invoices"
    >
        <x-slot name="tools">
            @can('invoices.create')
                <a href="{{ route('admin.invoices.create') }}" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-lg me-1"></i> Create Invoice
                </a>
            @endcan
        </x-slot>

        @forelse ($invoices as $invoice)
            <tr>
                <td><a href="{{ route('admin.invoices.show', $invoice) }}"><strong>{{ $invoice->invoice_no }}</strong></a></td>
                <td>{{ $invoice->customer->full_name ?? '—' }}</td>
                <td class="text-end">{{ number_format($invoice->amount, 2) }}</td>
                <td class="text-end text-muted">{{ number_format($invoice->tax, 2) }}</td>
                <td class="text-end fw-bold">{{ number_format($invoice->total, 2) }}</td>
                <td class="text-end">{{ number_format($invoice->paid_amount ?? 0, 2) }}</td>
                <td><x-adminlte.partials.status-badge :status="$invoice->status" /></td>
                <td class="text-muted">{{ $invoice->due_date?->format('M j, Y') ?? '—' }}</td>
                <td class="text-end">
                    <a href="{{ route('admin.invoices.show', $invoice) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
                    <a href="{{ route('admin.invoices.pdf', $invoice) }}" class="btn btn-sm btn-outline-secondary" title="Download PDF"><i class="bi bi-file-pdf"></i></a>
                </td>
            </tr>
        @empty
            <tr><td colspan="9" class="text-center text-muted py-4">No invoices found.</td></tr>
        @endforelse
    </x-adminlte.partials.datatable>
@stop
