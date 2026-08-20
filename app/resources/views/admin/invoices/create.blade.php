@extends('adminlte::page')

@section('title', 'Create Invoice')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Create Invoice</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.invoices.index') }}">Invoices</a></li>
                <li class="breadcrumb-item active">Create</li>
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

    <x-adminlte.partials.form-card icon="bi bi-receipt" title="New Invoice"
        :action="route('admin.invoices.store')" submit-label="Create Invoice"
        :cancel-url="route('admin.invoices.index')">
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-select name="customer_id" label="Customer" required>
                    <option value="">Select customer...</option>
                    @foreach ($customers as $c)
                        <option value="{{ $c->id }}" @selected(old('customer_id') == $c->id)>{{ $c->full_name }} ({{ $c->user?->email }})</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-3">
                <x-adminlte-input name="amount" type="number" step="0.01" min="0" label="Subtotal" value="{{ old('amount', '0.00') }}" required />
            </div>
            <div class="col-md-3">
                <x-adminlte-select name="status" label="Status">
                    <option value="draft" @selected(old('status', 'draft') === 'draft')">Draft</option>
                    <option value="sent" @selected(old('status') === 'sent')">Sent</option>
                </x-adminlte-select>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="due_date" type="date" label="Due date" value="{{ old('due_date') }}" />
            </div>
            <div class="col-md-6">
                <x-adminlte-textarea name="notes" label="Notes" rows="2" placeholder="Optional notes...">{{ old('notes') }}</x-adminlte-textarea>
            </div>
        </div>

        <h5 class="mt-3">Line Items</h5>
        <div id="line-items">
            <div class="row g-2 mb-2 line-item">
                <div class="col-md-5"><input type="text" name="items[0][description]" class="form-control form-control-sm" placeholder="Description" required></div>
                <div class="col-md-2"><input type="number" name="items[0][quantity]" class="form-control form-control-sm" value="1" min="1" required></div>
                <div class="col-md-3"><input type="number" name="items[0][unit_price]" class="form-control form-control-sm" step="0.01" min="0" placeholder="Unit price" required></div>
                <div class="col-md-2"><button type="button" class="btn btn-sm btn-outline-danger remove-item"><i class="bi bi-trash"></i></button></div>
            </div>
        </div>
        <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="add-item"><i class="bi bi-plus-lg me-1"></i> Add Line</button>
    </x-adminlte.partials.form-card>

    @push('scripts')
    <script>
        let idx = 1;
        document.getElementById('add-item').addEventListener('click', function() {
            const html = `<div class="row g-2 mb-2 line-item">
                <div class="col-md-5"><input type="text" name="items[${idx}][description]" class="form-control form-control-sm" placeholder="Description" required></div>
                <div class="col-md-2"><input type="number" name="items[${idx}][quantity]" class="form-control form-control-sm" value="1" min="1" required></div>
                <div class="col-md-3"><input type="number" name="items[${idx}][unit_price]" class="form-control form-control-sm" step="0.01" min="0" placeholder="Unit price" required></div>
                <div class="col-md-2"><button type="button" class="btn btn-sm btn-outline-danger remove-item"><i class="bi bi-trash"></i></button></div>
            </div>`;
            document.getElementById('line-items').insertAdjacentHTML('beforeend', html);
            idx++;
        });
        document.getElementById('line-items').addEventListener('click', function(e) {
            if (e.target.closest('.remove-item')) e.target.closest('.line-item').remove();
        });
    </script>
    @endpush
@stop
