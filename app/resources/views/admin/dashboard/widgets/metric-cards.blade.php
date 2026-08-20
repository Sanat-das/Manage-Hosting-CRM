@props(['metrics' => []])

@if (!empty($metrics))
    @include('components.adminlte.partials.metric-cards', ['items' => $metrics])
@else
    <p class="text-muted mb-0">No metrics available.</p>
@endif
