@extends('adminlte::page')

@section('title', 'New Quote')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">New Quote</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.quotes.index') }}">Quotes</a></li>
                <li class="breadcrumb-item active">New</li>
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

    <x-adminlte.partials.form-card icon="bi bi-file-text" title="New Quote"
        :action="route('admin.quotes.store')" submit-label="Create Quote"
        :cancel-url="route('admin.quotes.index')">
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-select name="customer_id" label="Customer" required>
                    <option value="">Select customer...</option>
                    @foreach ($customers as $c)
                        <option value="{{ $c->id }}" @selected(old('customer_id') == $c->id)>{{ $c->full_name }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="subject" label="Subject" value="{{ old('subject') }}" required />
            </div>
        </div>
        <div class="row">
            <div class="col-md-3"><x-adminlte-input name="subtotal" type="number" step="0.01" label="Subtotal" value="{{ old('subtotal', '0.00') }}" required /></div>
            <div class="col-md-3"><x-adminlte-input name="discount" type="number" step="0.01" label="Discount" value="{{ old('discount', '0.00') }}" /></div>
            <div class="col-md-3"><x-adminlte-input name="tax" type="number" step="0.01" label="Tax" value="{{ old('tax', '0.00') }}" /></div>
            <div class="col-md-3"><x-adminlte-input name="valid_until" type="date" label="Valid until" value="{{ old('valid_until') }}" /></div>
        </div>
        <x-adminlte-textarea name="notes" label="Notes" rows="3">{{ old('notes') }}</x-adminlte-textarea>
    </x-adminlte.partials.form-card>
@stop
