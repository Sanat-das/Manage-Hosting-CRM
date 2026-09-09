@php
    $index = $index ?? '__index__';
    $value = $value ?? [];
    // Only the cycles an order can actually be placed on (Order::BILLING_CYCLES).
    // `free` and `triennial` exist on product_pricing but can never be an
    // ordered cycle, so a price entered against them charges nobody.
    $cycles = $cycles ?? \App\Models\Product::DEFAULT_CYCLES;
    $extraCycles = collect($cycles)->except('monthly');
    $rowPrices = $value['pricing'] ?? [];
    // Show the extra-cycle panel open when one of them is already priced,
    // so an override can never hide behind a collapsed toggle.
    $hasExtraPricing = $extraCycles->keys()->contains(
        fn ($cycle) => ($rowPrices[$cycle]['price_modifier'] ?? '') !== ''
    );
@endphp

<div class="option-value-row border rounded p-2 mb-2 bg-light">
    <div class="row g-2 align-items-end">
        <div class="col-auto d-flex flex-column align-items-center pt-3">
            <button type="button" class="btn btn-link btn-sm p-0 lh-1 move-value-up" title="Move up" aria-label="Move up">
                <i class="bi bi-caret-up-fill" aria-hidden="true"></i>
            </button>
            <button type="button" class="btn btn-link btn-sm p-0 lh-1 move-value-down" title="Move down" aria-label="Move down">
                <i class="bi bi-caret-down-fill" aria-hidden="true"></i>
            </button>
        </div>

        <div class="col-md-5">
            <label class="form-label small text-muted mb-1" for="value-label-{{ $index }}">Label</label>
            <input type="text" id="value-label-{{ $index }}" name="values[{{ $index }}][label]"
                   class="form-control form-control-sm" value="{{ $value['label'] ?? '' }}"
                   placeholder="e.g. 10 GB">
        </div>

        <div class="col-md-3">
            <label class="form-label small text-muted mb-1" for="value-monthly-{{ $index }}">
                Price / month <span class="text-muted">(₹)</span>
            </label>
            <input type="number" step="0.01" min="0" id="value-monthly-{{ $index }}"
                   name="values[{{ $index }}][pricing][monthly][price_modifier]"
                   class="form-control form-control-sm"
                   value="{{ $rowPrices['monthly']['price_modifier'] ?? '' }}" placeholder="0.00">
        </div>

        <div class="col-md-2">
            <div class="form-check mb-1">
                <input class="form-check-input default-value-radio" type="radio" name="default_value_index"
                       value="{{ $index }}" id="value-default-{{ $index }}"
                       @checked(filter_var($value['is_default'] ?? false, FILTER_VALIDATE_BOOLEAN))>
                <label class="form-check-label small" for="value-default-{{ $index }}">Default</label>
            </div>
        </div>

        <div class="col-md-1 text-end">
            <button type="button" class="btn btn-sm btn-outline-danger remove-option-value" title="Remove value"
                    aria-label="Remove value">
                <i class="bi bi-trash" aria-hidden="true"></i>
            </button>
        </div>
    </div>

    {{-- Other cycles are derived from the monthly price (annual = monthly x 12)
         unless one is entered here, so the common case needs no input at all. --}}
    <div class="mt-1 ps-4">
        <button class="btn btn-link btn-sm p-0 text-decoration-none" type="button" data-bs-toggle="collapse"
                data-bs-target="#value-cycles-{{ $index }}" aria-expanded="{{ $hasExtraPricing ? 'true' : 'false' }}"
                aria-controls="value-cycles-{{ $index }}">
            <i class="bi bi-chevron-expand me-1" aria-hidden="true"></i>
            Different price on other cycles
            @if ($hasExtraPricing)
                <span class="badge bg-info ms-1">overridden</span>
            @endif
        </button>

        <div class="collapse {{ $hasExtraPricing ? 'show' : '' }}" id="value-cycles-{{ $index }}">
            <div class="row g-2 mt-1">
                @foreach ($extraCycles as $cycle => $cycleLabel)
                    <div class="col-6 col-md-3 col-lg-2">
                        <label class="form-label small text-muted mb-1" for="value-{{ $cycle }}-{{ $index }}">
                            {{ $cycleLabel }}
                        </label>
                        <input type="number" step="0.01" min="0" id="value-{{ $cycle }}-{{ $index }}"
                               name="values[{{ $index }}][pricing][{{ $cycle }}][price_modifier]"
                               class="form-control form-control-sm"
                               value="{{ $rowPrices[$cycle]['price_modifier'] ?? '' }}"
                               placeholder="{{ $cycle === 'one_time' ? 'not derived' : 'auto' }}">
                    </div>
                @endforeach
            </div>
            <p class="text-muted small mb-0 mt-1">
                Blank uses the monthly price multiplied by the cycle&rsquo;s months.
                One&nbsp;Time spans no months, so it charges nothing unless you set it here.
            </p>
        </div>
    </div>

    {{-- Display order follows the row order above; renumbered on every move. --}}
    <input type="hidden" class="value-sort-order" name="values[{{ $index }}][sort_order]"
           value="{{ $value['sort_order'] ?? 0 }}">
</div>
