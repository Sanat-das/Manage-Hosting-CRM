{{-- Compact CPU/RAM/Disk usage gauge for one grid cell: mini progress bar
     + percentage, colored green (<70%) / amber (70-85%) / red (>85%). --}}
@php
    $value = $value ?? null;
    $level = $value === null ? 'secondary' : ($value < 70 ? 'success' : ($value <= 85 ? 'warning' : 'danger'));
@endphp
@if ($value === null)
    <span class="text-muted small">&mdash;</span>
@else
    <div class="d-flex align-items-center gap-1" style="min-width:80px;">
        <div class="progress flex-grow-1" style="height:6px;">
            <div class="progress-bar text-bg-{{ $level }}" style="width:{{ min(100, max(0, $value)) }}%;"></div>
        </div>
        <span class="small text-{{ $level }} fw-semibold" style="width:3.4em;text-align:right;">{{ number_format($value, 0) }}%</span>
    </div>
@endif
