@props([
    'rows' => 5,
    'columns' => 4,
    'variant' => 'table', // table | card | list
])

@php
    $rows = max(1, min(12, (int) $rows));
    $columns = max(1, min(8, (int) $columns));
    $variant = in_array($variant, ['table', 'card', 'list'], true) ? $variant : 'table';
@endphp

{{-- Accessible skeleton: aria-busy + aria-label. Pure CSS shimmer via adminlte.css --}}

@if ($variant === 'table')
    <div {{ $attributes->merge(['class' => 'mh-skeleton mh-skeleton--table']) }} role="status" aria-busy="true" aria-label="Loading">
        <span class="visually-hidden">Loading...</span>
        @for ($r = 0; $r < $rows; $r++)
            <div class="mh-skeleton__row d-flex align-items-center" style="gap: var(--space-3); padding-block: var(--space-3);">
                @for ($c = 0; $c < $columns; $c++)
                    @php
                        // Vary widths for realistic row: first col shorter, last maybe fixed
                        $width = $c === 0 ? '5rem' : ($c === $columns - 1 ? '6rem' : '100%');
                        $height = $c === 0 ? '0.85rem' : '0.8rem';
                    @endphp
                    <span class="mh-skeleton__line flex-fill" style="height: {{ $height }}; max-width: {{ $c === $columns - 1 ? '6rem' : '100%' }}; width: {{ $width }}; border-radius: var(--radius-sm);"></span>
                @endfor
            </div>
            @if ($r < $rows - 1)
                <div class="mh-skeleton__divider" style="height: 1px; background: var(--bs-border-color); opacity: 0.6;"></div>
            @endif
        @endfor
    </div>
@elseif ($variant === 'card')
    <div {{ $attributes->merge(['class' => 'mh-skeleton mh-skeleton--card']) }} role="status" aria-busy="true" aria-label="Loading">
        <span class="visually-hidden">Loading...</span>
        <div class="d-flex flex-column" style="gap: var(--space-3);">
            @for ($r = 0; $r < $rows; $r++)
                <div class="card mh-skeleton__card" style="border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); border: 1px solid var(--bs-border-color); overflow: hidden;">
                    <div class="card-body d-flex flex-column" style="gap: var(--space-3); padding: var(--space-4);">
                        <span class="mh-skeleton__line" style="height: 1rem; width: 38%; border-radius: var(--radius-sm);"></span>
                        <span class="mh-skeleton__line" style="height: 0.8rem; width: 92%; border-radius: var(--radius-sm);"></span>
                        <span class="mh-skeleton__line" style="height: 0.8rem; width: 72%; border-radius: var(--radius-sm);"></span>
                        <div class="d-flex" style="gap: var(--space-2); margin-top: var(--space-1);">
                            <span class="mh-skeleton__line" style="height: 1.6rem; width: 5.5rem; border-radius: var(--radius-md);"></span>
                            <span class="mh-skeleton__line" style="height: 1.6rem; width: 5.5rem; border-radius: var(--radius-md);"></span>
                        </div>
                    </div>
                </div>
            @endfor
        </div>
    </div>
@else
    {{-- list variant --}}
    <div {{ $attributes->merge(['class' => 'mh-skeleton mh-skeleton--list']) }} role="status" aria-busy="true" aria-label="Loading">
        <span class="visually-hidden">Loading...</span>
        <div class="d-flex flex-column">
            @for ($r = 0; $r < $rows; $r++)
                <div class="d-flex align-items-center" style="gap: var(--space-3); padding-block: var(--space-3);">
                    <span class="mh-skeleton__avatar flex-shrink-0 rounded-circle" style="width: 2.25rem; height: 2.25rem;"></span>
                    <div class="flex-fill d-flex flex-column" style="gap: var(--space-2);">
                        <span class="mh-skeleton__line" style="height: 0.85rem; width: 42%; border-radius: var(--radius-sm);"></span>
                        <span class="mh-skeleton__line" style="height: 0.7rem; width: 68%; border-radius: var(--radius-sm);"></span>
                    </div>
                    <span class="mh-skeleton__line flex-shrink-0 d-none d-sm-inline-block" style="height: 0.75rem; width: 4.5rem; border-radius: var(--radius-sm);"></span>
                </div>
                @if ($r < $rows - 1)
                    <div class="mh-skeleton__divider" style="height: 1px; background: var(--bs-border-color); opacity: 0.6;"></div>
                @endif
            @endfor
        </div>
    </div>
@endif
