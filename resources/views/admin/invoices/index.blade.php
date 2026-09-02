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
        :sticky-header="true"
        :column-toggle="true"
        :export-url="route('admin.invoices.index')"
        export-label="Export CSV"
        :columns="[
            ['label' => 'Invoice #', 'sort' => 'invoice_no'],
            ['label' => 'Customer', 'sort' => 'customer'],
            ['label' => 'Amount', 'sort' => 'amount', 'class' => 'text-end grid-numeric'],
            ['label' => 'Tax', 'sort' => 'tax', 'class' => 'text-end grid-numeric'],
            ['label' => 'Total', 'sort' => 'total', 'class' => 'text-end grid-numeric'],
            ['label' => 'Paid', 'sort' => 'paid_amount', 'class' => 'text-end grid-numeric'],
            ['label' => 'Status', 'sort' => 'status'],
            ['label' => 'Due date', 'sort' => 'due_date'],
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
                <td class="text-end grid-numeric">₹{{ number_format($invoice->amount, 2) }}</td>
                <td class="text-end text-muted grid-numeric">₹{{ number_format($invoice->tax, 2) }}</td>
                <td class="text-end fw-bold grid-numeric">₹{{ number_format($invoice->total, 2) }}</td>
                <td class="text-end grid-numeric">₹{{ number_format($invoice->paid_amount ?? 0, 2) }}</td>
                <td><x-adminlte.partials.status-badge :status="$invoice->status" /></td>
                <td class="text-muted">{{ $invoice->due_date?->format('M j, Y') ?? '—' }}</td>
                <td class="text-end">
                        <div class="table-actions">
                            <a href="{{ route('admin.invoices.pdf', $invoice) }}" class="btn btn-sm btn-outline-secondary btn-icon" title="Download PDF" aria-label="Download PDF"><i class="bi bi-file-pdf"></i></a>
                        </div>
                    </td>
            </tr>
        @empty
            <x-ui.empty-table-row
                :col-span="9"
                icon="bi bi-receipt"
                title="No invoices found"
                message="Try adjusting your search or filters, or create a new invoice."
            />
        @endforelse
    </x-adminlte.partials.datatable>
@stop
