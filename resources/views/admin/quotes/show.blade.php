@extends('adminlte::page')

@section('title', $quote->quote_no)

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">{{ $quote->quote_no }}</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.quotes.index') }}">Quotes</a></li>
                <li class="breadcrumb-item active">{{ $quote->quote_no }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif

    <div class="d-flex justify-content-between mb-3">
        <div>
            <x-adminlte.partials.status-badge :status="$quote->stage" />
            <span class="text-muted ms-2">{{ $quote->subject }}</span>
        </div>
        <div class="d-flex gap-2">
            @can('invoices.edit')
                <a href="{{ route('admin.quotes.edit', $quote) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i> Edit</a>
            @endcan
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <x-adminlte-card>
                <table class="table table-sm table-borderless mb-0">
                    <tbody>
                        <tr><th class="w-25 text-muted">Customer</th><td>{{ $quote->customer?->full_name ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Subject</th><td>{{ $quote->subject }}</td></tr>
                        <tr><th class="text-muted">Valid until</th><td>{{ $quote->valid_until?->format('M j, Y') ?? 'No expiry' }}</td></tr>
                    </tbody>
                </table>
            </x-adminlte-card>
        </div>
        <div class="col-lg-4">
            <x-adminlte-card title="Summary">
                <table class="table table-sm table-borderless mb-0">
                    <tbody>
                        <tr><td class="text-muted">Subtotal</td><td class="text-end">{{ number_format($quote->subtotal, 2) }}</td></tr>
                        <tr><td class="text-muted">Discount</td><td class="text-end">-{{ number_format($quote->discount, 2) }}</td></tr>
                        <tr><td class="text-muted">Tax</td><td class="text-end">{{ number_format($quote->tax, 2) }}</td></tr>
                        <tr class="border-top"><th>Total</th><td class="text-end fw-bold">{{ number_format($quote->total, 2) }}</td></tr>
                    </tbody>
                </table>
            </x-adminlte-card>
        </div>
    </div>
@stop
