@props([
    'title' => 'Form',
    'icon' => null,
    'action' => '#',
    'method' => 'POST',
    'submitLabel' => 'Save',
    'submitIcon' => 'bi bi-check-lg',
    'cancelUrl' => null,
    'cancelLabel' => 'Cancel',
])

<x-adminlte-card :icon="$icon" :title="$title">
    <form method="POST" action="{{ $action }}">
        @csrf
        @unless (in_array($method, ['GET', 'POST'], true))
            @method($method)
        @endunless

        {{ $slot }}

        <div class="d-flex gap-2 mt-2">
            @if ($cancelUrl)
                <a href="{{ $cancelUrl }}" class="btn btn-outline-secondary">{{ $cancelLabel }}</a>
            @endif
            <button type="submit" class="btn btn-primary">
                @if ($submitIcon)
                    <i class="{{ $submitIcon }} me-1" aria-hidden="true"></i>
                @endif
                {{ $submitLabel }}
            </button>
        </div>
    </form>
</x-adminlte-card>
