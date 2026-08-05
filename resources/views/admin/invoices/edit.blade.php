@extends('adminlte::page')

@section('title', 'Edit: ' . $invoice->invoice_no)

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Edit Invoice</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.invoices.index') }}">Invoices</a></li>
                <li class="breadcrumb-item active">{{ $invoice->invoice_no }}</li>
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

    <x-adminlte.partials.form-card icon="bi bi-receipt" title="Edit: {{ $invoice->invoice_no }}"
        :action="route('admin.invoices.update', $invoice)" submit-label="Save Changes"
        :cancel-url="route('admin.invoices.show', $invoice)">
        @method('PUT')
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-select name="status" label="Status">
                    @foreach (\App\Models\Invoice::STATUS_LABELS as $val => $lbl)
                        <option value="{{ $val }}" @selected(old('status', $invoice->status) === $val)>{{ $lbl }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="due_date" type="date" label="Due date" value="{{ old('due_date', $invoice->due_date?->format('Y-m-d')) }}" />
            </div>
        </div>
        <x-adminlte-textarea name="notes" label="Notes" rows="3">{{ old('notes', $invoice->notes) }}</x-adminlte-textarea>
    </x-adminlte.partials.form-card>
@stop
