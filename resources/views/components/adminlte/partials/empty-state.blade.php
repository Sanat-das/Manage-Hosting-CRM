@props([
    'icon' => 'bi bi-inbox',
    'title' => 'No records found',
    'message' => null,
    'actionLabel' => null,
    'actionUrl' => null,
    'size' => 'default', // sm | default | lg
])

@php
    $sizeMap = [
        'sm' => ['pad' => 'p-4', 'iconSize' => '1.2rem', 'circle' => '2.5rem'],
        'default' => ['pad' => 'p-5', 'iconSize' => '1.5rem', 'circle' => '3rem'],
        'lg' => ['pad' => 'p-5 py-5', 'iconSize' => '1.65rem', 'circle' => '3.5rem'],
    ];
    $sz = $sizeMap[$size] ?? $sizeMap['default'];
@endphp

<div {{ $attributes->merge(['class' => 'mh-empty-state text-center d-flex flex-column align-items-center justify-content-center ' . $sz['pad']]) }}
     style="gap: var(--space-3); border-radius: var(--radius-lg);">
    <span class="mh-empty-state__icon d-inline-flex align-items-center justify-content-center rounded-circle"
          style="width: {{ $sz['circle'] }}; height: {{ $sz['circle'] }}; background: color-mix(in srgb, var(--color-primary) 10%, transparent); color: var(--color-primary); font-size: {{ $sz['iconSize'] }}; flex-shrink: 0;"
          aria-hidden="true">
        <i class="{{ $icon }}"></i>
    </span>

    <div class="mh-empty-state__body" style="max-width: 32rem;">
        <div class="mh-empty-state__title fw-semibold" style="font-size: var(--text-base); line-height: var(--leading-tight); color: var(--color-text);">
            {{ $title }}
        </div>
        @if ($message !== null && trim((string) $message) !== '')
            <p class="mh-empty-state__message mb-0 mt-1 text-muted small" style="font-size: var(--text-sm); line-height: var(--leading-normal);">
                {{ $message }}
            </p>
        @endif
        {{-- Allow extra slot content (e.g. secondary text) --}}
        @if (trim((string) ($slot ?? '')) !== '')
            <div class="mh-empty-state__slot mt-1 small text-muted" style="font-size: var(--text-sm);">
                {{ $slot }}
            </div>
        @endif
    </div>

    @if ($actionLabel && $actionUrl)
        <a href="{{ $actionUrl }}" class="btn btn-primary btn-sm d-inline-flex align-items-center gap-2 mt-1"
           style="border-radius: var(--radius-md); font-weight: var(--font-weight-medium);">
            {{ $actionLabel }}
        </a>
    @elseif ($actionLabel && ! $actionUrl)
        {{-- Action without URL: caller can wrap via slot or JS; render as button for accessibility --}}
        <span class="mh-empty-state__action-label small text-muted">{{ $actionLabel }}</span>
    @endif
</div>
