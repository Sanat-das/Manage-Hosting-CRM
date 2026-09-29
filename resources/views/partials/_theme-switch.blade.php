{{-- Colour-mode switch for layouts with no navbar dropdown to host one
     (auth pages, error pages). Buttons carry `data-bs-theme-value` — the same
     hook resources/js/adminlte.js wires and marks `.active`, so this stays in
     lockstep with the navbar dropdown in partials/color-mode.blade.php.

     `$variant` — 'floating' pins it to the top-right corner; 'inline' leaves
     positioning to the caller. --}}
@php $variant = $variant ?? 'inline'; @endphp

<div class="theme-switch {{ $variant === 'floating' ? 'theme-switch--floating' : '' }}"
     role="group" aria-label="{{ __('Colour scheme') }}">
    <button type="button" class="theme-switch__btn" data-bs-theme-value="light"
            aria-pressed="false" title="{{ __('Light') }}">
        <i class="bi bi-sun-fill" aria-hidden="true"></i>
        <span class="visually-hidden">{{ __('Light') }}</span>
    </button>
    <button type="button" class="theme-switch__btn" data-bs-theme-value="dark"
            aria-pressed="false" title="{{ __('Dark') }}">
        <i class="bi bi-moon-fill" aria-hidden="true"></i>
        <span class="visually-hidden">{{ __('Dark') }}</span>
    </button>
    <button type="button" class="theme-switch__btn" data-bs-theme-value="auto"
            aria-pressed="true" title="{{ __('Match system') }}">
        <i class="bi bi-circle-half" aria-hidden="true"></i>
        <span class="visually-hidden">{{ __('Match system') }}</span>
    </button>
</div>
