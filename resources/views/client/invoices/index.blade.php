@extends('adminlte::page')

@section('title', 'My Invoices')

@section('content_header')
    <x-ui.page-header title="My Invoices" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Dashboard', 'url' => route('client.dashboard')],
        ['label' => 'Invoices', 'active' => true],
    ]" />
@stop

@section('content')
    <x-adminlte.partials.datatable
        icon="bi bi-receipt"
        title="Invoices"
        :search-value="$search"
        search-placeholder="Search invoice number..."
        :status-options="['unpaid' => 'Unpaid', 'overdue' => 'Overdue', 'paid' => 'Paid']"
        :status-value="$status ?? ''"
        status-placeholder="All invoices"
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
                        <td><x-adminlte.partials.currency :value="$invoice->total" /></td>
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
                    <x-ui.empty-table-row colSpan="6" icon="bi bi-receipt" title="No invoices." />
                @endforelse
    </x-adminlte.partials.datatable>
@stop
