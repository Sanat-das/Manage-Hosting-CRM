@props(['widget' => []])

<div class="col-12 {{ $widget['size'] ?? '' }} mb-3" data-widget-key="{{ $widget['key'] }}">
    <x-adminlte-card :title="$widget['title'] ?? ''" :icon="$widget['icon'] ?? null">
        <x-slot name="tools">
            @include('admin.dashboard._widget-tools')
        </x-slot>
        @include($widget['view'], $widget['data'] ?? [])
    </x-adminlte-card>
</div>
