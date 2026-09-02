@props(['status' => null, 'label' => null, 'map' => [], 'variant' => 'solid'])

@php
    /*
     * Single source of truth for status colours across admin + client.
     *
     * One status token = one colour, everywhere. If a screen needs a different
     * colour for a token, pass an explicit :map — do not hand-roll a badge, or
     * the same status ends up a different colour on different pages.
     *
     * Colours are applied with Bootstrap 5.3 `text-bg-*` for solid variant
     * (background + contrast-correct foreground). Subtle variant uses
     * color-mix 12% of the token colour as background + token colour as text
     * (via inline style), respecting tokens.css --color-*.
     *
     * Visuals upgraded: rounded-pill, var(--text-xs), 500 weight, 0.02em tracking.
     * Backward compat: variant defaults to 'solid', existing 119 usages unaffected.
     */
    $defaultMap = [
        // customer / hosting / domain lifecycle
        'active' => 'success',
        'inactive' => 'secondary',
        'suspended' => 'danger',
        'pending' => 'warning',
        'expired' => 'danger',
        'cancelled' => 'secondary',
        'terminated' => 'secondary',
        // invoice / payment statuses
        'paid' => 'success',
        'unpaid' => 'danger',
        'partially_paid' => 'warning',
        'partial' => 'warning',
        'sent' => 'info',
        'draft' => 'secondary',
        'overdue' => 'danger',
        'void' => 'dark',
        'refunded' => 'warning',
        // ticket priority / status
        'low' => 'info',
        'medium' => 'warning',
        'high' => 'danger',
        'urgent' => 'dark',
        'open' => 'primary',
        'closed' => 'secondary',
        'on_hold' => 'warning',
        'in_progress' => 'info',
        'answered' => 'info',
        'customer_reply' => 'danger',
        'resolved' => 'success',
        'waiting' => 'warning',
        // quote stages
        'delivered' => 'info',
        'accepted' => 'success',
        'rejected' => 'danger',
        'dead' => 'secondary',
        // job / provisioning / delivery outcomes
        'success' => 'success',
        'completed' => 'success',
        'failed' => 'danger',
        'queued' => 'info',
        'running' => 'info',
        'processing' => 'info',
        'skipped' => 'secondary',
        // module / feature toggles
        'enabled' => 'success',
        'disabled' => 'secondary',
        'configured' => 'success',
        'installed' => 'info',
        'crashed' => 'danger',
        // content states
        'published' => 'success',
        'archived' => 'secondary',
        // IPAM statuses
        'available' => 'success',
        'assigned' => 'primary',
        'in-use' => 'primary',
        'reserved' => 'warning',
        'floating' => 'info',
        'nat' => 'info',
        'gateway' => 'dark',
        'broadcast' => 'dark',
        'network' => 'secondary',
    ];

    $key = (string) $status;
    $lookup = strtolower($key);

    $color = $map[$key]
        ?? $map[$lookup]
        ?? $defaultMap[$key]
        ?? $defaultMap[$lookup]
        ?? 'secondary';

    $text = $label ?? ucfirst(str_replace('_', ' ', $key));

    $variant = in_array($variant, ['solid', 'subtle'], true) ? $variant : 'solid';

    // Map bootstrap theme → token CSS var for subtle mode
    $tokenMap = [
        'primary'   => 'var(--color-primary)',
        'secondary' => 'var(--color-neutral-500)',
        'success'   => 'var(--color-success)',
        'danger'    => 'var(--color-danger)',
        'warning'   => 'var(--color-warning)',
        'info'      => 'var(--color-info)',
        'dark'      => 'var(--color-neutral-800)',
        'light'     => 'var(--color-neutral-200)',
    ];
    $tokenColor = $tokenMap[$color] ?? 'var(--color-neutral-500)';
@endphp

@if ($variant === 'subtle')
    <span {{ $attributes->merge(['class' => 'badge rounded-pill mh-badge mh-badge--subtle']) }}
          style="font-size: var(--text-xs); font-weight: 500; letter-spacing: 0.02em; padding: 0.28em 0.62em; background: color-mix(in srgb, {{ $tokenColor }} 12%, var(--color-surface)); color: {{ $tokenColor }}; border: 1px solid color-mix(in srgb, {{ $tokenColor }} 18%, transparent); line-height: 1.2;">
        {{ $text }}
    </span>
@else
    <span {{ $attributes->merge(['class' => 'badge rounded-pill text-bg-'.$color.' mh-badge mh-badge--solid']) }}
          style="font-size: var(--text-xs); font-weight: 500; letter-spacing: 0.02em; padding: 0.28em 0.62em; line-height: 1.2;">
        {{ $text }}
    </span>
@endif
