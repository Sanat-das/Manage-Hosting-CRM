@props([
    'title' => null,
    'subtitle' => null,
    'breadcrumbs' => null,
])

<div class="row align-items-center g-3">
    {{-- Title --}}
    <div class="col-sm-6">
        @if ($title)
            <h1 class="m-0 h4 fw-semibold" style="font-family: var(--font-sans); letter-spacing: var(--tracking-tight); line-height: var(--leading-tight); font-size: var(--text-xl);">
                {{ $title }}
            </h1>
            @if ($subtitle)
                <p class="mb-0 mt-1 small text-body-secondary" style="font-size: var(--text-sm); line-height: var(--leading-normal);">{{ $subtitle }}</p>
            @endif
        @endif
        @if (! $title && trim($slot) !== '')
            {{ $slot }}
        @endif
    </div>

    {{-- Breadcrumbs / actions — 8px spacing via gap-2/gap-3 --}}
    <div class="col-sm-6 d-flex flex-column flex-sm-row align-items-sm-center justify-content-sm-end gap-2 gap-sm-3">
        @isset($actions)
            <div class="d-flex align-items-center gap-2 order-2 order-sm-1">
                {{ $actions }}
            </div>
        @endisset

        <div class="order-1 order-sm-2">
            {{-- Named slot <x-slot:breadcrumbs> wins over prop --}}
            @if (isset($breadcrumbs) && ! is_array($breadcrumbs) && trim((string) $breadcrumbs) !== '')
                {{ $breadcrumbs }}
            @elseif (is_array($breadcrumbs) && count($breadcrumbs) > 0)
                <x-ui.breadcrumb :items="$breadcrumbs" />
            @endif
        </div>
    </div>
</div>
