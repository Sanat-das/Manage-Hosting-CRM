@extends('adminlte::page')

@section('title', 'Edit: ' . $quote->quote_no)

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Edit Quote</h1></div>
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
    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible>
            <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </x-adminlte-alert>
    @endif

    <x-adminlte.partials.form-card icon="bi bi-file-text" title="Edit: {{ $quote->quote_no }}"
        :action="route('admin.quotes.update', $quote)" submit-label="Save Changes"
        :cancel-url="route('admin.quotes.show', $quote)">
        @method('PUT')
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-select name="stage" label="Stage">
                    @foreach (\App\Http\Controllers\Admin\QuoteController::STAGES as $k => $v)
                        <option value="{{ $k }}" @selected(old('stage', $quote->stage) === $k)>{{ $v }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="subject" label="Subject" value="{{ old('subject', $quote->subject) }}" required />
            </div>
        </div>
        <div class="row">
            <div class="col-md-3"><x-adminlte-input name="subtotal" type="number" step="0.01" label="Subtotal" value="{{ old('subtotal', $quote->subtotal) }}" required /></div>
            <div class="col-md-3"><x-adminlte-input name="discount" type="number" step="0.01" label="Discount" value="{{ old('discount', $quote->discount) }}" /></div>
            <div class="col-md-3"><x-adminlte-input name="tax" type="number" step="0.01" label="Tax" value="{{ old('tax', $quote->tax) }}" /></div>
            <div class="col-md-3"><x-adminlte-input name="valid_until" type="date" label="Valid until" value="{{ old('valid_until', $quote->valid_until?->format('Y-m-d')) }}" /></div>
        </div>
        <x-adminlte-textarea name="notes" label="Notes" rows="3">{{ old('notes', $quote->notes) }}</x-adminlte-textarea>
    </x-adminlte.partials.form-card>
@stop
