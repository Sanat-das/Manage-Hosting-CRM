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

    @if ($locked)
        <x-adminlte-alert theme="warning" dismissible>
            This invoice is paid or partially paid — line items, customer and discount are locked. You can still edit status, due date and notes.
        </x-adminlte-alert>
    @endif

    <x-adminlte.partials.form-card icon="bi bi-receipt" title="Edit: {{ $invoice->invoice_no }}"
        :action="route('admin.invoices.update', $invoice)" submit-label="Save Changes"
        :cancel-url="route('admin.invoices.show', $invoice)">
        @method('PUT')

        @if ($locked)
            @php $lockedCustomer = $customers->firstWhere('id', $invoice->customer_id); @endphp
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Customer</label>
                        <input type="text" class="form-control-plaintext" value="{{ $lockedCustomer?->full_name }} ({{ $lockedCustomer?->user?->email }})" readonly>
                    </div>
                </div>
                <div class="col-md-6">
                    <x-adminlte-select name="status" label="Status">
                        @foreach ($allowedStatuses as $val => $lbl)
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
                        <tr><td colspan="4" class="text-center text-muted">No items.</td></tr>
                    @endforelse
                </tbody>
            </table>
        @else
            <div class="row">
                <div class="col-md-6">
                    <x-adminlte-select name="customer_id" label="Customer" required>
                        <option value="">Select customer...</option>
                        @foreach ($customers as $c)
                            <option value="{{ $c->id }}" @selected(old('customer_id', $invoice->customer_id) == $c->id)>{{ $c->full_name }} ({{ $c->user?->email }})</option>
                        @endforeach
                    </x-adminlte-select>
                </div>
                <div class="col-md-3">
                    <x-adminlte-input name="discount" type="number" step="0.01" min="0" label="Discount" value="{{ old('discount', number_format((float) $invoice->discount, 2, '.', '')) }}" />
                </div>
                <div class="col-md-3">
                    <x-adminlte-select name="status" label="Status">
                        @foreach ($allowedStatuses as $val => $lbl)
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
            <div id="line-items">
                @foreach ($invoice->items as $i => $item)
                    <div class="row g-2 mb-2 line-item">
                        <div class="col-md-5"><input type="text" name="items[{{ $i }}][description]" class="form-control form-control-sm" value="{{ old("items.$i.description", $item->description) }}" placeholder="Description" required></div>
                        <div class="col-md-2"><input type="number" name="items[{{ $i }}][quantity]" class="form-control form-control-sm" value="{{ old("items.$i.quantity", $item->quantity) }}" min="1" required></div>
                        <div class="col-md-3"><input type="number" name="items[{{ $i }}][unit_price]" class="form-control form-control-sm" step="0.01" min="0" value="{{ old("items.$i.unit_price", $item->unit_price) }}" placeholder="Unit price" required></div>
                        <div class="col-md-2"><button type="button" class="btn btn-sm btn-outline-danger remove-item"><i class="bi bi-trash"></i></button></div>
                    </div>
                @endforeach
            </div>
            <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="add-item"><i class="bi bi-plus-lg me-1"></i> Add Line</button>
        @endif
    </x-adminlte.partials.form-card>

    @if (!$locked)
        @push('scripts')
        <script>
            let idx = {{ $invoice->items->count() }};
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
    @endif
@stop
