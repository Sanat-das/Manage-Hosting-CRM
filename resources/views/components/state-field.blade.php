{{--
    State / province field that follows the country select next to it.

    India gets a real dropdown of the 36 current states and union territories;
    every other country gets a text box. That asymmetry is the whole point:
    GstStateCodes is the only subdivision list this application owns, and a
    free-text state is not cosmetic here — CustomerController pushes it through
    GstStateCodes::normalize() to fill customers.state_code, and a value that
    fails to normalize leaves the column NULL, which GstTaxService reads as
    inter-state and bills at the IGST rate. Offering a text box for a country
    we have no list for is correct; refusing an address because it is not in a
    half-finished list is not.

    Only the hidden input carries `name`, so exactly one value is ever posted
    no matter which control is on screen, and the id stays on it — page scripts
    that read the field by id (the Settings invoice preview) keep working.

    Usage:
        <x-state-field :value="$user->state" :country="$user->country" />
        <x-state-field name="settings[company_state]" id="company_state"
                       country-id="company_country" country-name="settings[company_country]"
                       :value="$settings['company_state'] ?? null"
                       :country="$settings['company_country'] ?? null" />
--}}
@props([
    'name' => 'state',
    'id' => null,
    'label' => 'State / Province',
    'value' => null,
    'country' => null,
    'countryId' => 'country',
    'countryName' => 'country',
    'size' => null,
    'wrapperClass' => 'mb-3',
    'placeholder' => 'e.g. Maharashtra',
    // Defaults to the semantic token, which is right when people fill in their
    // OWN address. Admin forms pass "off" — see country-select for why.
    'autocomplete' => 'address-level1',
])
@php
    $fieldId = $id ?: str_replace(['[', ']'], ['_', ''], $name);
    $errorKey = str_replace(['[', ']'], ['.', ''], $name);

    $current = trim((string) old($errorKey, $value));

    $countryKey = str_replace(['[', ']'], ['.', ''], $countryName);
    $currentCountry = trim((string) old($countryKey, $country)) ?: \App\Support\Countries::DEFAULT;

    $subdivisions = \App\Support\Countries::subdivisions($currentCountry);
    $useSelect = $subdivisions !== [];

    // An unrecognised state under a country we DO have a list for is almost
    // always a typo from the free-text era ("Maharastra"). It is shown as its
    // own option rather than dropped: the admin sees what is stored and can
    // correct it, and simply opening the form does not silently blank it.
    $unlisted = $useSelect && $current !== '' && ! in_array($current, $subdivisions, true);

    $selectClass = 'form-select'.($size ? ' form-select-'.$size : '');
    $inputClass = 'form-control'.($size ? ' form-control-'.$size : '');
    $invalid = $errors->has($errorKey) ? ' is-invalid' : '';
@endphp
<div class="{{ $wrapperClass }}"
     data-state-field
     data-country-target="{{ $countryId }}"
     data-subdivisions="{{ json_encode(\App\Support\Countries::subdivisionMap()) }}">
    @if ($label !== null)
        <label for="{{ $fieldId }}_{{ $useSelect ? 'select' : 'text' }}" class="form-label">{{ $label }}</label>
    @endif

    <input type="hidden" name="{{ $name }}" id="{{ $fieldId }}" value="{{ $current }}" data-state-value>

    <select id="{{ $fieldId }}_select"
            class="{{ $selectClass }}{{ $invalid }}"
            data-state-select
            autocomplete="{{ $autocomplete }}"
            aria-label="{{ $label ?? 'State / Province' }}"
            @unless ($useSelect) hidden disabled @endunless>
        <option value="">&mdash; select a state &mdash;</option>
        @if ($unlisted)
            <option value="{{ $current }}" selected>{{ $current }}</option>
        @endif
        @foreach ($subdivisions as $subdivision)
            <option value="{{ $subdivision }}" @selected($current === $subdivision)>{{ $subdivision }}</option>
        @endforeach
    </select>

    <input type="text"
           id="{{ $fieldId }}_text"
           class="{{ $inputClass }}{{ $invalid }}"
           value="{{ $current }}"
           placeholder="{{ $placeholder }}"
           autocomplete="{{ $autocomplete }}"
           aria-label="{{ $label ?? 'State / Province' }}"
           data-state-text
           @if ($useSelect) hidden disabled @endif>

    @error($errorKey)
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @enderror
</div>

@once
    <script>
        (function () {
            function wire(field) {
                var hidden = field.querySelector('[data-state-value]');
                var select = field.querySelector('[data-state-select]');
                var text = field.querySelector('[data-state-text]');
                var label = field.querySelector('label');
                var map = {};

                try {
                    map = JSON.parse(field.getAttribute('data-subdivisions') || '{}');
                } catch (e) {
                    map = {};
                }

                var country = document.getElementById(field.getAttribute('data-country-target'));

                if (!hidden || !select || !text) {
                    return;
                }

                // Everything downstream reads the hidden input, so a change has
                // to be announced on it — the Settings invoice preview listens
                // for exactly this.
                function publish(value) {
                    if (hidden.value === value) {
                        return;
                    }
                    hidden.value = value;
                    hidden.dispatchEvent(new Event('input', { bubbles: true }));
                    hidden.dispatchEvent(new Event('change', { bubbles: true }));
                }

                function render() {
                    var list = (country && map[country.value]) || [];
                    var useSelect = list.length > 0;

                    if (useSelect) {
                        var keep = hidden.value;
                        select.innerHTML = '';
                        select.add(new Option('— select a state —', ''));
                        if (keep && list.indexOf(keep) === -1) {
                            select.add(new Option(keep, keep));
                        }
                        list.forEach(function (name) {
                            select.add(new Option(name, name));
                        });
                        select.value = keep;
                    } else {
                        text.value = hidden.value;
                    }

                    select.hidden = !useSelect;
                    select.disabled = !useSelect;
                    text.hidden = useSelect;
                    text.disabled = useSelect;

                    if (label) {
                        label.htmlFor = useSelect ? select.id : text.id;
                    }
                }

                select.addEventListener('change', function () { publish(select.value); });
                text.addEventListener('input', function () { publish(text.value); });

                if (country) {
                    country.addEventListener('change', function () {
                        var list = map[country.value] || [];
                        // A state from the country we just left is not a state
                        // of the one we arrived at; keep free text as-is.
                        if (list.length && list.indexOf(hidden.value) === -1) {
                            publish('');
                        }
                        render();
                    });
                }

                render();
            }

            function init() {
                document.querySelectorAll('[data-state-field]').forEach(wire);
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', init);
            } else {
                init();
            }
        })();
    </script>
@endonce
