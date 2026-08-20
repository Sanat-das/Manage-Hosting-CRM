@props(['chartConfig' => []])

@if (!empty($chartConfig))
    <div data-apexchart data-apexchart-config="{{ json_encode($chartConfig) }}"></div>
    <p class="text-muted small mt-2 mb-0">Monthly revenue trend.</p>
@else
    <p class="text-muted mb-0">No revenue data yet.</p>
@endif
