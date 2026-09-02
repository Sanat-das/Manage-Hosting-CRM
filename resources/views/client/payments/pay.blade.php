@extends('adminlte::page')

@section('title', 'Pay Invoice ' . $invoice->invoice_no)

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Pay Invoice {{ $invoice->invoice_no }}</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('client.invoices.index') }}">Invoices</a></li>
                <li class="breadcrumb-item"><a href="{{ route('client.invoices.show', $invoice) }}">{{ $invoice->invoice_no }}</a></li>
                <li class="breadcrumb-item active">Pay</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    <div class="row">
        <div class="col-lg-8">
            <x-adminlte-card icon="bi bi-credit-card" title="Choose a Payment Method">
                @if ($gateways->isEmpty())
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle me-1"></i> No payment methods are currently available. Please contact support.
                    </div>
                    <a href="{{ route('client.invoices.show', $invoice) }}" class="btn btn-outline-secondary mt-3">
                        <i class="bi bi-arrow-left me-1"></i> Back to Invoice
                    </a>
                @else
                    <form method="POST" action="{{ route('client.invoices.pay.purchase', $invoice) }}">
                        @csrf
                        <div class="mb-3">
                            @foreach ($gateways as $gateway)
                                @php
                                    $description = $gateway->isOnline()
                                        ? "You'll be redirected to complete payment."
                                        : 'Follow the instructions to complete payment.';
                                @endphp
                                <label class="d-flex align-items-center border rounded p-3 mb-2">
                                    <input type="radio" name="gateway" value="{{ $gateway->code }}"
                                           class="form-check-input me-3" {{ $loop->first ? 'checked' : '' }} required>
                                    <span>
                                        <strong>{{ $gateway->name }}</strong>
                                        <span class="d-block small text-muted">{{ $description }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-arrow-right-circle me-1"></i> Continue to Payment
                        </button>
                        <a href="{{ route('client.invoices.show', $invoice) }}" class="btn btn-outline-secondary ms-2">Cancel</a>
                    </form>
                @endif
            </x-adminlte-card>
        </div>

        <div class="col-lg-4">
            <x-adminlte-card icon="bi bi-receipt" title="Invoice Summary">
                <div class="mb-2">
                    <span class="text-muted">Invoice</span>
                    <strong class="float-end">{{ $invoice->invoice_no }}</strong>
                </div>
                <div class="mb-2">
                    <span class="text-muted">Status</span>
                    <x-adminlte.partials.status-badge :status="$invoice->status" class="float-end" />
                </div>
                <hr>
                <div class="mb-3">
                    <div class="text-muted small">Amount Due</div>
                    <div class="fs-4 fw-bold">{{ number_format($dueAmount, 2) }}</div>
                </div>
            </x-adminlte-card>
        </div>
    </div>
@stop
