@props([
    'id' => 'confirm-modal',
    'title' => 'Confirm action',
    'message' => 'Are you sure? This cannot be undone.',
    'action' => '#',
    'method' => 'DELETE',
    'confirmLabel' => 'Delete',
    'confirmTheme' => 'danger',
    'cancelLabel' => 'Cancel',
])

@php
    $iconMap = [
        'danger' => 'bi bi-exclamation-triangle-fill',
        'warning' => 'bi bi-exclamation-circle-fill',
        'primary' => 'bi bi-question-circle-fill',
        'success' => 'bi bi-check-circle-fill',
        'info' => 'bi bi-info-circle-fill',
        'secondary' => 'bi bi-question-circle',
    ];
    $confirmIcon = $iconMap[$confirmTheme] ?? $iconMap['danger'];
    $tokenMap = [
        'danger' => 'var(--color-danger)',
        'warning' => 'var(--color-warning)',
        'primary' => 'var(--color-primary)',
        'success' => 'var(--color-success)',
        'info' => 'var(--color-info)',
        'secondary' => 'var(--color-neutral-500)',
    ];
    $accent = $tokenMap[$confirmTheme] ?? $tokenMap['danger'];
@endphp

{{--
    The single confirmation dialog for the whole app — admin and client.

    Never guard a destructive or state-changing action with the browser's
    native confirm(); it cannot be styled, is not keyboard/screen-reader
    consistent, and made the same action look different on different pages.

    Put the trigger where the action lives:
        <button type="button" data-bs-toggle="modal" data-bs-target="#my-id">

    and render this component *outside* any table or flex row (see
    admin/products/index for the per-row pattern).

    Use the `fields` slot when the submitted form needs more than CSRF and the
    method spoof, e.g. a hidden status value.
--}}
<div class="mh-confirm-modal">
<x-adminlte-modal :id="$id" :title="$title" size="sm">
    {{-- Icon circle + message block — tokens for radii/shadow/spacing --}}
    <div class="d-flex gap-3 align-items-start">
        <span class="d-inline-flex align-items-center justify-content-center rounded-circle flex-shrink-0"
              style="width: 2.5rem; height: 2.5rem; background: color-mix(in srgb, {{ $accent }} 12%, transparent); color: {{ $accent }}; font-size: 1.1rem;">
            <i class="{{ $confirmIcon }}" aria-hidden="true"></i>
        </span>
        <div class="flex-fill" style="padding-top: 0.15rem;">
            <p class="mb-0" style="font-size: var(--text-sm); line-height: var(--leading-normal); color: var(--color-text);">{{ $message }}</p>
            @if (trim((string) ($slot ?? '')) !== '')
                <div class="mt-2 small text-muted" style="font-size: var(--text-sm);">{{ $slot }}</div>
            @endif
        </div>
    </div>

    <form method="POST" action="{{ $action }}" id="{{ $id }}-form">
        @csrf
        @unless (in_array($method, ['GET', 'POST'], true))
            @method($method)
        @endunless
        {{ $fields ?? '' }}
    </form>

    <x-slot name="footer">
        <div class="d-flex gap-2 justify-content-end w-100" style="gap: var(--space-2);">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"
                    style="border-radius: var(--radius-md); font-weight: 500; transition: all var(--duration-base) var(--ease-default);">
                {{ $cancelLabel }}
            </button>
            <button type="submit" form="{{ $id }}-form" class="btn btn-{{ $confirmTheme }}"
                    style="border-radius: var(--radius-md); font-weight: 500; transition: all var(--duration-base) var(--ease-default);">
                {{ $confirmLabel }}
            </button>
        </div>
    </x-slot>
</x-adminlte-modal>
</div>
