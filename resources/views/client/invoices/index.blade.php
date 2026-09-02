@extends('adminlte::page')

@section('title', 'My Invoices')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">My Invoices</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('client.dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item active">Invoices</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    <div class="mb-3">
        <a href="{{ route('client.invoices.index') }}" class="btn btn-sm {{ !$status ? 'btn-primary' : 'btn-outline-primary' }}">All</a>
        <a href="{{ route('client.invoices.index', ['status' => 'unpaid']) }}" class="btn btn-sm {{ $status === 'unpaid' ? 'btn-warning' : 'btn-outline-warning' }}">Unpaid</a>
        <a href="{{ route('client.invoices.index', ['status' => 'overdue']) }}" class="btn btn-sm {{ $status === 'overdue' ? 'btn-danger' : 'btn-outline-danger' }}">Overdue</a>
        <a href="{{ route('client.invoices.index', ['status' => 'paid']) }}" class="btn btn-sm {{ $status === 'paid' ? 'btn-success' : 'btn-outline-success' }}">Paid</a>
    </div>

    <x-adminlte.partials.datatable
        icon="bi bi-receipt"
        title="Invoices"
        :search-value="$search"
        search-placeholder="Search invoice number..."
        :columns="[
            ['label' => 'Invoice #', 'sort' => 'invoice_no'],
            ['label' => 'Date', 'sort' => 'created_at'],
            ['label' => 'Due', 'sort' => 'due_date'],
            ['label' => 'Amount', 'sort' => 'total'],
            ['label' => 'Status', 'sort' => 'status'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$invoices"
    >
                @forelse ($invoices as $invoice)
                    <tr>
                        <td><a href="{{ route('client.invoices.show', $invoice) }}" class="text-decoration-none"><strong>{{ $invoice->invoice_no }}</strong></a></td>
                        <td class="text-muted">{{ $invoice->created_at?->format('M j, Y') }}</td>
                        <td class="text-muted">{{ $invoice->due_date?->format('M j, Y') ?? '—' }}</td>
                        <td>{{ number_format($invoice->total, 2) }}</td>
                        <td>
                            <x-adminlte.partials.status-badge :status="$invoice->status" />
                        </td>
                        <td class="text-end">
                            <div class="table-actions">
                                <a href="{{ route('client.invoices.show', $invoice) }}" class="btn btn-sm btn-outline-primary btn-icon" title="View" aria-label="View"><i class="bi bi-eye"></i></a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No invoices.</td></tr>
                @endforelse
    </x-adminlte.partials.datatable>
@stop
