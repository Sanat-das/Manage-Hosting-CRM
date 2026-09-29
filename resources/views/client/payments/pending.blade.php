@extends('adminlte::page')

@section('title', 'Payment Pending')

@section('content_header')
    <x-ui.page-header title="Payment Pending" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Invoices', 'url' => route('client.invoices.index')],
        ['label' => $invoice->invoice_no, 'url' => route('client.invoices.show', $invoice)],
        ['label' => 'Payment', 'active' => true],
    ]" />
@stop

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <x-adminlte-card icon="bi bi-hourglass-split" title="Payment">
                <p>Your payment for invoice <strong>{{ $invoice->invoice_no }}</strong> is
                    <x-adminlte.partials.status-badge :status="$payment->status" />.
                </p>

                @if ($payment->status === 'pending')
                    @if (session('payment_message'))
                        <div class="alert alert-info"><i class="bi bi-info-circle me-1"></i> {{ session('payment_message') }}</div>
                    @endif

                    @if (! empty(session('payment_instructions')))
                        <h6 class="mt-3">Complete the payment using these details:</h6>
                        <ul class="list-group mb-3">
                            @foreach (session('payment_instructions') as $label => $value)
                                <li class="list-group-item d-flex justify-content-between">
                                    <span class="text-muted">{{ $label }}</span>
                                    <strong>{{ $value }}</strong>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <div class="alert alert-secondary border mb-3">
                        <i class="bi bi-check2-circle me-1 text-success"></i>
                        Once you've completed the transfer, our team will confirm your payment and update the invoice.
                    </div>
                @endif

                <a href="{{ route('client.invoices.show', $invoice) }}" class="btn btn-primary">
                    <i class="bi bi-arrow-left me-1"></i> Back to Invoice
                </a>
            </x-adminlte-card>
        </div>
    </div>
@stop
