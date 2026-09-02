@extends('adminlte::page')

@section('title', $product->name)

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">{{ $product->name }}</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('client.store.index') }}">Store</a></li>
                <li class="breadcrumb-item active">{{ $product->name }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @include('client.store._alerts')

    @php
        $product->loadMissing('optionLinks.group', 'optionLinks.linkValues.pricing', 'optionLinks.unitPricing');

        $optionLinks = $product->optionLinks;
        $infoLinks = $optionLinks->where('customer_editable', false);
        $editableLinks = $optionLinks->where('customer_editable', true);

        $continuousTypes = \App\Models\ProductOptionGroup::CONTINUOUS_TYPES;

        // The 'free' cycle is a payment-type marker (the pricing matrix stores
        // a free row for free products) — never a customer-selectable billing
        // cycle. Paid products list their real cycles; free products get no
        // selector at all and submit the product's default cycle instead.
        $cycleTiers = $product->pricing->reject(fn ($tier) => $tier->billing_cycle === 'free');

        $optionCycleSuffix = [
            'free' => 'free', 'one_time' => 'once', 'monthly' => 'mo', 'quarterly' => 'qtr',
            'semi_annual' => '6mo', 'annual' => 'yr', 'biennial' => '2yr', 'triennial' => '3yr',
        ];

        $optionModifierLabel = static function (float $modifier, string $cycle) use ($optionCycleSuffix): string {
            $sign = $modifier < 0 ? '-' : '+';
            $suffix = $cycle === 'one_time' ? ' once' : '/'.($optionCycleSuffix[$cycle] ?? 'mo');
            return $sign.'₹'.number_format(abs($modifier), 2).$suffix;
        };

        // Free products keep the option groups but never charge for them.
        $showOptionModifiers = ($product->payment_type ?? 'recurring') !== 'free';
        // The modifier shown for ONE billing cycle: the exact cycle wins, then
        // monthly, then the single configured cycle — matching the live
        // preview's modifierFor() fallback so the label never lies.
        $optionValueModifier = static function ($value, string $cycle) use ($optionModifierLabel): string {
            $price = $value->pricing->firstWhere('billing_cycle', $cycle)
                ?? $value->pricing->firstWhere('billing_cycle', 'monthly');

            if ($price === null && $value->pricing->isNotEmpty()) {
                $price = $value->pricing->first();
            }

            return $price === null
                ? ''
                : ' ('.$optionModifierLabel((float) $price->price_modifier, (string) $price->billing_cycle).')';
        };

        // Per-cycle modifiers embedded for the live label update (the customer
        // can switch the billing cycle, and the labels follow).
        $optionValueModifierData = static function ($value): string {
            return $value->pricing->sortBy('billing_cycle')
                ->mapWithKeys(fn ($price) => [(string) $price->billing_cycle => (float) $price->price_modifier])
                ->toJson();
        };

        // Pricing data embedded for the live price preview: base price per
        // cycle plus, per editable link, either per-unit prices (continuous)
        // or per-value modifiers (discrete). The 'free' marker cycle is never
        // part of the base map (free products price from products.price).
        $optionPricingData = [
            'base' => $cycleTiers->isNotEmpty()
                ? $cycleTiers->mapWithKeys(fn ($tier) => [$tier->billing_cycle => (float) $tier->price])->all()
                : ['monthly' => (float) $product->price],
            'links' => $editableLinks->mapWithKeys(function ($link) use ($continuousTypes) {
                $data = ['type' => $link->group?->type ?? 'dropdown'];

                if (in_array($link->group?->type, $continuousTypes, true)) {
                    $data['unit'] = $link->unitPricing
                        ->mapWithKeys(fn ($price) => [$price->billing_cycle => (float) $price->price_modifier])
                        ->all();
                } else {
                    $data['values'] = $link->linkValues->mapWithKeys(fn ($value) => [
                        $value->label => $value->pricing
                            ->mapWithKeys(fn ($price) => [$price->billing_cycle => (float) $price->price_modifier])
                            ->all(),
                    ])->all();
                }

                return [(int) $link->id => $data];
            })->all(),
        ];
    @endphp

    <div class="row">
        <div class="col-md-8">
            <x-adminlte-card icon="bi bi-box-seam" title="{{ $product->name }}">
                <p>{{ $product->description ?? 'No description available.' }}</p>
                <div class="mb-2">
                    <span class="badge text-bg-info">{{ $product->group?->name ?? '—' }}</span>
                    @if (($product->payment_type ?? 'recurring') === 'free')
                        <span class="badge text-bg-success">Free</span>
                    @elseif ($product->pricing->isNotEmpty())
                        <span class="badge text-bg-primary">From ₹{{ number_format($product->pricing->min('price'), 2) }}/mo</span>
                    @else
                        <span class="badge text-bg-primary">₹{{ number_format($product->price, 2) }}</span>
                    @endif
                </div>

                {{-- Informational (non-editable) option links render as a static display list.
                     No pricing is shown: these are fixed inclusions the customer cannot
                     change, so a modifier would mislead (e.g. a "+₹50.00/mo" that is
                     already baked into the base price). --}}
                @if ($infoLinks->isNotEmpty())
                    <div class="row mt-3">
                        @foreach ($infoLinks as $link)
                            <div class="col-md-3 col-6">
                                <strong>{{ $link->group?->name }}</strong><br>
                                {{ $link->linkValues->isNotEmpty() ? $link->linkValues->map(fn ($v) => $v->label)->implode(', ') : '—' }}
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-adminlte-card>
        </div>
        <div class="col-md-4">
            <x-adminlte-card icon="bi bi-cart-plus" title="Add to Cart">
                <form method="POST" action="{{ route('client.store.cart.add') }}">
                    @csrf
                    <input type="hidden" name="product_id" value="{{ $product->id }}">

                    @if ($cycleTiers->isNotEmpty())
                        <div class="mb-3">
                            <label class="form-label fw-bold">Billing Cycle</label>
                            @foreach ($cycleTiers as $tier)
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="billing_cycle"
                                           value="{{ $tier->billing_cycle }}"
                                           id="cycle-{{ $tier->billing_cycle }}"
                                           @checked($loop->first || $tier->billing_cycle === ($product->billing_cycle ?? null))>
                                    <label class="form-check-label" for="cycle-{{ $tier->billing_cycle }}">
                                        {{ ucfirst(str_replace('_', ' ', $tier->billing_cycle)) }}
                                        — ₹{{ number_format($tier->price, 2) }}
                                        @if ((float) $tier->setup_fee > 0)
                                            <span class="text-muted small">(+₹{{ number_format($tier->setup_fee, 2) }} setup)</span>
                                        @endif
                                    </label>
                                </div>
                            @endforeach
                        </div>
                    @else
                        {{-- Free product: no billing cycle is charged, so no
                             selector — submit the product's default cycle. --}}
                        <input type="hidden" name="billing_cycle" value="{{ $product->billing_cycle ?? 'monthly' }}">
                    @endif

                    <div class="mb-3">
                        <label class="form-label fw-bold">Quantity</label>
                        @if ($product->isSingleUnit())
                            <input type="number" name="quantity" class="form-control" min="1" max="1" value="1" disabled>
                            <input type="hidden" name="quantity" value="1">
                            <div class="form-text text-muted">Sold as a single unit per order.</div>
                        @else
                            <input type="number" name="quantity" class="form-control" min="1" max="99" value="1">
                        @endif
                    </div>

                    @if ($product->require_domain)
                        <div class="mb-3">
                            <label class="form-label fw-bold">Domain</label>
                            <input type="text" name="domain" class="form-control" placeholder="example.com">
                        </div>
                    @endif

                    {{-- Customer-editable option links render per-type controls inside the form. --}}
                    @if ($editableLinks->isNotEmpty())
                        <div class="mb-3">
                            <label class="form-label fw-bold">Configuration Options</label>
                            @foreach ($editableLinks as $link)
                                @php
                                    $optionType = $link->group?->type ?? 'dropdown';
                                    $renderable = $link->linkValues->isNotEmpty()
                                        || in_array($optionType, ['quantity', 'text', 'number', 'slider'], true);
                                @endphp
                                @if ($renderable)
                                    <div class="mb-3">
                                        <label class="form-label" for="option-{{ $link->id }}">{{ $link->group?->name ?? 'Option' }}</label>
                                        @switch($optionType)
                                            @case('dropdown')
                                                <select class="form-select" name="options[{{ $link->id }}]" id="option-{{ $link->id }}" required>
                                                    @foreach ($link->linkValues as $value)
                                                        <option value="{{ $value->label }}" @selected($value->is_default)>{{ $value->label }}@if ($showOptionModifiers)<span class="option-value-modifier" data-modifiers="{{ $optionValueModifierData($value) }}">{{ $optionValueModifier($value, (string) $product->billing_cycle) }}</span>@endif</option>
                                                    @endforeach
                                                </select>
                                                @break

                                            @case('radio')
                                                @foreach ($link->linkValues as $value)
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="radio" name="options[{{ $link->id }}]"
                                                               id="option-{{ $link->id }}-{{ $value->id }}" value="{{ $value->label }}"
                                                               @checked($value->is_default) @if ($loop->first) required @endif>
                                                        <label class="form-check-label" for="option-{{ $link->id }}-{{ $value->id }}">
                                                            {{ $value->label }}@if ($showOptionModifiers)<span class="option-value-modifier" data-modifiers="{{ $optionValueModifierData($value) }}">{{ $optionValueModifier($value, (string) $product->billing_cycle) }}</span>@endif
                                                        </label>
                                                    </div>
                                                @endforeach
                                                @break

                                            @case('checkbox')
                                                {{-- Selections capped by the group's input_max (fallback: every
                                                     value); unchecked options grey out once the cap is reached. --}}
                                                <div class="option-checkbox-group mb-1"
                                                     data-checkbox-group="{{ $link->id }}"
                                                     data-max="{{ $link->input_max ?? $link->group?->input_max ?? $link->linkValues->count() }}">
                                                    @foreach ($link->linkValues as $value)
                                                        <div class="form-check" data-checkbox-option>
                                                            <input class="form-check-input" type="checkbox" name="options[{{ $link->id }}][]"
                                                                   id="option-{{ $link->id }}-{{ $value->id }}" value="{{ $value->label }}"
                                                                   @checked($value->is_default)>
                                                            <label class="form-check-label" for="option-{{ $link->id }}-{{ $value->id }}">
                                                                {{ $value->label }}@if ($showOptionModifiers)<span class="option-value-modifier" data-modifiers="{{ $optionValueModifierData($value) }}">{{ $optionValueModifier($value, (string) $product->billing_cycle) }}</span>@endif
                                                            </label>
                                                        </div>
                                                    @endforeach
                                                </div>
                                                @break

                                            @case('quantity')
                                                {{-- Quantity is a count: always whole units (step 1), matching
                                                     the server's integer rule — never the group's input_step. --}}
                                                <input type="number" class="form-control" name="options[{{ $link->id }}]"
                                                       id="option-{{ $link->id }}"
                                                       min="{{ $link->input_min ?? $link->group?->input_min ?? 0 }}"
                                                       max="{{ $link->input_max ?? $link->group?->input_max }}"
                                                       step="1"
                                                       value="{{ $link->linkValues->contains('is_default', true) ? 1 : 0 }}">
                                                <div class="form-text" data-unit-price="{{ $link->id }}"></div>
                                                @break

                                            @case('number')
                                                <input type="number" class="form-control" name="options[{{ $link->id }}]"
                                                       id="option-{{ $link->id }}"
                                                       min="{{ $link->input_min ?? $link->group?->input_min ?? 0 }}"
                                                       max="{{ $link->input_max ?? $link->group?->input_max }}"
                                                       step="{{ $link->input_step ?? $link->group?->input_step ?? 1 }}"
                                                       value="{{ $link->linkValues->contains('is_default', true) ? 1 : 0 }}">
                                                <div class="form-text" data-unit-price="{{ $link->id }}"></div>
                                                @break

                                            @case('slider')
                                                @php
                                                    $sliderMin = $link->input_min ?? $link->group?->input_min ?? 0;
                                                    $sliderMax = $link->input_max ?? $link->group?->input_max ?? 100;
                                                    $sliderStep = $link->input_step ?? $link->group?->input_step ?? 1;
                                                @endphp
                                                <div class="d-flex align-items-center gap-2">
                                                    <input type="range" class="form-range flex-grow-1" name="options[{{ $link->id }}]"
                                                           id="option-{{ $link->id }}" min="{{ $sliderMin }}" max="{{ $sliderMax }}"
                                                           step="{{ $sliderStep }}" value="{{ $sliderMin }}">
                                                    <span class="small text-muted fw-semibold" data-slider-value="option-{{ $link->id }}">{{ $sliderMin }}</span>
                                                </div>
                                                <div class="form-text" data-unit-price="{{ $link->id }}"></div>
                                                @break

                                            @default
                                                <input type="text" class="form-control" name="options[{{ $link->id }}]"
                                                       id="option-{{ $link->id }}" maxlength="255" required
                                                       placeholder="{{ $link->input_placeholder ?? $link->group?->input_placeholder }}">
                                        @endswitch
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @endif

                    <div class="mb-3 d-flex justify-content-between align-items-center">
                        <span class="fw-bold">Estimated total</span>
                        <span class="fs-5 fw-bold text-primary" id="live-price-total">—</span>
                    </div>

                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-cart-plus me-1"></i> Add to Cart</button>
                </form>
                <a href="{{ route('client.store.index') }}" class="btn btn-outline-secondary w-100 mt-2">Back to Store</a>
            </x-adminlte-card>
        </div>
    </div>

    @push('js')
        <script>
            (function () {
                const pricing = @json($optionPricingData);
                const totalEl = document.getElementById('live-price-total');
                const unitLabels = document.querySelectorAll('[data-unit-price]');
                const optionCycleSuffix = {
                    free: 'free', one_time: 'once', monthly: 'mo', quarterly: 'qtr',
                    semi_annual: '6mo', annual: 'yr', biennial: '2yr', triennial: '3yr',
                };
                const isFreeProduct = @json(($product->payment_type ?? 'recurring') === 'free');
                // The cycles the product actually offers (its pricing ladder) —
                // option modifier fallbacks only apply within this set.
                const enabledCycles = Object.keys(pricing.base || {});
                if (!totalEl) return;

                function currentCycle() {
                    const checked = document.querySelector('input[name="billing_cycle"]:checked');
                    if (checked) return checked.value;
                    const hidden = document.querySelector('input[name="billing_cycle"]');
                    return hidden ? hidden.value : 'monthly';
                }

                function modifierFor(map, cycle) {
                    if (!map) return 0;
                    if (map[cycle] !== undefined) return map[cycle];
                    // Fallbacks only apply within the product's enabled cycles.
                    if (enabledCycles.indexOf('monthly') !== -1 && map.monthly !== undefined) return map.monthly;
                    const keys = Object.keys(map);
                    if (keys.length === 1 && enabledCycles.indexOf(keys[0]) !== -1) return map[keys[0]];
                    return 0;
                }

                function selectionPrice(id, cycle) {
                    const link = pricing.links[id];
                    if (!link) return 0;
                    const control = document.getElementById('option-' + id);

                    if (link.type === 'slider' || link.type === 'number' || link.type === 'quantity') {
                        return modifierFor(link.unit, cycle) * (control ? (parseFloat(control.value) || 0) : 0);
                    }

                    if (link.type === 'checkbox') {
                        let sum = 0;
                        document.querySelectorAll('input[name="options[' + id + '][]"]:checked').forEach(function (cb) {
                            sum += modifierFor(link.values[cb.value], cycle);
                        });
                        return sum;
                    }

                    if (link.type === 'radio') {
                        const checked = document.querySelector('input[name="options[' + id + ']"]:checked');
                        return checked ? modifierFor(link.values[checked.value], cycle) : 0;
                    }

                    return control && control.value ? modifierFor(link.values[control.value], cycle) : 0;
                }

                function recompute() {
                    const cycle = currentCycle();
                    let total = pricing.base[cycle] ?? pricing.base.monthly ?? 0;

                    Object.keys(pricing.links).forEach(function (id) {
                        total += selectionPrice(id, cycle);
                    });

                    totalEl.textContent = '₹' + total.toFixed(2);

                    unitLabels.forEach(function (el) {
                        if (isFreeProduct) {
                            el.textContent = '';
                            return;
                        }
                        const link = pricing.links[el.dataset.unitPrice];
                        const unit = link ? modifierFor(link.unit, cycle) : 0;
                        el.textContent = unit > 0 ? '₹' + unit.toFixed(2) + ' per unit / ' + cycle : '';
                    });

                    document.querySelectorAll('[data-slider-value]').forEach(function (el) {
                        const control = document.getElementById(el.dataset.sliderValue);
                        if (control) el.textContent = control.value;
                    });

                    // Value labels show only the selected cycle's modifier
                    // (exact cycle, then monthly, then the single value).
                    if (!isFreeProduct) {
                        document.querySelectorAll('.option-value-modifier').forEach(function (el) {
                            const map = JSON.parse(el.dataset.modifiers || '{}');
                            const source = map[cycle] !== undefined ? cycle
                                : (map.monthly !== undefined ? 'monthly'
                                    : (Object.keys(map).length === 1 ? Object.keys(map)[0] : null));
                            if (source === null) {
                                el.textContent = '';
                                return;
                            }
                            const modifier = map[source];
                            const suffix = source === 'one_time' ? ' once' : '/' + (optionCycleSuffix[source] || 'mo');
                            el.textContent = ' (' + (modifier < 0 ? '-' : '+') + '₹' + Math.abs(modifier).toFixed(2) + suffix + ')';
                        });
                    }
                }

                document.querySelectorAll('input[name="billing_cycle"], input[name^="options["], select[name^="options["]')
                    .forEach(function (el) {
                        el.addEventListener('input', recompute);
                        el.addEventListener('change', recompute);
                    });

                recompute();
            })();
        </script>

        <style>
            .option-cap-limited { opacity: .45; }
        </style>
        <script>
            // Checkbox selection caps (the group's input_max, else the value
            // count): grey out + disable unchecked options once the cap is
            // reached, mirroring the server's max rule.
            (function () {
                document.querySelectorAll('[data-checkbox-group]').forEach(function (group) {
                    var max = parseInt(group.dataset.max, 10) || 1;
                    var inputs = Array.from(group.querySelectorAll('input[type="checkbox"]'));

                    function sync() {
                        var checked = group.querySelectorAll('input[type="checkbox"]:checked').length;
                        var atCap = checked >= max;
                        inputs.forEach(function (input) {
                            var block = atCap && !input.checked;
                            input.disabled = block;
                            var option = input.closest('[data-checkbox-option]');
                            if (option) option.classList.toggle('option-cap-limited', block);
                        });
                    }

                    inputs.forEach(function (input) { input.addEventListener('change', sync); });
                    sync();
                });
            })();
        </script>
    @endpush
@stop
