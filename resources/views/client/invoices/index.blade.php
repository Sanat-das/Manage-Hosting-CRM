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
        <a href="{{ route('client.invoices.index', ['status' => 'paid']) }}" class="btn btn-sm {{ $status === 'paid' ? 'btn-success' : 'btn-outline-success' }}">Paid</a>
    </div>

    <x-adminlte-card icon="bi bi-receipt" title="Invoices">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr><th>Invoice #</th><th>Date</th><th>Due</th><th>Amount</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($invoices as $invoice)
                    <tr>
                        <td><strong>{{ $invoice->invoice_no }}</strong></td>
                        <td class="text-muted">{{ $invoice->created_at?->format('M j, Y') }}</td>
                        <td class="text-muted">{{ $invoice->due_date?->format('M j, Y') ?? '—' }}</td>
                        <td>{{ number_format($invoice->total, 2) }}</td>
                        <td>
                            @php $badge = ['paid'=>'success','sent'=>'info','overdue'=>'danger','draft'=>'secondary','cancelled'=>'dark'][$invoice->status] ?? 'secondary'; @endphp
                            <span class="badge bg-{{ $badge }}">{{ ucfirst($invoice->status) }}</span>
                        </td>
                        <td class="text-end">
                            <a href="{{ route('client.invoices.show', $invoice) }}" class="btn btn-sm btn-outline-primary">View</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No invoices.</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $invoices->links() }}
    </x-adminlte-card>
@stop
