@extends('adminlte::page')

@section('title', 'Choose Server Type')

@section('content_header')
    <x-ui.page-header title="Choose Server Type" subtitle="Start by picking the type — it cannot be changed after creation" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Servers', 'url' => route('admin.servers.index')],
        ['label' => 'Choose Type', 'active' => true],
    ]" />
@stop

@section('content')
@php
    // Provided by ServerController::createType via IntegrationRegistry::serverTypeOptions() grouped
    // Fallback to empty groups when controller renders without the registry (tests).
    $grouped = $grouped ?? [];
    $allGroups = ['panel' => 'Panel', 'virtualization' => 'Virtualization', 'compute' => 'Compute'];
    $groupIcons = ['panel' => 'bi bi-window', 'virtualization' => 'bi bi-hdd-stack', 'compute' => 'bi bi-cpu'];
    $groupDescriptions = [
        'panel' => 'Shared hosting control panels — cPanel/WHM, Plesk, DirectAdmin.',
        'virtualization' => 'Virtualization & compute — Hyper-V, Proxmox, KVM.',
        'compute' => 'Compute & bare metal.',
    ];
    // Icon per slug fallback
    $slugIcon = [
        'cpanel' => 'bi bi-window-stack',
        'directadmin' => 'bi bi-window',
        'plesk' => 'bi bi-window-split',
        'virtualizor' => 'bi bi-hdd-stack',
        'hyperv' => 'bi bi-microsoft',
        'proxmox' => 'bi bi-server',
    ];
@endphp

    @if (empty($grouped))
        <x-adminlte-alert theme="warning">
            No server types are available.
        </x-adminlte-alert>
    @endif

    @foreach ($allGroups as $groupKey => $groupLabel)
        @php $items = $grouped[$groupKey] ?? []; @endphp
        @if (count($items) > 0 || $groupKey === 'panel')
            <div class="mb-4">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-2 bg-body-secondary" style="width:28px;height:28px;">
                        <i class="{{ $groupIcons[$groupKey] ?? 'bi bi-grid' }}"></i>
                    </span>
                    <h5 class="mb-0 fw-semibold" style="font-size:var(--text-base); letter-spacing:var(--tracking-tight);">{{ $groupLabel }}</h5>
                    <span class="badge text-bg-light border fw-normal">{{ count($items) }} {{ Str::plural('type', count($items)) }}</span>
                </div>
                @if (! empty($groupDescriptions[$groupKey]))
                    <p class="text-muted small mb-3" style="font-size:var(--text-sm);">{{ $groupDescriptions[$groupKey] }}</p>
                @endif

                @if (count($items) === 0)
                    <x-adminlte-card>
                        <p class="text-muted mb-0 small">No active types in this group.</p>
                    </x-adminlte-card>
                @else
                    <div class="row g-3">
                        @foreach ($items as $type)
                            @php
                                $slug = $type['slug'] ?? $type['value'] ?? '';
                                $label = $type['label'] ?? Str::title($slug);
                                $desc = $type['description'] ?? '';
                                $isInactive = ($type['status'] ?? 'active') !== 'active' || (($type['disabled'] ?? false) === true);
                                // Proxmox stub: module provides fail but is still active; we treat disabled flag from manifest/config
                                // Also if group proxmox but controller marks coming_soon
                                $comingSoon = ($type['coming_soon'] ?? false) || ($slug === 'proxmox' && ($type['disabled'] ?? false));
                                // fallback: if slug proxmox and description contains Coming soon
                                if ($slug === 'proxmox' && str_contains(strtolower($desc), 'coming soon')) { $comingSoon = true; }
                                $icon = $slugIcon[$slug] ?? 'bi bi-box';
                            @endphp
                            <div class="col-12 col-md-6 col-lg-4">
                                <div class="card h-100 border shadow-sm" style="border-radius:var(--radius-lg);">
                                    <div class="card-body d-flex flex-column p-3 p-md-4" style="gap:12px;">
                                        <div class="d-flex align-items-start gap-3">
                                            <span class="d-inline-flex align-items-center justify-content-center rounded-3 flex-shrink-0 {{ $comingSoon ? 'bg-body-secondary text-muted' : 'bg-primary text-white' }}" style="width:44px;height:44px; font-size:1.15rem;">
                                                <i class="{{ $icon }}"></i>
                                            </span>
                                            <div class="flex-grow-1 min-w-0">
                                                <h6 class="mb-1 fw-semibold text-truncate" style="font-size:var(--text-sm); letter-spacing:var(--tracking-tight);">{{ $label }}</h6>
                                                <p class="text-muted small mb-0" style="font-size:var(--text-xs); line-height:1.5; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;">{{ $desc !== '' ? $desc : 'Slug: '.$slug }}</p>
                                                <div class="mt-2 d-flex align-items-center gap-2 flex-wrap">
                                                    <span class="badge rounded-pill {{ $comingSoon ? 'text-bg-secondary' : 'text-bg-primary' }}" style="font-size:var(--text-xs); font-weight:500;">{{ $slug }}</span>
                                                    @if ($comingSoon)
                                                        <span class="badge rounded-pill text-bg-warning" style="font-size:var(--text-xs);">Coming soon</span>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                        <div class="mt-auto pt-2">
                                            @if ($comingSoon)
                                                <button type="button" class="btn btn-outline-secondary btn-sm w-100" disabled>
                                                    <i class="bi bi-clock me-1"></i> Coming soon
                                                </button>
                                            @else
                                                <a href="{{ route('admin.servers.create', ['type' => $slug]) }}" class="btn btn-primary btn-sm w-100">
                                                    <i class="bi bi-arrow-right me-1"></i> Select
                                                </a>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    @endforeach

    <div class="d-flex justify-content-start mt-2">
        <a href="{{ route('admin.servers.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Back to Servers
        </a>
    </div>
@stop
