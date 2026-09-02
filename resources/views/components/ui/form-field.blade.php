@props([
    'label' => null,
    'id' => null,
    'required' => false,
    'help' => null,
    'error' => null,
    'horizontal' => false,
])

@php
    $hasError = $error !== null && $error !== '';
@endphp

<div {{ $attributes->merge(['class' => 'mb-3 mh-form-field' . ($horizontal ? ' mh-form-field--horizontal' : '')]) }}>
    @if ($label)
        <label @if ($id) for="{{ $id }}" @endif class="form-label mh-form-label">
            {{ $label }}
            @if ($required)
                <span class="mh-required" aria-hidden="true">*</span>
                <span class="visually-hidden"> (required)</span>
            @endif
        </label>
    @endif

    {{ $slot }}

    @if ($help)
        <div class="form-text mh-help-text">{{ $help }}</div>
    @endif

    @if ($hasError)
        <div class="invalid-feedback d-block mh-invalid-feedback" @if ($id) id="{{ $id }}-error" @endif>{{ $error }}</div>
    @endif
</div>
