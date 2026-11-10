@extends('adminlte::page')

@section('title', 'Create Invoice')

@section('content_header')
    <x-ui.page-header title="Create Invoice" subtitle="Create a new invoice for billing" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')], ['label' => 'Invoices', 'url' => route('admin.invoices.index')], ['label' => 'Create', 'active' => true]]" />
@stop

@section('content')
    <x-adminlte.partials.flash-alert />

    <x-adminlte.partials.form-card icon="bi bi-receipt" title="New Invoice"
        :action="route('admin.invoices.store')" submit-label="Create Invoice"
        :cancel-url="route('admin.invoices.index')">
        <div class="row">
            <div class="col-md-4">
                <x-adminlte-select name="customer_id" label="Customer" required>
                    <option value="">Select customer...</option>
                    @foreach ($customers as $c)
                        <option value="{{ $c->id }}" @selected(old('customer_id') == $c->id)>{{ $c->full_name }} ({{ $c->user?->email }})</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-3">
                {{-- The subtotal is the sum of the line items. It is shown, not
                     posted: the server derives the invoice amount from the
                     lines, so the header can never disagree with them. --}}
                <label for="subtotal" class="form-label">Subtotal</label>
                <input type="text" id="subtotal" class="form-control" value="0.00" readonly>
            </div>
            <div class="col-md-3">
                <x-adminlte-input name="discount" type="number" step="0.01" min="0" label="Discount" value="{{ old('discount', '0.00') }}" />
            </div>
            <div class="col-md-2">
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
                <div class="col-md-5"><input type="text" name="items[0][description]" class="form-control form-control-sm" placeholder="Description" aria-label="Line item description" required></div>
                <div class="col-md-2"><input type="number" name="items[0][quantity]" class="form-control form-control-sm" value="1" min="1" aria-label="Line item quantity" required></div>
                <div class="col-md-3"><input type="number" name="items[0][unit_price]" class="form-control form-control-sm" step="0.01" min="0" placeholder="Unit price" aria-label="Line item unit price" required></div>
                <div class="col-md-2"><button type="button" class="btn btn-sm btn-outline-danger remove-item" aria-label="Remove line item"><i class="bi bi-trash" aria-hidden="true"></i></button></div>
            </div>
        </div>
        <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="add-item"><i class="bi bi-plus-lg me-1"></i> Add Line</button>
    </x-adminlte.partials.form-card>

    @push('js')
    <script>
        let idx = 1;

        // The subtotal is a read-only mirror of the line items — the server
        // derives the invoice amount from the lines, never from the browser.
        function recalcSubtotal() {
            let sum = 0;
            document.querySelectorAll('#line-items .line-item').forEach(function (row) {
                const qty = parseFloat(row.querySelector('input[name*="quantity"]')?.value) || 0;
                const price = parseFloat(row.querySelector('input[name*="unit_price"]')?.value) || 0;
                sum += qty * price;
            });
            const field = document.getElementById('subtotal');
            if (field) { field.value = sum.toFixed(2); }
        }

        document.getElementById('add-item').addEventListener('click', function() {
            const html = `<div class="row g-2 mb-2 line-item">
                <div class="col-md-5"><input type="text" name="items[${idx}][description]" class="form-control form-control-sm" placeholder="Description" aria-label="Line item description" required></div>
                <div class="col-md-2"><input type="number" name="items[${idx}][quantity]" class="form-control form-control-sm" value="1" min="1" aria-label="Line item quantity" required></div>
                <div class="col-md-3"><input type="number" name="items[${idx}][unit_price]" class="form-control form-control-sm" step="0.01" min="0" placeholder="Unit price" aria-label="Line item unit price" required></div>
                <div class="col-md-2"><button type="button" class="btn btn-sm btn-outline-danger remove-item" aria-label="Remove line item"><i class="bi bi-trash"></i></button></div>
            </div>`;
            document.getElementById('line-items').insertAdjacentHTML('beforeend', html);
            idx++;
            recalcSubtotal();
        });
        document.getElementById('line-items').addEventListener('click', function(e) {
            if (e.target.closest('.remove-item')) {
                e.target.closest('.line-item').remove();
                recalcSubtotal();
            }
        });
        document.getElementById('line-items').addEventListener('input', recalcSubtotal);
        recalcSubtotal();
    </script>
    @endpush
@stop
