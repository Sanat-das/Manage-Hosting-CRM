@php
    $cycles = $cycles ?? \App\Models\Product::BILLING_CYCLES;
    $currency = $currency ?? 'INR';
    $product = $product ?? null;
    $isBundle = (bool) ($product?->isBundle() ?? false);

    $cycleMonths = [
        'free' => null, 'one_time' => null, 'monthly' => 1, 'quarterly' => 3,
        'semi_annual' => 6, 'annual' => 12, 'biennial' => 24, 'triennial' => 36,
    ];
    $cycleSubLabel = [
        'free' => 'Free', 'one_time' => 'One time', 'monthly' => 'Per month', 'quarterly' => 'Per 3 months',
        'semi_annual' => 'Per 6 months', 'annual' => 'Per year', 'biennial' => 'Per 2 years', 'triennial' => 'Per 3 years',
    ];

    $pricingRow = static function (string $cycle) use ($product) {
        return $product?->pricing->firstWhere('billing_cycle', $cycle);
    };

    $cycleEnabled = static function (string $cycle) use ($product, $pricingRow, $cycles): bool {
        if ($cycle === 'free') {
            return true;
        }

        // On a fresh create form every cycle is offered; on edit the toggle
        // reflects whether a price is stored (old() wins after validation).
        return $product === null || filled(old("pricing.$cycle.price", $pricingRow($cycle)?->price));
    };

    $gstEnabled = (bool) old('gst_enabled', $product?->gst_enabled ?? false);
    $gstType = (string) old('gst_type', $product?->gst_type ?? 'standard');

    $paymentType = (string) old('payment_type', $product?->payment_type ?? 'recurring');
    $quantityBehaviour = (string) old('quantity_behaviour', $product?->quantity_behaviour ?? 'none');
    $recurringCyclesLimit = old('recurring_cycles_limit', $product?->recurring_cycles_limit ?? 0);
    $autoTerminateValue = old('auto_terminate_value', $product?->auto_terminate_value ?? 0);
    $autoTerminateUnit = (string) old('auto_terminate_unit', $product?->auto_terminate_unit ?? 'days');
    $prorataEnabled = (bool) old('prorata_enabled', $product?->prorata_enabled ?? false);
    $prorataDate = old('prorata_date', $product?->prorata_date);
    $prorataChargeNextMonth = (bool) old('prorata_charge_next_month', $product?->prorata_charge_next_month ?? false);
    $earlyRenewalMode = (string) old('early_renewal_mode', $product?->early_renewal_mode ?? 'default');
    $earlyRenewalDays = old('early_renewal_days', $product?->early_renewal_days ?? []) ?? [];
@endphp

{{-- Pricing Configuration: payment type, the billing-cycle matrix and the
     billing behaviour settings. Included INSIDE the product form on both
     create and edit, so every input submits with the single save button.
     Effective-monthly and savings badges are computed live in the browser and
     never write back to the stored prices. --}}
<div class="pricing-configuration">
    <p class="text-muted small">
        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
        Set a price and setup fee per billing cycle — turn a cycle off to hide it at checkout.
    </p>

    @if ($isBundle)
        <x-adminlte-alert theme="info" dismissible>
            <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
            This is a bundle product — its pricing is derived from the component products' pricing.
        </x-adminlte-alert>
    @endif

    {{-- Payment type + the billing-cycle matrix (the primary focus) --}}
    <h6 class="mb-2"><i class="bi bi-tag me-1"></i>Payment Type</h6>
    <div class="btn-group mb-3" role="group" aria-label="Payment type">
        @foreach (['free' => 'Free', 'one_time' => 'One Time', 'recurring' => 'Recurring'] as $value => $label)
            <input type="radio" class="btn-check" name="payment_type" id="payment-type-{{ $value }}"
                   value="{{ $value }}" @checked($paymentType === $value)>
            <label class="btn btn-outline-secondary btn-sm" for="payment-type-{{ $value }}">{{ $label }}</label>
        @endforeach
    </div>

    <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="mb-0"><i class="bi bi-currency-exchange me-1"></i>Currency &amp; Billing Cycles</h6>
        <span class="badge bg-secondary">{{ $currency }}</span>
    </div>

    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th style="min-width: 140px;">Cycle</th>
                    <th class="text-end" style="min-width: 130px;">Price</th>
                    <th class="text-end" style="min-width: 130px;">Setup fee</th>
                    <th style="min-width: 130px;">Effective / month</th>
                    <th style="min-width: 90px;">Savings</th>
                    <th class="text-center" style="width: 70px;">Enable</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($cycles as $cycle => $cycleLabel)
                    @php
                        $isFree = $cycle === 'free';
                        $enabled = $cycleEnabled($cycle);
                    @endphp
                    <tr class="cycle-row {{ $enabled ? '' : 'cycle-card-disabled' }}" data-cycle="{{ $cycle }}">
                        <td>
                            <strong>{{ $cycleLabel }}</strong>
                            <div class="small text-muted">{{ $cycleSubLabel[$cycle] }}</div>
                        </td>
                        <td class="text-end">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text px-1">₹</span>
                                <input type="number" step="0.01" min="0"
                                       name="pricing[{{ $cycle }}][price]"
                                       class="form-control text-end cycle-price"
                                       value="{{ old("pricing.$cycle.price", $pricingRow($cycle)?->price) }}"
                                       placeholder="Price" data-cycle-input="{{ $cycle }}" @disabled(! $enabled)>
                            </div>
                        </td>
                        <td class="text-end">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text px-1">₹</span>
                                <input type="number" step="0.01" min="0"
                                       name="pricing[{{ $cycle }}][setup_fee]"
                                       class="form-control text-end cycle-setup"
                                       value="{{ old("pricing.$cycle.setup_fee", $pricingRow($cycle)?->setup_fee) }}"
                                       placeholder="0" data-cycle-input="{{ $cycle }}" @disabled(! $enabled)>
                            </div>
                        </td>
                        <td class="text-muted small cycle-effective"></td>
                        <td>
                            <span class="badge text-bg-success cycle-savings d-none"></span>
                        </td>
                        <td class="text-center">
                            @if ($isFree)
                                <span class="badge bg-success">Free</span>
                            @else
                                <div class="form-check form-switch d-inline-block mb-0">
                                    <input class="form-check-input cycle-toggle" type="checkbox" role="switch"
                                           id="cycle-enabled-{{ $cycle }}" data-cycle="{{ $cycle }}"
                                           @checked($enabled) title="Enable this billing cycle at checkout">
                                    <label class="form-check-label" for="cycle-enabled-{{ $cycle }}"></label>
                                </div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- GST settings (always visible in the tab) --}}
    <div class="border-top pt-3 mt-4">
        <h6 class="mb-2"><i class="bi bi-percent me-1"></i>GST settings</h6>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="gst_enabled" value="1"
                   id="gst_enabled" @checked($gstEnabled)>
            <label class="form-check-label" for="gst_enabled">Apply per-product GST rates</label>
        </div>
        <div class="row">
            <div class="col-md-4">
                <x-adminlte-select name="gst_type" label="GST type">
                    @foreach ($gstTypes as $value => $label)
                        <option value="{{ $value }}" @selected($gstType === $value)>{{ $label }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-4">
                <x-adminlte-input name="gst_rate" type="number" step="0.01" min="0" max="100"
                                  label="GST rate (%)" value="{{ old('gst_rate', $product?->gst_rate) }}" />
            </div>
            <div class="col-md-4">
                <x-adminlte-input name="cgst_rate" type="number" step="0.01" min="0" max="100"
                                  label="CGST rate (%)" value="{{ old('cgst_rate', $product?->cgst_rate) }}" />
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="sgst_rate" type="number" step="0.01" min="0" max="100"
                                  label="SGST rate (%)" value="{{ old('sgst_rate', $product?->sgst_rate) }}" />
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="igst_rate" type="number" step="0.01" min="0" max="100"
                                  label="IGST rate (%)" value="{{ old('igst_rate', $product?->igst_rate) }}" />
            </div>
        </div>
    </div>

    {{-- Secondary settings, collapsed until needed --}}
    <div class="d-flex flex-wrap gap-2 mt-4">
        <a class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" href="#collapse-quantity"
           role="button" aria-expanded="false" aria-controls="collapse-quantity">
            <i class="bi bi-123 me-1"></i> Quantity &amp; Behaviour
        </a>
        <a class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" href="#collapse-recurring"
           role="button" aria-expanded="false" aria-controls="collapse-recurring">
            <i class="bi bi-arrow-repeat me-1"></i> Recurring &amp; Fixed-Term
        </a>
        <a class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" href="#collapse-prorata"
           role="button" aria-expanded="{{ $prorataEnabled ? 'true' : 'false' }}" aria-controls="collapse-prorata">
            <i class="bi bi-calendar2-range me-1"></i> Prorata Billing
        </a>
        <a class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" href="#collapse-early-renewal"
           role="button" aria-expanded="{{ $earlyRenewalMode === 'custom' ? 'true' : 'false' }}" aria-controls="collapse-early-renewal">
            <i class="bi bi-clock-history me-1"></i> Early Renewal
        </a>
        <a class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" href="#advanced-billing-settings"
           role="button" aria-expanded="false" aria-controls="advanced-billing-settings">
            <i class="bi bi-sliders me-1"></i> Advanced (Promos)
        </a>
    </div>

    <div class="collapse mt-3" id="collapse-quantity">
        <div class="border rounded p-3 mb-3">
            <div class="list-group list-group-flush">
                @foreach ([
                    'none' => ['No', 'Sold without a quantity selector.'],
                    'multiple_services' => ['Multiple Services', 'Each quantity is an independent service.'],
                    'scaling' => ['Scaling Service', 'The quantity scales a single service.'],
                ] as $value => [$label, $description])
                    <label class="list-group-item d-flex gap-2 align-items-center py-1 px-2">
                        <input type="radio" class="form-check-input m-0" name="quantity_behaviour"
                               id="qty-{{ $value }}" value="{{ $value }}" @checked($quantityBehaviour === $value)>
                        <span>
                            <strong class="d-block small">{{ $label }}</strong>
                            <span class="small text-muted">{{ $description }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
        </div>
    </div>

    <div class="collapse mt-3" id="collapse-recurring" data-payment-type-field="recurring"
         @if ($paymentType !== 'recurring') style="display:none" @endif>
        <div class="border rounded p-3 mb-3">
            <label class="form-label small text-muted mb-1" for="recurring-cycles-limit">Recurring Cycles Limit</label>
            <input type="number" min="0" id="recurring-cycles-limit" name="recurring_cycles_limit"
                   class="form-control form-control-sm" value="{{ $recurringCyclesLimit }}" placeholder="0">
            <div class="form-text text-muted">0 = Unlimited. E.g. 12 invoices 12 cycles, then ends.</div>

            <label class="form-label small text-muted mb-1 mt-3" for="auto-terminate-value">Auto Termination / Fixed Term</label>
            <div class="input-group input-group-sm">
                <input type="number" min="0" id="auto-terminate-value" name="auto_terminate_value"
                       class="form-control" value="{{ $autoTerminateValue }}" placeholder="0">
                <select name="auto_terminate_unit" class="form-select" style="max-width: 96px;">
                    @foreach (['days' => 'Days', 'months' => 'Months', 'years' => 'Years'] as $value => $label)
                        <option value="{{ $value }}" @selected($autoTerminateUnit === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-text text-muted">0 = Disabled. E.g. 30 Days terminates 30 days after activation.</div>
        </div>
    </div>

    <div class="collapse {{ $prorataEnabled ? 'show' : '' }} mt-3" id="collapse-prorata">
        <div class="border rounded p-3 mb-3">
            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" name="prorata_enabled" value="1"
                       id="prorata-enabled" @checked($prorataEnabled)>
                <label class="form-check-label" for="prorata-enabled">Enable prorated billing for this product</label>
            </div>
            <div class="row g-3" data-prorata-fields>
                <div class="col-md-4">
                    <label class="form-label small" for="prorata-date">Prorata Date</label>
                    <input type="number" min="1" max="28" id="prorata-date" name="prorata_date"
                           class="form-control" value="{{ $prorataDate }}" placeholder="e.g. 15"
                           @disabled(! $prorataEnabled)>
                    <div class="form-text text-muted">Day of the month (1&ndash;28) prorated charges are calculated on.</div>
                </div>
                <div class="col-md-4">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" name="prorata_charge_next_month" value="1"
                               id="prorata-charge-next-month" @checked($prorataChargeNextMonth)
                               @disabled(! $prorataEnabled)>
                        <label class="form-check-label" for="prorata-charge-next-month">Charge Next Month</label>
                    </div>
                    <div class="form-text text-muted">The first prorated invoice also bills the following month up front.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="collapse {{ $earlyRenewalMode === 'custom' ? 'show' : '' }} mt-3" id="collapse-early-renewal">
        <div class="border rounded p-3 mb-3">
            <div class="btn-group mb-2" role="group" aria-label="Early renewal configuration">
                <input type="radio" class="btn-check" name="early_renewal_mode" id="er-mode-default" value="default"
                       @checked($earlyRenewalMode === 'default')>
                <label class="btn btn-outline-secondary btn-sm" for="er-mode-default">Use System Default</label>
                <input type="radio" class="btn-check" name="early_renewal_mode" id="er-mode-custom" value="custom"
                       @checked($earlyRenewalMode === 'custom')>
                <label class="btn btn-outline-secondary btn-sm" for="er-mode-custom">Product-Specific Configuration</label>
            </div>
            <div class="row g-3" data-early-renewal-fields>
                @foreach (['monthly', 'quarterly', 'semi_annual', 'annual', 'biennial', 'triennial'] as $erCycle)
                    <div class="col-md-4">
                        <label class="form-label small" for="er-{{ $erCycle }}">
                            {{ \App\Models\Product::BILLING_CYCLES[$erCycle] ?? ucfirst(str_replace('_', ' ', $erCycle)) }}
                        </label>
                        <div class="input-group input-group-sm">
                            <input type="number" min="0" max="365" id="er-{{ $erCycle }}"
                                   name="early_renewal_days[{{ $erCycle }}]"
                                   class="form-control" value="{{ $earlyRenewalDays[$erCycle] ?? '' }}"
                                   placeholder="0" @disabled($earlyRenewalMode !== 'custom')>
                            <span class="input-group-text">days</span>
                        </div>
                    </div>
                @endforeach
            </div>
            <p class="form-text text-muted mb-0">
                Days before the due date during which an early renewal is allowed. E.g. 31 days lets the client renew up to 31 days early.
            </p>
        </div>
    </div>

    <div class="collapse mt-3" id="advanced-billing-settings">
        <div class="border rounded p-3 mb-3">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Cycle</th>
                            <th class="text-end">Promo price</th>
                            <th>Promo start</th>
                            <th>Promo end</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($cycles as $cycle => $cycleLabel)
                            @php $enabled = $cycleEnabled($cycle); @endphp
                            <tr>
                                <td><strong>{{ $cycleLabel }}</strong></td>
                                <td class="text-end">
                                    <input type="number" step="0.01" min="0"
                                           name="pricing[{{ $cycle }}][promo_price]"
                                           class="form-control form-control-sm text-end"
                                           value="{{ old("pricing.$cycle.promo_price", $pricingRow($cycle)?->promo_price) }}"
                                           placeholder="0.00" data-cycle-input="{{ $cycle }}" @disabled(! $enabled)>
                                </td>
                                <td>
                                    <input type="date"
                                           name="pricing[{{ $cycle }}][promo_start]"
                                           class="form-control form-control-sm"
                                           value="{{ old("pricing.$cycle.promo_start", $pricingRow($cycle)?->promo_start?->format('Y-m-d')) }}"
                                           data-cycle-input="{{ $cycle }}" @disabled(! $enabled)>
                                </td>
                                <td>
                                    <input type="date"
                                           name="pricing[{{ $cycle }}][promo_end]"
                                           class="form-control form-control-sm"
                                           value="{{ old("pricing.$cycle.promo_end", $pricingRow($cycle)?->promo_end?->format('Y-m-d')) }}"
                                           data-cycle-input="{{ $cycle }}" @disabled(! $enabled)>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @push('js')
        <style>
            .cycle-card-disabled { opacity: .55; }
        </style>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var cycleMonths = {
                    free: null, one_time: null, monthly: 1, quarterly: 3, semi_annual: 6,
                    annual: 12, biennial: 24, triennial: 36,
                };
                var paymentTypeCycles = {
                    free: ['free'],
                    one_time: ['one_time'],
                    recurring: ['monthly', 'quarterly', 'semi_annual', 'annual', 'biennial', 'triennial'],
                };
                var allCycles = ['free', 'one_time', 'monthly', 'quarterly', 'semi_annual', 'annual', 'biennial', 'triennial'];

                function formatCurrency(value) {
                    return '₹' + Number(value).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                }

                function currentPaymentType() {
                    var checked = document.querySelector('input[name="payment_type"]:checked');
                    return checked ? checked.value : 'recurring';
                }

                function updateCycleSummary(card) {
                    if (!card) return;
                    var months = cycleMonths[card.dataset.cycle];
                    var priceEl = card.querySelector('.cycle-price');
                    var effectiveEl = card.querySelector('.cycle-effective');
                    var savingsEl = card.querySelector('.cycle-savings');
                    if (!priceEl) return;

                    var price = parseFloat(priceEl.value) || 0;

                    if (months && months > 1 && price > 0) {
                        var effective = price / months;
                        effectiveEl.textContent = formatCurrency(effective) + ' / mo';

                        var monthlyPriceEl = document.querySelector('input[name="pricing[monthly][price]"]');
                        var monthly = monthlyPriceEl ? (parseFloat(monthlyPriceEl.value) || 0) : 0;
                        var pct = monthly > 0 ? Math.round((1 - effective / monthly) * 100) : 0;

                        if (pct > 0) {
                            savingsEl.textContent = pct + '% OFF';
                            savingsEl.classList.remove('d-none');
                        } else {
                            savingsEl.classList.add('d-none');
                        }
                    } else {
                        effectiveEl.textContent = '';
                        savingsEl.classList.add('d-none');
                    }
                }

                // A cycle is submittable when its payment type is active AND its
                // enable toggle is on (free cycles are always on).
                function applyCycleState(cycle) {
                    var inType = paymentTypeCycles[currentPaymentType()].indexOf(cycle) !== -1;
                    var card = document.querySelector('.cycle-row[data-cycle="' + cycle + '"]');
                    var toggle = card ? card.querySelector('.cycle-toggle') : null;
                    var enabled = inType && (toggle ? toggle.checked : true);

                    if (card) {
                        card.classList.toggle('d-none', !inType);
                        card.classList.toggle('cycle-card-disabled', !enabled);
                    }

                    document.querySelectorAll('[data-cycle-input="' + cycle + '"]').forEach(function (input) {
                        input.disabled = !enabled;
                    });

                    updateCycleSummary(card);
                }

                // The set of cycles currently enabled on the product (visible in
                // the active payment type and toggled on) — mirrored by the
                // configurable-option pricing section via a custom event.
                function enabledCycles() {
                    return allCycles.filter(function (cycle) {
                        var inType = paymentTypeCycles[currentPaymentType()].indexOf(cycle) !== -1;
                        if (!inType) return false;
                        var card = document.querySelector('.cycle-row[data-cycle="' + cycle + '"]');
                        var toggle = card ? card.querySelector('.cycle-toggle') : null;
                        return toggle ? toggle.checked : true;
                    });
                }

                function dispatchCycleEnabled() {
                    document.dispatchEvent(new CustomEvent('cycle-enabled-change', { detail: enabledCycles() }));
                }

                // Enable toggles + payment-type radios drive the matrix.
                document.querySelectorAll('.cycle-toggle').forEach(function (toggle) {
                    toggle.addEventListener('change', function () {
                        applyCycleState(toggle.dataset.cycle);
                        dispatchCycleEnabled();
                    });
                });

                document.querySelectorAll('input[name="payment_type"]').forEach(function (input) {
                    input.addEventListener('change', function () {
                        allCycles.forEach(applyCycleState);

                        // Recurring-only settings (cycles limit / auto-termination).
                        var recurringOnly = currentPaymentType() === 'recurring';
                        document.querySelectorAll('[data-payment-type-field="recurring"]').forEach(function (el) {
                            el.style.display = recurringOnly ? '' : 'none';
                        });

                        document.dispatchEvent(new CustomEvent('payment-type-change', { detail: currentPaymentType() }));
                        dispatchCycleEnabled();
                    });
                });

                document.querySelectorAll('.cycle-row').forEach(function (card) {
                    var priceInput = card.querySelector('.cycle-price');
                    if (priceInput) {
                        priceInput.addEventListener('input', function () { updateCycleSummary(card); });
                    }
                });

                var monthlyPriceEl = document.querySelector('input[name="pricing[monthly][price]"]');
                if (monthlyPriceEl) {
                    monthlyPriceEl.addEventListener('input', function () {
                        document.querySelectorAll('.cycle-row').forEach(updateCycleSummary);
                    });
                }

                // Prorata: the fields only accept input while enabled.
                var prorataToggle = document.querySelector('#prorata-enabled');
                if (prorataToggle) {
                    prorataToggle.addEventListener('change', function () {
                        var on = prorataToggle.checked;
                        document.querySelectorAll('[data-prorata-fields] input').forEach(function (input) {
                            input.disabled = !on;
                        });
                    });
                }

                // Early renewal: per-cycle windows only when product-specific.
                function syncEarlyRenewal() {
                    var custom = document.querySelector('input[name="early_renewal_mode"]:checked');
                    var on = custom ? custom.value === 'custom' : false;
                    document.querySelectorAll('[data-early-renewal-fields] input').forEach(function (input) {
                        input.disabled = !on;
                    });
                }
                document.querySelectorAll('input[name="early_renewal_mode"]').forEach(function (input) {
                    input.addEventListener('change', syncEarlyRenewal);
                });

                allCycles.forEach(applyCycleState);
                syncEarlyRenewal();
                dispatchCycleEnabled();
            });
        </script>
    @endpush
</div>
