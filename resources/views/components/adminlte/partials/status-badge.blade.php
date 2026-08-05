@props(['status' => null, 'label' => null, 'map' => []])

@php
    $defaultMap = [
        // customer / hosting / domain statuses
        'active' => 'success',
        'inactive' => 'secondary',
        'suspended' => 'danger',
        'pending' => 'warning',
        'expired' => 'danger',
        'cancelled' => 'secondary',
        'terminated' => 'secondary',
        // invoice statuses
        'paid' => 'success',
        'sent' => 'info',
        'draft' => 'secondary',
        'overdue' => 'danger',
        'refunded' => 'warning',
        // ticket priority / status
        'low' => 'info',
        'medium' => 'warning',
        'high' => 'danger',
        'urgent' => 'dark',
        'open' => 'primary',
        'closed' => 'secondary',
        'on_hold' => 'warning',
        'answered' => 'info',
        // quote stages
        'accepted' => 'success',
        'declined' => 'danger',
        'expired' => 'secondary',
    ];
    $color = $map[$status] ?? $defaultMap[$status] ?? 'secondary';
    $text = $label ?? ucfirst(str_replace('_', ' ', (string) $status));
@endphp

<span class="badge bg-{{ $color }}">{{ $text }}</span>
