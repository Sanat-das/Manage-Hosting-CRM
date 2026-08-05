@php
    $index = $index ?? '__index__';
    $value = $value ?? [];
    $cycles = $cycles ?? \App\Models\Product::BILLING_CYCLES;
@endphp

<div class="option-value-row border rounded p-3 mb-3 bg-light">
    <div class="row g-2">
        <div class="col-md-5">
            <label class="form-label small text-muted">Label</label>
            <input type="text" name="values[{{ $index }}][label]" class="form-control form-control-sm"
                   value="{{ $value['label'] ?? '' }}" placeholder="e.g. 10 GB">
        </div>
        <div class="col-md-3">
            <label class="form-label small text-muted">Sort order</label>
            <input type="number" min="0" name="values[{{ $index }}][sort_order]"
                   class="form-control form-control-sm" value="{{ $value['sort_order'] ?? '' }}">
        </div>
        <div class="col-md-4 d-flex align-items-end justify-content-end">
            <button type="button" class="btn btn-sm btn-outline-danger remove-option-value">
                <i class="bi bi-trash me-1"></i> Remove
            </button>
        </div>
    </div>
    <div class="mt-2">
        <label class="form-label small text-muted">Price modifier per billing cycle</label>
        <div class="row g-2">
            @foreach ($cycles as $cycle => $cycleLabel)
                <div class="col-md-3 col-lg-2">
                    <label class="form-label small text-muted">{{ $cycleLabel }}</label>
                    <input type="number" step="0.01" min="0"
                           name="values[{{ $index }}][pricing][{{ $cycle }}][price_modifier]"
                           class="form-control form-control-sm"
                           value="{{ $value['pricing'][$cycle]['price_modifier'] ?? '' }}" placeholder="0.00">
                </div>
            @endforeach
        </div>
    </div>
</div>
