@php
    $continuousTypes = $continuousTypes ?? \App\Models\ProductOptionGroup::CONTINUOUS_TYPES;
@endphp

{{-- Configurable options at product creation, mirroring the edit page's
     "Attach an option group" picker: pick a catalog group and attach it, then
     configure it type-aware. Continuous groups (slider / number / quantity)
     get a per-cycle unit-price grid; dropdown / radio / checkbox groups get a
     per-value pricing table. Attached groups submit with the product form;
     anything never attached stays hidden AND its inputs disabled, so it never
     reaches the payload. --}}
<x-adminlte-card title="Configurable options" icon="bi bi-sliders" class="mt-3" body-class="p-3">
    <p class="text-muted small">
        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
        Attach an option group, then set its pricing. Slider / number / quantity
        groups are priced per unit; dropdown / radio / checkbox groups keep
        per-value pricing.
    </p>

    <div class="row g-2 align-items-end mb-3">
        <div class="col-md-6">
            <label class="form-label small text-muted mb-1" for="option-group-picker">
                Attach an option group
            </label>
            <select id="option-group-picker" class="form-select form-select-sm">
                <option value="">— Select option group —</option>
                @foreach ($availableGroups as $group)
                    <option value="{{ $group->id }}" @disabled(! empty(old("option_groups.{$group->id}.selected")))>
                        {{ $group->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3">
            <button type="button" id="option-group-attach-btn" class="btn btn-sm btn-primary w-100">
                <i class="bi bi-link-45deg me-1"></i> Attach
            </button>
        </div>
    </div>

    @php
        $createPaymentType = (string) old('payment_type', 'recurring');
        $createCycle = $createPaymentType === 'one_time'
            ? 'one_time'
            : (string) old('billing_cycle', 'monthly');
        $optionPricingHidden = $createPaymentType === 'free';
    @endphp

    @forelse ($availableGroups as $group)
        @php
            $isContinuous = in_array($group->type, $continuousTypes, true);
            $attached = ! empty(old("option_groups.{$group->id}.selected"));
            $overrideOn = $attached && ! empty(old("option_groups.{$group->id}.override_defaults"));
        @endphp

        <div id="option-card-{{ $group->id }}"
             class="option-link-card border rounded p-2 mb-2 @if (! $attached) d-none @endif">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <div>
                    <strong>{{ $group->name }}</strong>
                    <span class="badge text-bg-info ms-1">{{ ucfirst($group->type) }}</span>
                </div>
                <button type="button" class="btn btn-sm btn-outline-danger option-group-detach-btn"
                        data-option-card="option-card-{{ $group->id }}">
                    <i class="bi bi-unlink me-1"></i> Detach
                </button>
            </div>

            {{-- Marks the group as selected for the store payload; disabled
                 (not submitted) whenever the group is not attached. --}}
            <input type="hidden" name="option_groups[{{ $group->id }}][selected]" value="1"
                   class="option-group-input" @disabled(! $attached)>

            <div class="form-check form-switch mb-2">
                <input class="form-check-input option-group-input" type="checkbox" role="switch"
                       name="option_groups[{{ $group->id }}][customer_editable]" value="1"
                       id="create-editable-{{ $group->id }}"
                       @checked(! empty(old("option_groups.{$group->id}.customer_editable")))
                       @disabled(! $attached)>
                <label class="form-check-label" for="create-editable-{{ $group->id }}">
                    Allow the customer to edit this option at checkout
                </label>
            </div>

            @if ($isContinuous)
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input option-group-input option-input-overrides-toggle" type="checkbox"
                           role="switch"
                           name="option_groups[{{ $group->id }}][override_defaults]" value="1"
                           id="create-override-{{ $group->id }}"
                           @checked($overrideOn) @disabled(! $attached)>
                    <label class="form-check-label" for="create-override-{{ $group->id }}">
                        Override the group's Min / Max / Step / Placeholder for this product
                    </label>
                </div>
                <div class="row g-2 mb-2 option-input-overrides">
                    <div class="col-md-3 col-lg-2">
                        <label class="form-label small text-muted">Min value</label>
                        <input type="number" step="0.01" min="0"
                               class="form-control form-control-sm option-override-input"
                               name="option_groups[{{ $group->id }}][input_min]"
                               value="{{ old("option_groups.{$group->id}.input_min", $group->input_min) }}"
                               placeholder="0" @disabled(! $overrideOn)>
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <label class="form-label small text-muted">Max value</label>
                        <input type="number" step="0.01" min="0"
                               class="form-control form-control-sm option-override-input"
                               name="option_groups[{{ $group->id }}][input_max]"
                               value="{{ old("option_groups.{$group->id}.input_max", $group->input_max) }}"
                               placeholder="0" @disabled(! $overrideOn)>
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <label class="form-label small text-muted">Step</label>
                        <input type="number" step="0.01" min="0"
                               class="form-control form-control-sm option-override-input"
                               name="option_groups[{{ $group->id }}][input_step]"
                               value="{{ old("option_groups.{$group->id}.input_step", $group->input_step) }}"
                               placeholder="0" @disabled(! $overrideOn)>
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <label class="form-label small text-muted">Placeholder</label>
                        <input type="text"
                               class="form-control form-control-sm option-override-input"
                               name="option_groups[{{ $group->id }}][input_placeholder]"
                               value="{{ old("option_groups.{$group->id}.input_placeholder", $group->input_placeholder) }}"
                               placeholder="e.g. Enter amount" @disabled(! $overrideOn)>
                    </div>
                </div>
                <p class="text-muted small mb-2">
                    <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                    Values shown are the group's defaults. Turn the switch on to set per-product
                    values — blank fields keep the group's.
                </p>
                @unless ($optionPricingHidden)
                    <div class="row g-2 mt-1" data-option-pricing>
                        @foreach ($cycles as $cycle => $cycleLabel)
                            <div class="col-md-3 col-lg-2" data-option-cycle="{{ $cycle }}">
                                <label class="form-label small text-muted">{{ $cycleLabel }}</label>
                                <input type="number" step="0.01" min="0"
                                       class="form-control form-control-sm option-group-input option-cycle-input"
                                       name="option_groups[{{ $group->id }}][unit_pricing][{{ $cycle }}]"
                                       value="{{ old("option_groups.{$group->id}.unit_pricing.{$cycle}") }}"
                                       placeholder="0.00" @disabled(! $attached)>
                            </div>
                        @endforeach
                    </div>
                    <p class="text-muted small mb-0 mt-2">
                        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                        Unit price per enabled billing cycle — the customer's chosen value (per the
                        group's Min / Max / Step) multiplies the price of the cycle they select.
                    </p>
                @else
                    <p class="text-muted small mb-0 mt-2">
                        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                        Free product — option groups are attachable but no option pricing is charged.
                    </p>
                @endunless
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="min-width: 170px;">Label</th>
                                @unless ($optionPricingHidden)
                                    @foreach ($cycles as $cycle => $cycleLabel)
                                        <th class="text-end" style="min-width: 100px;" data-option-cycle="{{ $cycle }}" data-option-pricing>{{ $cycleLabel }}</th>
                                    @endforeach
                                @endunless
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($group->values as $value)
                                <tr>
                                    <td>{{ $value->label }}</td>
                                    @unless ($optionPricingHidden)
                                        @foreach ($cycles as $cycle => $cycleLabel)
                                            <td class="text-end" data-option-cycle="{{ $cycle }}" data-option-pricing>
                                                <input type="number" step="0.01" min="0"
                                                       class="form-control form-control-sm text-end option-group-input option-cycle-input"
                                                       name="option_groups[{{ $group->id }}][pricing][{{ $value->id }}][{{ $cycle }}]"
                                                       value="{{ old("option_groups.{$group->id}.pricing.{$value->id}.{$cycle}", $value->pricing->firstWhere('billing_cycle', $cycle)?->price_modifier) }}"
                                                       placeholder="0.00" @disabled(! $attached)>
                                            </td>
                                        @endforeach
                                    @endunless
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ $optionPricingHidden ? 1 : count($cycles) + 1 }}" class="text-center text-muted py-3">No values on this group yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @empty
        <p class="text-muted mb-0">No option groups available.</p>
    @endforelse

    @push('js')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var picker = document.getElementById('option-group-picker');
                var attachBtn = document.getElementById('option-group-attach-btn');

                function syncOverrideFields(block) {
                    var attached = !block.classList.contains('d-none');
                    var toggle = block.querySelector('.option-input-overrides-toggle');
                    var on = attached && toggle && toggle.checked;
                    block.querySelectorAll('.option-override-input').forEach(function (input) {
                        input.disabled = !on;
                    });
                }

                // Option pricing mirrors the billing cycles ENABLED in the
                // product's pricing matrix above (via the cycle-enabled-change
                // event): one unit-price field / pricing column per enabled
                // cycle, shown/submitted together.
                var enabledOptionCycles = ['monthly', 'quarterly', 'semi_annual', 'annual', 'biennial', 'triennial'];

                function syncOptionCycles() {
                    document.querySelectorAll('[data-option-cycle]').forEach(function (el) {
                        var card = el.closest('.option-link-card');
                        var attached = card && !card.classList.contains('d-none');
                        var on = attached && enabledOptionCycles.indexOf(el.dataset.optionCycle) !== -1;
                        el.style.display = on ? '' : 'none';
                        el.querySelectorAll('input').forEach(function (input) {
                            input.disabled = !on;
                        });
                    });
                }

                document.addEventListener('cycle-enabled-change', function (e) {
                    enabledOptionCycles = e.detail || [];
                    syncOptionCycles();
                });

                function setAttached(groupId, on) {
                    var block = document.getElementById('option-card-' + groupId);
                    if (!block) return;

                    block.classList.toggle('d-none', !on);
                    block.querySelectorAll('.option-group-input').forEach(function (input) {
                        input.disabled = !on;
                    });
                    syncOverrideFields(block);
                    syncOptionCycles();

                    var option = picker.querySelector('option[value="' + groupId + '"]');
                    if (option) option.disabled = on;
                }

                // Free products keep the groups attachable but never charge for
                // options: the pricing fields are hidden and dropped from the
                // payload when the payment type is Free.
                function syncOptionPricing(paymentType) {
                    var free = paymentType === 'free';
                    document.querySelectorAll('[data-option-pricing]').forEach(function (el) {
                        el.style.display = free ? 'none' : '';
                        el.querySelectorAll('input').forEach(function (input) {
                            input.disabled = free || input.disabled;
                        });
                    });
                    if (!free) syncOptionCycles();
                }

                document.addEventListener('payment-type-change', function (e) {
                    syncOptionPricing(e.detail);
                });
                syncOptionPricing('{{ $createPaymentType }}');

                attachBtn.addEventListener('click', function () {
                    if (!picker.value) return;
                    setAttached(picker.value, true);
                    picker.value = '';
                });

                document.querySelectorAll('.option-group-detach-btn').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        setAttached(btn.dataset.optionCard.replace('option-card-', ''), false);
                    });
                });

                document.querySelectorAll('.option-link-card').forEach(function (block) {
                    var toggle = block.querySelector('.option-input-overrides-toggle');
                    if (toggle) {
                        toggle.addEventListener('change', function () {
                            syncOverrideFields(block);
                        });
                    }
                });

                syncOptionCycles();
            });
        </script>
    @endpush
</x-adminlte-card>
