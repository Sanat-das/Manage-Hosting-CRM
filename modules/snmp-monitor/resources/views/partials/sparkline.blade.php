{{-- Server-rendered CPU trend sparkline for one listing row. No JS/Chart.js
     needed for a 90x24 inline chart — keeps the listing page light. --}}
@php
    $points = $points ?? [];
    $known = array_values(array_filter($points, fn ($v) => $v !== null));
    $w = 90;
    $h = 24;
    $pad = 2;
@endphp
@if (count($known) < 2)
    <span class="text-muted small">&mdash;</span>
@else
    @php
        $min = min($known);
        $max = max($known);
        $range = $max - $min;
        $n = count($points);
        $step = $n > 1 ? ($w - 2 * $pad) / ($n - 1) : 0;
        $coords = [];

        foreach ($points as $i => $v) {
            if ($v === null) {
                continue;
            }

            $x = $pad + $i * $step;
            $y = $range > 0
                ? $h - $pad - (($v - $min) / $range) * ($h - 2 * $pad)
                : $h / 2;

            $coords[] = round($x, 1).','.round($y, 1);
        }

        $trendColor = end($known) > reset($known) ? '#dc3545' : (end($known) < reset($known) ? '#198754' : '#6c757d');
    @endphp
    <svg width="{{ $w }}" height="{{ $h }}" viewBox="0 0 {{ $w }} {{ $h }}" class="align-middle" aria-hidden="true">
        <polyline fill="none" stroke="{{ $trendColor }}" stroke-width="1.5" points="{{ implode(' ', $coords) }}" />
    </svg>
@endif
