@props(['value' => 0, 'symbol' => '₹'])

<span {{ $attributes->merge(['class' => 'mh-currency']) }} style="font-variant-numeric: tabular-nums; font-feature-settings: 'tnum'; white-space: nowrap;">{{ $symbol }}{{ number_format((float) $value, 2) }}</span>
