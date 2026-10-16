{{--
    Password input with its generate / copy / show controls.

    Replaces both the raw `<input type="password">` markup and
    `<x-adminlte-input type="password">`. The AdminLTE input component takes
    no prepend/append prop, and its published view only honours them as named
    slots — which would nest the buttons inside a `.input-group-text` span and
    break their sizing. Owning the input-group here is what puts the buttons
    where they belong, as direct children of the group.

    `confirm` names the paired password_confirmation field by id: Generate
    writes the same value to both, which is the only way the pair stays in
    sync when a human never types it.

    Behaviour lives in resources/js/password-field.js behind one delegated
    document listener, so fields re-rendered into a modal or a per-row partial
    are wired without running a script again.

    Usage:
        <x-password-field name="password" label="Password" required confirm="password_confirmation" />
        <x-password-field name="password" size="sm" :generate="false" :copy="false" />
--}}
@props([
    'name' => 'password',
    'id' => null,
    'label' => null,
    'value' => null,
    'required' => false,
    'size' => null,
    'wrapperClass' => 'mb-3',
    'placeholder' => null,
    'autocomplete' => 'new-password',
    'maxlength' => null,
    'help' => null,
    'confirm' => null,
    'generate' => true,
    'copy' => true,
    'view' => true,
    'length' => 16,
])
@php
    $fieldId = $id ?: $name;
    $errorKey = str_replace(['[', ']'], ['.', ''], $name);
    $hasError = $errors->has($errorKey);
    $controlClass = 'form-control'
        .($size ? ' form-control-'.$size : '')
        .($hasError ? ' is-invalid' : '');
    $buttonClass = 'btn btn-outline-secondary'
        .($size === 'sm' ? ' btn-sm' : '');
@endphp
<div class="{{ $wrapperClass }}"
     data-password-field
     @if ($confirm) data-password-confirm="{{ $confirm }}" @endif
     @if ($length !== 16) data-password-length="{{ $length }}" @endif>
    @if ($label !== null)
        <label for="{{ $fieldId }}" class="form-label">{{ $label }}@if ($required) <span class="mh-required" aria-hidden="true">*</span><span class="visually-hidden"> (required)</span>@endif</label>
    @endif

    <div class="input-group">
        <input type="password"
               name="{{ $name }}"
               id="{{ $fieldId }}"
               value="{{ old($errorKey, $value) }}"
               data-password-input
               @if ($placeholder) placeholder="{{ $placeholder }}" @endif
               @if ($maxlength) maxlength="{{ $maxlength }}" @endif
               @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
               @if ($required) required @endif
               @if ($hasError) aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @endif
               {{ $attributes->merge(['class' => $controlClass]) }}>

        @if ($generate)
            <button type="button" class="{{ $buttonClass }}" data-pw-generate aria-label="Generate password" title="Generate password"><i class="bi bi-magic" aria-hidden="true"></i></button>
        @endif
        @if ($copy)
            <button type="button" class="{{ $buttonClass }}" data-pw-copy aria-label="Copy password" title="Copy password"><i class="bi bi-clipboard" aria-hidden="true"></i></button>
        @endif
        @if ($view)
            <button type="button" class="{{ $buttonClass }}" data-pw-toggle aria-pressed="false" aria-label="Show password" title="Show password"><i class="bi bi-eye" aria-hidden="true"></i></button>
        @endif
    </div>

    @if ($help !== null)
        <small class="form-text text-muted">{{ $help }}</small>
    @endif

    @if ($hasError)
        <div class="invalid-feedback d-block" id="{{ $fieldId }}-error">{{ $errors->first($errorKey) }}</div>
    @endif
</div>
