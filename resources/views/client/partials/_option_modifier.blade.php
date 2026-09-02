{{--
    Inline price-modifier chip for a product option value.

    Renders a signed, per-billing-cycle price modifier such as "+₹5.00/mo" or
    "-₹60.00/yr". A zero modifier still renders as "+₹0.00/mo" so the caller
    decides whether to include it.

    @param float  $modifier  price modifier for the billing cycle
    @param string $cycle     billing cycle key (Order::BILLING_CYCLES vocabulary)
--}}
@php
    $optionCycleSuffix = [
        'free' => 'free',
        'one_time' => 'once',
        'monthly' => 'mo',
        'quarterly' => 'qtr',
        'semi_annual' => '6mo',
        'annual' => 'yr',
        'biennial' => '2yr',
        'triennial' => '3yr',
    ];

    $modifier = (float) $modifier;
    $cycle = (string) $cycle;
    $sign = $modifier < 0 ? '-' : '+';
    $suffix = $cycle === 'one_time' ? ' once' : '/'.($optionCycleSuffix[$cycle] ?? 'mo');
@endphp
<span class="small fw-semibold {{ $modifier < 0 ? 'text-danger' : 'text-success' }}">
    {{ $sign }}₹{{ number_format(abs($modifier), 2) }}{{ $suffix }}
</span>
