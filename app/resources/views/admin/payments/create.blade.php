@extends('adminlte::page')

@section('title', 'Record Payment')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Record Payment</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.payments.index') }}">Payments</a></li>
                <li class="breadcrumb-item active">Record</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible>
            <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </x-adminlte-alert>
    @endif

    <x-adminlte.partials.form-card icon="bi bi-credit-card" title="Record Payment"
        :action="route('admin.payments.store')" submit-label="Record Payment"
        :cancel-url="route('admin.payments.index')">
        <x-adminlte-select name="invoice_id" label="Invoice" required>
            <option value="">Select invoice...</option>
            @foreach ($invoices as $inv)
                <option value="{{ $inv->id }}" @selected(old('invoice_id') == $inv->id)>
                    {{ $inv->invoice_no }} — {{ $inv->customer?->full_name }} (Due: {{ number_format($inv->total - ($inv->paid_amount ?? 0), 2) }})
                </option>
            @endforeach
        </x-adminlte-select>
        <div class="row">
            <div class="col-md-4">
                <x-adminlte-input name="amount" type="number" step="0.01" min="0.01" label="Amount" value="{{ old('amount', '0.00') }}" required />
            </div>
            <div class="col-md-4">
                <x-adminlte-select name="method" label="Method" required>
                    @foreach (['bank_transfer' => 'Bank Transfer', 'razorpay' => 'Razorpay', 'stripe' => 'Stripe', 'paypal' => 'PayPal', 'wallet' => 'Wallet', 'manual' => 'Manual'] as $k => $v)
                        <option value="{{ $k }}" @selected(old('method') === $k)>{{ $v }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-4">
                <x-adminlte-input name="transaction_id" label="Transaction ID" value="{{ old('transaction_id') }}" placeholder="Optional" />
            </div>
        </div>
        <x-adminlte-textarea name="notes" label="Notes" rows="2">{{ old('notes') }}</x-adminlte-textarea>
    </x-adminlte.partials.form-card>
@stop
