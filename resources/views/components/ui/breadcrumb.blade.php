@props([
    'items' => [],
    'showHome' => true,
])

@php
    // Normalise: each item is ['label' => string, 'url' => ?string, 'active' => bool]
    // Last item is always active unless explicitly overridden.
    $crumbs = collect($items)->map(function ($item, $idx) use ($items) {
        $isLast = $idx === count($items) - 1;
        return [
            'label'  => $item['label'] ?? $item['text'] ?? '',
            'url'    => $item['url'] ?? $item['href'] ?? null,
            'active' => $item['active'] ?? $isLast,
        ];
    })->filter(fn ($c) => $c['label'] !== '')->values();

    if ($showHome && $crumbs->isNotEmpty()) {
        $hasHome = $crumbs->contains(fn ($c) => str($c['label'])->lower()->value() === __('adminlte.home') || strtolower($c['label']) === 'home');
        if (! $hasHome) {
            $crumbs->prepend([
                'label'  => __('adminlte.home'),
                'url'    => url('/'),
                'active' => false,
            ]);
        }
    }
@endphp

<nav aria-label="breadcrumb">
    <ol class="breadcrumb float-sm-end mb-0" style="--bs-breadcrumb-divider: '/'; gap: var(--space-1, 0.25rem);">
        @foreach ($crumbs as $crumb)
            @if ($crumb['active'])
                <li class="breadcrumb-item active" aria-current="page">{{ $crumb['label'] }}</li>
            @else
                <li class="breadcrumb-item">
                    @if ($crumb['url'])
                        <a href="{{ $crumb['url'] }}" class="text-decoration-none link-secondary">{{ $crumb['label'] }}</a>
                    @else
                        {{ $crumb['label'] }}
                    @endif
                </li>
            @endif
        @endforeach
    </ol>
</nav>
