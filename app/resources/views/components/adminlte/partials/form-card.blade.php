@props([
    'title' => 'Form',
    'icon' => null,
    'action' => '#',
    'method' => 'POST',
    'submitLabel' => 'Save',
    'submitIcon' => 'bi bi-check-lg',
    'cancelUrl' => null,
    'cancelLabel' => 'Cancel',
    'showFooter' => true,
    'formId' => null,
])

<x-adminlte-card :icon="$icon" :title="$title">
    @isset($tools)
        <x-slot name="tools">
            {{ $tools }}
        </x-slot>
    @endisset

    <form method="POST" action="{{ $action }}" @if ($formId) id="{{ $formId }}" @endif>
        @csrf
        @unless (in_array($method, ['GET', 'POST'], true))
            @method($method)
        @endunless

        {{ $slot }}

        @if ($showFooter)
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
        @endif
    </form>
</x-adminlte-card>
