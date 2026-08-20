@php
    $cycles = $cycles ?? \App\Models\Product::BILLING_CYCLES;
    $links = $product->optionLinks ?? collect();
    $continuousTypes = \App\Models\ProductOptionGroup::CONTINUOUS_TYPES;
    // Option pricing mirrors the product's ENABLED billing cycles (its pricing
    // ladder): one unit-price field / pricing column per enabled cycle.
    // One-time products price options as a one-time modifier; free products
    // never charge for options (the groups stay attachable, just unpriced).
    $optionPricingHidden = ($product->payment_type ?? 'recurring') === 'free';

    if ($optionPricingHidden) {
        $optionCycles = [];
    } elseif (($product->payment_type ?? 'recurring') === 'one_time') {
        $optionCycles = ['one_time'];
    } else {
        $optionCycles = $product->pricing
            ->pluck('billing_cycle')
            ->filter(fn ($cycle) => $cycle !== 'free' && $cycle !== 'one_time')
            ->values()
            ->all();

        // Products without a stored ladder keep the default cycle as a fallback.
        if ($optionCycles === []) {
            $optionCycles = [(string) ($product->billing_cycle ?? 'monthly')];
        }
    }
@endphp

{{-- Configurable options: per-link configuration only. This partial is
     included INSIDE the product update form, so every card's fields submit
     with the single "Save Changes" button, keyed by link id. The attach
     picker, the per-link "Sync values from group" forms and the detach
     confirm modals live outside the form (see edit.blade.php). --}}
<div id="option-links-sortable" data-sortable
     data-sortable-options='{"handle": ".option-link-drag-handle"}'>
@forelse ($links as $link)
    @php
        $isContinuous = $link->group && in_array($link->group->type, $continuousTypes, true);
        $linkPayload = old("option_links.{$link->id}", []);
        $overrideOn = $link->hasInputOverride() || ! empty($linkPayload['override_defaults']);
        $linkSortOrder = $linkPayload['sort_order'] ?? $link->sort_order ?? 0;
    @endphp
    <div class="option-link-card border rounded p-2 mb-2" data-link-sortable>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <div class="d-flex align-items-center gap-2">
                <span class="option-link-drag-handle text-muted" title="Drag to reorder"
                      style="cursor: grab;"><i class="bi bi-grip-vertical"></i></span>
                <div>
                    <strong>{{ $link->group?->name ?? 'Option group #'.$link->option_group_id }}</strong>
                    @if ($link->group)
                        <span class="badge bg-info ms-1">{{ ucfirst($link->group->type) }}</span>
                    @endif
                    <span class="text-muted small ms-1">{{ $link->customer_editable ? 'Customer editable' : 'Admin only' }}</span>
                </div>
            </div>
            <input type="hidden" name="option_links[{{ $link->id }}][sort_order]" class="option-link-sort-order"
                   value="{{ $linkSortOrder }}">
            <div>
                @if ($link->group && ! in_array($link->group->type, $continuousTypes, true))
                    <button type="submit" form="sync-{{ $link->id }}"
                            class="btn btn-sm btn-outline-secondary me-1">
                        <i class="bi bi-arrow-repeat me-1"></i> Sync values from group
                    </button>
                @endif
                <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal"
                        data-bs-target="#detach-link-{{ $link->id }}">
                    <i class="bi bi-unlink me-1"></i> Detach
                </button>
            </div>
        </div>

        {{-- The values payload mirrors the per-link request, nested under the
             link id: values[] carries id/label/sort_order/is_default, per-value
             pricing[keyed by link-value id][cycle]. The Default radio in each
             row is the single designator — a hidden default_value_id would go
             stale and override the radio's selection on save. --}}
        <input type="hidden" name="option_links[{{ $link->id }}][customer_editable]" value="0">

        <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" role="switch"
                   name="option_links[{{ $link->id }}][customer_editable]" value="1"
                   id="link-customer-editable-{{ $link->id }}"
                   @checked($link->customer_editable || ! empty($linkPayload['customer_editable']))>
            <label class="form-check-label" for="link-customer-editable-{{ $link->id }}">
                Allow the customer to edit this option at checkout
            </label>
        </div>

        @if ($isContinuous)
            {{-- Continuous types (slider / number / quantity) are priced per unit:
                 a unit-price grid replaces the discrete value table. Existing
                 (legacy) link values are never submitted, so they stay untouched. --}}

            {{-- Per-product override of the group's Min / Max / Step / Placeholder:
                 shows the group's values, toggles to per-product values. --}}
            <div class="form-check form-switch mb-2">
                <input class="form-check-input option-input-overrides-toggle" type="checkbox" role="switch"
                       name="option_links[{{ $link->id }}][override_defaults]" value="1"
                       id="override-defaults-{{ $link->id }}"
                       @checked($overrideOn)>
                <label class="form-check-label" for="override-defaults-{{ $link->id }}">
                    Override the group's Min / Max / Step / Placeholder for this product
                </label>
            </div>
            <div class="row g-2 mb-2 option-input-overrides">
                <div class="col-md-3 col-lg-2">
                    <label class="form-label small text-muted">Min value</label>
                    <input type="number" step="0.01" min="0"
                           class="form-control form-control-sm option-override-input"
                           name="option_links[{{ $link->id }}][input_min]"
                           value="{{ old("option_links.{$link->id}.input_min", $link->input_min ?? $link->group?->input_min) }}"
                           placeholder="0" @disabled(! $overrideOn)>
                </div>
                <div class="col-md-3 col-lg-2">
                    <label class="form-label small text-muted">Max value</label>
                    <input type="number" step="0.01" min="0"
                           class="form-control form-control-sm option-override-input"
                           name="option_links[{{ $link->id }}][input_max]"
                           value="{{ old("option_links.{$link->id}.input_max", $link->input_max ?? $link->group?->input_max) }}"
                           placeholder="0" @disabled(! $overrideOn)>
                </div>
                <div class="col-md-3 col-lg-2">
                    <label class="form-label small text-muted">Step</label>
                    <input type="number" step="0.01" min="0"
                           class="form-control form-control-sm option-override-input"
                           name="option_links[{{ $link->id }}][input_step]"
                           value="{{ old("option_links.{$link->id}.input_step", $link->input_step ?? $link->group?->input_step) }}"
                           placeholder="0" @disabled(! $overrideOn)>
                </div>
                <div class="col-md-3 col-lg-2">
                    <label class="form-label small text-muted">Placeholder</label>
                    <input type="text"
                           class="form-control form-control-sm option-override-input"
                           name="option_links[{{ $link->id }}][input_placeholder]"
                           value="{{ old("option_links.{$link->id}.input_placeholder", $link->input_placeholder ?? $link->group?->input_placeholder) }}"
                           placeholder="e.g. Enter amount" @disabled(! $overrideOn)>
                </div>
            </div>
            <p class="text-muted small mb-2">
                <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                Values shown are the group's defaults. Turn the switch on to set per-product
                values — blank fields keep the group's.
            </p>
            @unless ($optionPricingHidden)
                <div class="row g-2 mt-1">
                    @foreach ($optionCycles as $cycle)
                        <div class="col-md-3 col-lg-2">
                            <label class="form-label small text-muted">{{ $cycles[$cycle] ?? $cycle }}</label>
                            <input type="number" step="0.01" min="0"
                                   name="option_links[{{ $link->id }}][unit_pricing][{{ $cycle }}]"
                                   class="form-control form-control-sm"
                                   value="{{ old("option_links.{$link->id}.unit_pricing.{$cycle}", $link->unitPricing->firstWhere('billing_cycle', $cycle)?->price_modifier) }}"
                                   placeholder="0.00">
                        </div>
                    @endforeach
                </div>
                <p class="text-muted small mb-0">
                    <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                    Unit price per enabled billing cycle — the customer's chosen value (per the
                    group's Min / Max / Step) multiplies the price of the cycle they select.
                </p>
            @else
                <p class="text-muted small mb-0">
                    <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                    Free product — option groups are attachable but no option pricing is charged.
                </p>
            @endunless
        @elseif ($link->linkValues->isNotEmpty())
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-2">
                    <thead>
                        <tr>
                            <th style="min-width: 170px;">Label</th>
                            <th style="width: 90px;">Sort order</th>
                                    <th style="width: 70px;">Default</th>
                                    @unless ($optionPricingHidden)
                                        @foreach ($optionCycles as $cycle)
                                            <th class="text-end" style="min-width: 100px;">{{ $cycles[$cycle] ?? $cycle }}</th>
                                        @endforeach
                                    @endunless
                                    <th style="width: 60px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($link->linkValues as $index => $value)
                            <tr class="option-link-value-row">
                                <td>
                                    <input type="hidden" name="option_links[{{ $link->id }}][values][{{ $index }}][id]" value="{{ $value->id }}">
                                    <input type="text" name="option_links[{{ $link->id }}][values][{{ $index }}][label]"
                                           class="form-control form-control-sm" value="{{ $value->label }}"
                                           placeholder="e.g. 10 GB" required>
                                </td>
                                <td>
                                    <input type="number" min="0" name="option_links[{{ $link->id }}][values][{{ $index }}][sort_order]"
                                           class="form-control form-control-sm" value="{{ $value->sort_order }}">
                                </td>
                                <td class="text-center">
                                    <input class="form-check-input option-link-default-radio" type="radio"
                                           name="option_links[{{ $link->id }}][values][{{ $index }}][is_default]" value="1"
                                           id="default-{{ $link->id }}-{{ $value->id }}"
                                           @checked($value->is_default)>
                                </td>
                                        @unless ($optionPricingHidden)
                                            @foreach ($optionCycles as $cycle)
                                                <td>
                                                    <input type="number" step="0.01" min="0"
                                                           name="option_links[{{ $link->id }}][pricing][{{ $value->id }}][{{ $cycle }}]"
                                                           class="form-control form-control-sm text-end"
                                                           value="{{ $value->pricing->firstWhere('billing_cycle', $cycle)?->price_modifier }}"
                                                           placeholder="0.00">
                                                </td>
                                            @endforeach
                                        @endunless
                                        <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-outline-danger remove-option-link-value"
                                            title="Remove this value">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="text-muted small mb-0">
                <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                Removing a value and saving persists the change. The default value cannot be removed unless another value is marked as default.
                @if ($optionPricingHidden)
                    <br>Free product — option groups are attachable but no option pricing is charged.
                @endif
            </p>
        @else
            <p class="text-muted small mb-0">No values attached to this group yet.</p>
        @endif
    </div>
@empty
    <p class="text-muted mb-0">No option groups attached.</p>
@endforelse
</div>

@push('js')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Re-sequence the hidden sort_order inputs to the dragged order so
            // the single "Save Changes" submit persists the new arrangement.
            var sortableList = document.getElementById('option-links-sortable');
            if (sortableList) {
                var renumber = function () {
                    sortableList.querySelectorAll('.option-link-sort-order').forEach(function (input, index) {
                        input.value = index + 1;
                    });
                };
                sortableList.addEventListener('end', renumber);
                renumber(); // normalize once on load (covers gaps / duplicates)
            }

            document.querySelectorAll('.option-link-card').forEach(function (card) {
                // Keep a single default across the group's value rows.
                card.addEventListener('change', function (e) {
                    var radio = e.target.closest('.option-link-default-radio');
                    if (!radio || !radio.checked) return;
                    card.querySelectorAll('.option-link-default-radio').forEach(function (other) {
                        if (other !== radio) other.checked = false;
                    });
                });
                // Remove a value row client-side; persistence happens on Save Changes.
                card.addEventListener('click', function (e) {
                    var btn = e.target.closest('.remove-option-link-value');
                    if (!btn) return;
                    btn.closest('.option-link-value-row').remove();
                });
                // Enable the per-product Min/Max/Step/Placeholder fields only
                // while the override switch is on (off = inherit the group).
                var overrideToggle = card.querySelector('.option-input-overrides-toggle');
                if (overrideToggle) {
                    var syncOverrides = function () {
                        card.querySelectorAll('.option-override-input').forEach(function (input) {
                            input.disabled = !overrideToggle.checked;
                        });
                    };
                    overrideToggle.addEventListener('change', syncOverrides);
                    syncOverrides();
                }
            });
        });
    </script>
@endpush
