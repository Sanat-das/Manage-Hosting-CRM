@extends('adminlte::page')

@section('title', 'Edit: ' . $invoice->invoice_no)

@section('content_header')
    <x-ui.page-header title="Edit Invoice" subtitle="Update invoice details and line items" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')], ['label' => 'Invoices', 'url' => route('admin.invoices.index')], ['label' => $invoice->invoice_no, 'active' => true]]" />
@stop

@section('content')
    <x-adminlte.partials.flash-alert />

    @if ($locked)
        <x-adminlte-alert theme="warning" dismissible>
            This invoice is paid, partly paid, void, cancelled or refunded — the line items, the customer and the discount are locked. You can still edit the status, the due date and the notes.
        </x-adminlte-alert>
    @endif

    @if ($lastEdit)
        <div class="small text-muted mb-2">Last edited by {{ $lastEdit }}</div>
    @endif

    <x-adminlte.partials.form-card icon="bi bi-receipt" title="Edit: {{ $invoice->invoice_no }}"
        :action="route('admin.invoices.update', $invoice)" submit-label="Save Changes"
        :cancel-url="route('admin.invoices.show', $invoice)">
        @method('PUT')

        {{-- The invoice's own timestamp. A form that was opened before someone
             else changed the invoice is refused with a clear message instead
             of failing every line id it posts. --}}
        <input type="hidden" name="updated_at" value="{{ old('updated_at', $invoice->updated_at?->toDateTimeString()) }}">

        @if ($locked)
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="locked-customer">Customer</label>
                        <input type="text" id="locked-customer" class="form-control-plaintext" value="{{ $invoice->customer?->full_name }} ({{ $invoice->customer?->user?->email }})" readonly>
                    </div>
                </div>
                <div class="col-md-6">
                    <x-adminlte-select name="status" label="Status">
                        @foreach ($editableStatuses as $val => $lbl)
                            <option value="{{ $val }}" @selected(old('status', $invoice->status) === $val)>{{ $lbl }}</option>
                        @endforeach
                    </x-adminlte-select>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <x-adminlte-input name="due_date" type="date" label="Due date" value="{{ old('due_date', $invoice->due_date?->format('Y-m-d')) }}" />
                </div>
            </div>
            <x-adminlte-textarea name="notes" label="Notes" rows="3">{{ old('notes', $invoice->notes) }}</x-adminlte-textarea>

            <h5 class="mt-3">Line Items</h5>
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Description</th><th class="text-center">Qty</th><th class="text-end">Unit Price</th><th class="text-end">Total</th></tr></thead>
                <tbody>
                    @forelse ($invoice->items as $item)
                        <tr>
                            <td>{{ $item->description }}</td>
                            <td class="text-center">{{ $item->quantity }}</td>
                            <td class="text-end">{{ number_format($item->unit_price, 2) }}</td>
                            <td class="text-end">{{ number_format($item->total, 2) }}</td>
                        </tr>
                    @empty
                        <x-ui.empty-table-row colSpan="4" icon="bi bi-list-ul" title="No items." />
                    @endforelse
                </tbody>
            </table>
        @else
            <div class="row">
                <div class="col-md-6">
                    {{-- The customer cannot be reassigned here: an invoice
                         belongs to the customer it was raised for. --}}
                    <div class="mb-3">
                        <label for="invoice-customer">Customer</label>
                        <input type="text" id="invoice-customer" class="form-control-plaintext" value="{{ $invoice->customer?->full_name }} ({{ $invoice->customer?->user?->email }})" readonly>
                    </div>
                </div>
                <div class="col-md-3">
                    <x-adminlte-input name="discount" type="number" step="0.01" min="0" label="Discount" value="{{ old('discount', number_format((float) $invoice->discount, 2, '.', '')) }}" />
                </div>
                <div class="col-md-3">
                    <x-adminlte-select name="status" label="Status">
                        @foreach ($editableStatuses as $val => $lbl)
                            <option value="{{ $val }}" @selected(old('status', $invoice->status) === $val)>{{ $lbl }}</option>
                        @endforeach
                    </x-adminlte-select>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <x-adminlte-input name="due_date" type="date" label="Due date" value="{{ old('due_date', $invoice->due_date?->format('Y-m-d')) }}" />
                </div>
                <div class="col-md-6">
                    <x-adminlte-textarea name="notes" label="Notes" rows="3">{{ old('notes', $invoice->notes) }}</x-adminlte-textarea>
                </div>
            </div>

            <h5 class="mt-3">Line Items</h5>
            @php
                // The rows the form shows: the submitted ones when a
                // validation failed (so a line the admin added is not
                // silently dropped), the stored ones otherwise.
                $rows = $submittedItems ?? $invoice->items->map(fn ($item) => [
                    'id' => $item->id,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                ])->all();
            @endphp
            <div id="line-items">
                @foreach ($rows as $i => $row)
                    <div class="row g-2 mb-2 line-item">
                        {{-- The line's identity. Without it the save cannot tell
                             which stored row a submitted line came from, and
                             the recreated line loses its product link (which
                             also drives per-product GST) and the configurable
                             options it bills for. --}}
                        <input type="hidden" name="items[{{ $i }}][id]" value="{{ old("items.$i.id", $row['id'] ?? null) }}">
                        <div class="col-md-4"><input type="text" name="items[{{ $i }}][description]" class="form-control form-control-sm" value="{{ old("items.$i.description", $row['description'] ?? '') }}" placeholder="Description" aria-label="Line item description" required></div>
                        <div class="col-md-2"><input type="number" name="items[{{ $i }}][quantity]" class="form-control form-control-sm" value="{{ old("items.$i.quantity", $row['quantity'] ?? 1) }}" min="1" aria-label="Line item quantity" required></div>
                        <div class="col-md-2"><input type="number" name="items[{{ $i }}][unit_price]" class="form-control form-control-sm" step="0.01" min="0" value="{{ old("items.$i.unit_price", $row['unit_price'] ?? 0) }}" placeholder="Unit price" aria-label="Line item unit price" required></div>
                        <div class="col-md-2"><span class="line-total d-block pt-1 text-end fw-semibold" aria-label="Line total">{{ number_format((float) old("items.$i.quantity", $row['quantity'] ?? 0) * (float) old("items.$i.unit_price", $row['unit_price'] ?? 0), 2) }}</span></div>
                        <div class="col-md-2"><button type="button" class="btn btn-sm btn-outline-danger remove-item" aria-label="Remove line item"><i class="bi bi-trash" aria-hidden="true"></i></button></div>
                    </div>
                @endforeach
            </div>
            <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="add-item"><i class="bi bi-plus-lg me-1"></i> Add Line</button>

            <div class="row mt-3">
                <div class="col-md-6 offset-md-3">
                    <div class="d-flex justify-content-between"><span>Subtotal</span><span id="edit-subtotal">0.00</span></div>
                    <div class="d-flex justify-content-between text-muted"><span>Less discount</span><span id="edit-discount">0.00</span></div>
                    <div class="d-flex justify-content-between fw-semibold border-top pt-1 mt-1"><span>Total before tax</span><span id="edit-due">0.00</span></div>
                    <div class="form-text">Tax is computed from the saved lines — GST settings decide the rate.</div>
                </div>
            </div>
        @endif
    </x-adminlte.partials.form-card>

    @if (!$locked)
        @push('js')
        <script>
            let idx = {{ count($rows ?? []) }};

            // The line total and the header totals, live. Tax is the server's
            // job (GST settings decide the rate), so the strip stops at the
            // total before tax and says so.
            function lineTotal(row) {
                const qty = parseFloat(row.querySelector('input[name*="quantity"]')?.value) || 0;
                const price = parseFloat(row.querySelector('input[name*="unit_price"]')?.value) || 0;

                return qty * price;
            }

            function recalcTotals() {
                let subtotal = 0;
                const rows = document.querySelectorAll('#line-items .line-item');

                rows.forEach(function (row) {
                    const total = lineTotal(row);
                    subtotal += total;
                    const cell = row.querySelector('.line-total');
                    if (cell) { cell.textContent = total.toFixed(2); }
                });

                const discount = parseFloat(document.querySelector('input[name="discount"]')?.value) || 0;

                const subtotalEl = document.getElementById('edit-subtotal');
                if (subtotalEl) { subtotalEl.textContent = subtotal.toFixed(2); }

                const discountEl = document.getElementById('edit-discount');
                if (discountEl) { discountEl.textContent = discount.toFixed(2); }

                const dueEl = document.getElementById('edit-due');
                if (dueEl) { dueEl.textContent = Math.max(0, subtotal - discount).toFixed(2); }

                // An invoice needs at least one line: the last row's remove
                // button is disabled rather than leaving an empty form that
                // the server would refuse.
                rows.forEach(function (row) {
                    const button = row.querySelector('.remove-item');
                    if (button) { button.disabled = rows.length <= 1; }
                });
            }

            document.getElementById('add-item').addEventListener('click', function() {
                const html = `<div class="row g-2 mb-2 line-item">
                    <div class="col-md-4"><input type="text" name="items[${idx}][description]" class="form-control form-control-sm" placeholder="Description" aria-label="Line item description" required></div>
                    <div class="col-md-2"><input type="number" name="items[${idx}][quantity]" class="form-control form-control-sm" value="1" min="1" aria-label="Line item quantity" required></div>
                    <div class="col-md-2"><input type="number" name="items[${idx}][unit_price]" class="form-control form-control-sm" step="0.01" min="0" placeholder="Unit price" aria-label="Line item unit price" required></div>
                    <div class="col-md-2"><span class="line-total d-block pt-1 text-end fw-semibold" aria-label="Line total">0.00</span></div>
                    <div class="col-md-2"><button type="button" class="btn btn-sm btn-outline-danger remove-item" aria-label="Remove line item"><i class="bi bi-trash" aria-hidden="true"></i></button></div>
                </div>`;
                document.getElementById('line-items').insertAdjacentHTML('beforeend', html);
                idx++;
                recalcTotals();
            });

            document.getElementById('line-items').addEventListener('click', function(e) {
                if (e.target.closest('.remove-item')) {
                    e.target.closest('.line-item').remove();
                    recalcTotals();
                }
            });

            document.getElementById('line-items').addEventListener('input', recalcTotals);

            const discountField = document.querySelector('input[name="discount"]');
            if (discountField) { discountField.addEventListener('input', recalcTotals); }

            recalcTotals();
        </script>
        @endpush
    @endif
@stop
