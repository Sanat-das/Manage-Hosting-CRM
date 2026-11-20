@extends('adminlte::page')

@section('title', 'Rack — '.$rack->name)

@section('content_header')
    <x-ui.page-header title="{{ $rack->name }}" subtitle="View rack details" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Racks', 'url' => route('admin.racks.index')],
        ['label' => $rack->name, 'active' => true],
    ]" />
@stop

@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif

    <div class="d-flex justify-content-end mb-3">
        <a href="{{ route('admin.racks.edit', $rack) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i> Edit</a>
    </div>

    <div class="row">
        <div class="col-md-6">
            <x-adminlte-card icon="bi bi-info-circle" title="Details">
                <table class="table table-sm table-borderless mb-0">
                    <tbody>
                        <tr><th class="text-muted w-25">Name</th><td>{{ $rack->name }}</td></tr>
                        <tr><th class="text-muted">Datacenter</th><td>{{ $rack->datacenter?->name ?? '—' }}</td></tr>
                        <tr><th class="text-muted">U Height</th><td>{{ $rack->u_height ?? '—' }}U</td></tr>
                        <tr><th class="text-muted">Assets in rack</th><td>{{ $rack->devices->count() }}</td></tr>
                        <tr><th class="text-muted">Power</th><td>{{ $rack->power_capacity_watts ? $rack->power_capacity_watts . ' W' : '—' }}</td></tr>
                        <tr><th class="text-muted">Status</th><td><x-adminlte.partials.status-badge :status="$rack->status" /></td></tr>
                    </tbody>
                </table>
            </x-adminlte-card>
        </div>
        <div class="col-md-6">
            @php
                $uHeight = max(0, (int) ($rack->u_height ?? 0));
                $face = request()->query('face') === 'rear' ? 'rear' : 'front';
                $faceDevices = $rack->devices->filter(fn ($device) => ($device->face ?? 'front') === $face);
                $assetsByPosition = $faceDevices->keyBy('rack_u_position');
                $faceUrl = fn (string $f) => route('admin.racks.show', $rack).'?'.http_build_query(array_merge(request()->query(), ['face' => $f]));
            @endphp

            <x-adminlte-card icon="bi bi-list-ol" title="Rack Elevation ({{ $uHeight }}U)">
                <div class="mb-2">
                    <a href="{{ $faceUrl('front') }}" class="btn btn-sm {{ $face === 'front' ? 'btn-primary' : 'btn-outline-secondary' }}" @if ($face === 'front') aria-current="true" @endif>Front</a>
                    <a href="{{ $faceUrl('rear') }}" class="btn btn-sm {{ $face === 'rear' ? 'btn-primary' : 'btn-outline-secondary' }}" @if ($face === 'rear') aria-current="true" @endif>Rear</a>
                </div>
                @if ($uHeight > 0)
                    @if ($faceDevices->isEmpty())
                        <p class="text-muted">No devices mounted on the {{ $face }} face.</p>
                    @endif
                    <div class="table-responsive" style="max-height: 420px; overflow-y: auto;">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="text-muted">
                                <tr>
                                    <th style="width: 3.5rem;">U</th>
                                    <th>Device</th>
                                </tr>
                            </thead>
                            <tbody>
                                @for ($position = $uHeight; $position >= 1; $position--)
                                    @php $slotAsset = $assetsByPosition->get($position); @endphp
                                    @if ($slotAsset)
                                        <tr data-u-position="{{ $position }}"><td class="text-muted" style="width: 3.5rem;">{{ $position }}U</td><td><a href="{{ route('admin.devices.show', $slotAsset) }}">{{ $slotAsset->asset_tag }}</a> <x-adminlte.partials.status-badge :status="$slotAsset->status ?? 'active'" />@if (($slotAsset->face ?? 'front') === 'rear') <span class="badge text-bg-secondary">Rear</span>@endif</td></tr>
                                    @else
                                        <tr data-u-position="{{ $position }}"><td class="text-muted" style="width: 3.5rem;">{{ $position }}U</td><td><span class="text-muted rack-slot-empty">—</span></td></tr>
                                    @endif
                                @endfor
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-muted mb-0">Rack height not set.</p>
                @endif
            </x-adminlte-card>

            <x-adminlte-card icon="bi bi-hdd-stack" title="Devices">
                @if ($rack->devices->count())
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="text-muted">
                                <tr>
                                    <th>Asset Tag</th>
                                    <th style="width: 3.5rem;">U</th>
                                    <th style="width: 6rem;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rack->devices as $asset)
                                    <tr><td><a href="{{ route('admin.devices.show', $asset) }}">{{ $asset->asset_tag }}</a></td><td class="text-muted">{{ $asset->rack_u_position ?? '—' }}</td><td><x-adminlte.partials.status-badge :status="$asset->status ?? 'active'" /></td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-muted mb-0">No devices in this rack.</p>
                @endif
            </x-adminlte-card>
        </div>
    </div>
@stop
