{{--
    The generate / copy / show controls on their own, for the password fields
    that have to keep their existing input markup:

      - schema-driven fields whose type is decided at runtime
        (admin/modules/config, admin/products/_module_config_fields),
      - the per-row hosting and console partials, which already build their
        own input-group around the field,
      - the auth screens that prefix the field with an icon.

    The group attributes (`data-password-field`, `data-password-confirm`,
    `data-password-length`) stay on the surrounding `.input-group`, exactly as
    in the full component — the buttons find everything through them, so this
    partial and the full component share one implementation.

    Usage:
        <div class="input-group" data-password-field data-password-confirm="password_confirmation">
            <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
            <input type="password" id="password" name="password" data-password-input class="form-control">
            <x-password-actions />
        </div>
--}}
@props([
    'generate' => true,
    'copy' => true,
    'view' => true,
    'size' => null,
])
@php
    $buttonClass = 'btn btn-outline-secondary'.($size === 'sm' ? ' btn-sm' : '');
@endphp
@if ($generate)
    <button type="button" class="{{ $buttonClass }}" data-pw-generate aria-label="Generate password" title="Generate password"><i class="bi bi-magic" aria-hidden="true"></i></button>
@endif
@if ($copy)
    <button type="button" class="{{ $buttonClass }}" data-pw-copy aria-label="Copy password" title="Copy password"><i class="bi bi-clipboard" aria-hidden="true"></i></button>
@endif
@if ($view)
    <button type="button" class="{{ $buttonClass }}" data-pw-toggle aria-pressed="false" aria-label="Show password" title="Show password"><i class="bi bi-eye" aria-hidden="true"></i></button>
@endif
