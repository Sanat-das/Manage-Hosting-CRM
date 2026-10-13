@php
    $threshold = \App\ViewModels\Admin\CapacityPressureViewModel::THRESHOLD_PERCENT;

    // Bars are drawn from an integer width so a raw float never reaches the
    // style attribute, and an over-100% utilisation can never overflow the track.
    $diskBarWidth = min(100, max(0, (int) round($capacity->diskUsedPercent)));
    $bandwidthBarWidth = min(100, max(0, (int) round($capacity->bandwidthUsedPercent)));

    $diskBarClass = $capacity->diskUsedPercent >= $threshold ? 'bg-danger' : 'bg-success';
    $bandwidthBarClass = $capacity->bandwidthUsedPercent >= $threshold ? 'bg-danger' : 'bg-success';

    $overThreshold = $capacity->diskUsedPercent >= $threshold
        || $capacity->bandwidthUsedPercent >= $threshold;

    $diskBarLabel = (int) round($capacity->diskUsedPercent);
    $bandwidthBarLabel = (int) round($capacity->bandwidthUsedPercent);
@endphp

<x-adminlte-card icon="bi bi-hdd-stack" title="Capacity pressure">
    <x-slot:tools>
        <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2" data-widget-toggle title="Hide widget" aria-pressed="false" style="border-radius: var(--radius-md); font-size: var(--text-xs);"><i class="bi bi-eye"></i><span class="visually-hidden">Hide widget</span></button>
        @if ($overThreshold)
            <span class="badge rounded-pill text-bg-danger" style="font-size: var(--text-xs); font-weight: 500; letter-spacing: 0.02em; padding: 0.28em 0.62em;">Over capacity</span>
        @else
            <span class="badge rounded-pill text-bg-success" style="font-size: var(--text-xs); font-weight: 500; letter-spacing: 0.02em; padding: 0.28em 0.62em;">Within quota</span>
        @endif
        @can('hosting.view')
            <a href="{{ route('admin.servers.index') }}" class="btn btn-sm btn-outline-secondary py-1 px-2" style="border-radius: var(--radius-md); font-size: var(--text-xs);">View all</a>
        @endcan
    </x-slot:tools>

    @if ($capacity->accountsWithDiskQuota === 0)
        <x-adminlte.partials.empty-state
            icon="bi bi-hdd-stack"
            title="No quotas are set"
            message="No hosting account has a disk quota, so capacity pressure cannot be calculated. Disk and bandwidth quotas are tracked in MB once accounts carry them."
            size="sm" />
    @else
        <div class="mb-3">
            <div class="d-flex justify-content-between gap-2 small">
                <span class="text-muted">Disk used</span>
                <span class="fw-semibold" style="font-variant-numeric: tabular-nums;">{{ $diskBarLabel }}%</span>
            </div>
            <div class="progress mt-1" style="height: 1.1rem; font-size: var(--text-xs); font-weight: 600;" role="progressbar" aria-valuenow="{{ $diskBarWidth }}" aria-valuemin="0" aria-valuemax="100" title="Disk used {{ $diskBarLabel }}%">
                <div class="progress-bar {{ $diskBarClass }}" style="width: {{ $diskBarWidth }}%">{{ $diskBarLabel }}%</div>
            </div>
            <div class="text-muted mt-1" style="font-size: var(--text-xs);">
                {{ $capacity->accountsOverDiskThreshold }} of {{ $capacity->accountsWithDiskQuota }} accounts over {{ $threshold }}%
            </div>
        </div>
    @endif

    {{-- Bandwidth quotas sit on their own population: a disk denominator here
         would state a false M. With no bandwidth quota there is nothing to
         divide, so the block degrades to one line instead of a 0% bar. --}}
    @if ($capacity->accountsWithBandwidthQuota === 0)
        <div class="mb-0 text-muted" style="font-size: var(--text-xs);">No bandwidth quotas set</div>
    @else
        <div class="mb-0">
            <div class="d-flex justify-content-between gap-2 small">
                <span class="text-muted">Bandwidth used</span>
                <span class="fw-semibold" style="font-variant-numeric: tabular-nums;">{{ $bandwidthBarLabel }}%</span>
            </div>
            <div class="progress mt-1" style="height: 1.1rem; font-size: var(--text-xs); font-weight: 600;" role="progressbar" aria-valuenow="{{ $bandwidthBarWidth }}" aria-valuemin="0" aria-valuemax="100" title="Bandwidth used {{ $bandwidthBarLabel }}%">
                <div class="progress-bar {{ $bandwidthBarClass }}" style="width: {{ $bandwidthBarWidth }}%">{{ $bandwidthBarLabel }}%</div>
            </div>
            <div class="text-muted mt-1" style="font-size: var(--text-xs);">
                {{ $capacity->accountsOverBandwidthThreshold }} of {{ $capacity->accountsWithBandwidthQuota }} accounts over {{ $threshold }}%
            </div>
        </div>
    @endif
</x-adminlte-card>
