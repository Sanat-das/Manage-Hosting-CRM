@props(['tabs' => [], 'activeTab' => null])

@php
    $activeTab = $activeTab ?: (string) request()->query('tab', $tabs[0]['id'] ?? 'profile');
@endphp

<ul class="nav nav-tabs" role="tablist">
    @foreach ($tabs as $tab)
        @php $active = $activeTab === ($tab['id'] ?? ''); @endphp
        <li class="nav-item" role="presentation">
            <button
                class="nav-link @if ($active) active @endif"
                id="{{ $tab['id'] }}-tab"
                data-bs-toggle="tab"
                data-bs-target="#{{ $tab['id'] }}"
                type="button"
                role="tab"
                aria-controls="{{ $tab['id'] }}"
                @if ($active) aria-selected="true" @else aria-selected="false" @endif
            >
                @if (! empty($tab['icon']))
                    <i class="{{ $tab['icon'] }} me-2"></i>
                @endif
                {{ $tab['label'] ?? $tab['id'] }}
                @if (! empty($tab['badge']))
                    <span class="badge bg-secondary ms-1">{{ $tab['badge'] }}</span>
                @endif
            </button>
        </li>
    @endforeach
</ul>

<div class="tab-content pt-3">
    {{ $slot }}
</div>
