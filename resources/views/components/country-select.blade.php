{{--
    Country dropdown — the only place a country list is rendered.

    Replaces the ten-entry array that used to be pasted into six views. Options
    come from App\Support\Countries; a stored value the list does not contain
    (legacy rows hold the literal "Other") is kept as its own option, because a
    <select> whose value is absent shows its FIRST option instead, and the next
    save writes that back to the row.

    Usage:
        <x-country-select :selected="$user->country" />
        <x-country-select name="settings[company_country]" id="company_country"
                          :selected="$settings['company_country'] ?? null" />
        <x-country-select :label="null" size="sm" wrapper-class="" />   (compact, unlabelled)
        <x-country-select allow-blank :selected="$datacenter->country" />  (no forced default)
--}}
@props([
    'name' => 'country',
    'id' => null,
    'label' => 'Country',
    'selected' => null,
    'size' => null,
    'wrapperClass' => 'mb-3',
    'allowBlank' => false,
])
@php
    $fieldId = $id ?: str_replace(['[', ']'], ['_', ''], $name);
    $errorKey = str_replace(['[', ']'], ['.', ''], $name);

    $current = trim((string) old($errorKey, $selected));

    // A blank column used to land on India because India happened to be the
    // first option. The list is alphabetical now, so the default is stated
    // rather than inherited from the sort order.
    //
    // allowBlank exists for the fields that were free text until now
    // (datacenters): there, "no country recorded" is a real state, and
    // defaulting it to India would write a country into every row an admin
    // opened and saved for an unrelated reason.
    if ($current === '' && ! $allowBlank) {
        $current = \App\Support\Countries::DEFAULT;
    }
@endphp
<div class="{{ $wrapperClass }}">
    @if ($label !== null)
        <label for="{{ $fieldId }}" class="form-label">{{ $label }}</label>
    @endif

    {{-- autocomplete defaults to the semantic token, which is right when people
         fill in their OWN address (register, client profile). Admin forms pass
         autocomplete="off": there the filler is entering somebody else's
         address, so browser autofill offers the admin's own and is one
         tab-complete away from putting their home address on a customer's
         invoice. --}}
    <select name="{{ $name }}"
            id="{{ $fieldId }}"
            {{ $attributes->merge([
                'autocomplete' => 'country-name',
                'class' => 'form-select'.($size ? ' form-select-'.$size : '').($errors->has($errorKey) ? ' is-invalid' : ''),
            ]) }}>
        @if ($allowBlank)
            <option value="" @selected($current === '')>&mdash; not recorded &mdash;</option>
        @endif
        @foreach (\App\Support\Countries::optionsFor($current) as $country)
            <option value="{{ $country }}" @selected($current === $country)>{{ $country }}</option>
        @endforeach
    </select>

    @error($errorKey)
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @enderror
</div>
