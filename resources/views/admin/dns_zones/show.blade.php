@extends('adminlte::page')

@php
    $zoneDomain = $dnsZone->domain ?? $dnsZone->name ?? '—';
    $zoneType = $dnsZone->type ?? $dnsZone->zone_type ?? '—';
    $zonePrimaryNs = $dnsZone->primary_ns ?? $dnsZone->master_nameserver ?? null;
    $zoneAdminEmail = $dnsZone->admin_email ?? null;
    $zoneSerial = $dnsZone->serial ?? null;
    $zoneStatus = $dnsZone->status ?? 'active';
    $zoneRefresh = $dnsZone->refresh ?? null;
    $zoneRetry = $dnsZone->retry ?? null;
    $zoneExpire = $dnsZone->expire ?? null;
    $zoneTtl = $dnsZone->minimum_ttl ?? $dnsZone->ttl ?? null;
    $recordsCount = $dnsZone->records ? $dnsZone->records->count() : 0;
    $statusTheme = in_array(strtolower((string) $zoneStatus), ['active', 'enabled'], true) ? 'success' : 'secondary';
@endphp

@section('title', 'DNS Zone — '.$zoneDomain)

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">{{ $zoneDomain }}</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.dns-zones.index') }}">DNS Zones</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $zoneDomain }}</li>
            </ol>
        </div>
    </div>
@stop

@php
    $activeTab = (string) request()->query('tab', 'zone-info');
    $tabs = [
        ['id' => 'zone-info', 'label' => 'Zone Info', 'icon' => 'bi bi-info-circle'],
        ['id' => 'records', 'label' => 'Records', 'icon' => 'bi bi-list-nested', 'badge' => $recordsCount],
        ['id' => 'activity', 'label' => 'History', 'icon' => 'bi bi-clock-history'],
    ];
@endphp

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif
    @if (session('error'))
        <x-adminlte-alert theme="danger" dismissible>{{ session('error') }}</x-adminlte-alert>
    @endif
    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible>
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-adminlte-alert>
    @endif

    {{-- Header card --}}
    <x-adminlte-card>
        <div class="d-flex flex-wrap align-items-center gap-3">
            <div class="d-flex align-items-center justify-content-center rounded-circle bg-primary text-white"
                 style="width: 56px; height: 56px; font-size: 1.25rem; flex-shrink: 0;">
                <i class="bi bi-globe"></i>
            </div>
            <div class="flex-grow-1">
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <h4 class="mb-0">{{ $zoneDomain }}</h4>
                    <x-adminlte.partials.status-badge :status="$zoneStatus" />
                    <span class="badge text-bg-info">{{ ucfirst((string) $zoneType) }}</span>
                </div>
                <div class="text-muted mt-1">
                    @if ($zonePrimaryNs)
                        <i class="bi bi-hdd-network me-1"></i>{{ $zonePrimaryNs }}
                    @endif
                    @if ($zonePrimaryNs && $zoneAdminEmail)
                        <span class="mx-2">|</span>
                    @endif
                    @if ($zoneAdminEmail)
                        <i class="bi bi-envelope me-1"></i>{{ $zoneAdminEmail }}
                    @endif
                    @if (($zonePrimaryNs || $zoneAdminEmail) && $zoneSerial !== null)
                        <span class="mx-2">|</span>
                    @endif
                    @if ($zoneSerial !== null)
                        <i class="bi bi-hash me-1"></i>Serial {{ $zoneSerial }}
                    @endif
                    @if (!$zonePrimaryNs && !$zoneAdminEmail && $zoneSerial === null)
                        <span>{{ $recordsCount }} {{ \Illuminate\Support\Str::plural('record', $recordsCount) }}</span>
                        <span class="mx-2">|</span><span>TTL {{ $zoneTtl ?? '—' }}</span>
                    @endif
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('admin.dns-zones.records.index', $dnsZone) }}" class="btn btn-sm btn-outline-info">
                    <i class="bi bi-list-nested me-1"></i> Records
                </a>
                <a href="{{ route('admin.dns-zones.edit', $dnsZone) }}" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-pencil me-1"></i> Edit
                </a>
                <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal"
                        data-bs-target="#delete-dns-zone-modal">
                    <i class="bi bi-trash me-1"></i> Delete
                </button>
            </div>
        </div>
    </x-adminlte-card>

    {{-- Metric row --}}
    <x-adminlte.partials.metric-cards :items="[
        ['title' => (string) $recordsCount, 'text' => 'Records', 'icon' => 'bi bi-list-nested', 'theme' => 'primary'],
        ['title' => ucfirst((string) $zoneType), 'text' => 'Type', 'icon' => 'bi bi-diagram-3', 'theme' => 'info'],
        ['title' => ucfirst(str_replace('_', ' ', (string) $zoneStatus)), 'text' => 'Status', 'icon' => 'bi bi-check-circle', 'theme' => $statusTheme],
        ['title' => $zoneSerial !== null ? (string) $zoneSerial : '—', 'text' => 'Serial', 'icon' => 'bi bi-hash', 'theme' => 'warning'],
    ]" />

    {{-- Tabbed detail --}}
    <x-adminlte-card>
        <x-adminlte.partials.detail-tabs :tabs="$tabs" :active-tab="$activeTab">
            {{-- Zone Info --}}
            <div class="tab-pane fade {{ $activeTab === 'zone-info' ? 'show active' : '' }}" id="zone-info" role="tabpanel" aria-labelledby="zone-info-tab">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tbody>
                                <tr><th class="w-25 text-muted">Domain</th><td>{{ $zoneDomain }}</td></tr>
                                <tr><th class="text-muted">Type</th><td><span class="badge text-bg-info">{{ ucfirst((string) $zoneType) }}</span></td></tr>
                                <tr><th class="text-muted">Primary NS</th><td>{{ $zonePrimaryNs ?? '—' }}</td></tr>
                                <tr><th class="text-muted">Admin Email</th><td>{{ $zoneAdminEmail ?? '—' }}</td></tr>
                                <tr><th class="text-muted">Serial</th><td>{{ $zoneSerial !== null ? $zoneSerial : '—' }}</td></tr>
                                <tr><th class="text-muted">Status</th><td><x-adminlte.partials.status-badge :status="$zoneStatus" /></td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tbody>
                                <tr><th class="w-25 text-muted">Refresh</th><td>{{ $zoneRefresh !== null ? $zoneRefresh : '—' }}</td></tr>
                                <tr><th class="text-muted">Retry</th><td>{{ $zoneRetry !== null ? $zoneRetry : '—' }}</td></tr>
                                <tr><th class="text-muted">Expire</th><td>{{ $zoneExpire !== null ? $zoneExpire : '—' }}</td></tr>
                                <tr><th class="text-muted">Minimum TTL</th><td>{{ $zoneTtl !== null ? $zoneTtl : '—' }}</td></tr>
                                <tr><th class="text-muted">Records</th><td>{{ $recordsCount }}</td></tr>
                                <tr><th class="text-muted">Created</th><td>{{ $dnsZone->created_at?->format('M j, Y H:i') ?? '—' }}</td></tr>
                                <tr><th class="text-muted">Updated</th><td>{{ $dnsZone->updated_at?->format('M j, Y H:i') ?? '—' }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- Records --}}
            <div class="tab-pane fade {{ $activeTab === 'records' ? 'show active' : '' }}" id="records" role="tabpanel" aria-labelledby="records-tab">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="text-muted mb-0"><i class="bi bi-list-nested me-1"></i> DNS Records</h6>
                    @if (\Illuminate\Support\Facades\Route::has('admin.dns-zones.records.create'))
                        <a href="{{ route('admin.dns-zones.records.create', $dnsZone) }}" class="btn btn-sm btn-primary">
                            <i class="bi bi-plus-lg me-1"></i> Add Record
                        </a>
                    @endif
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Content</th>
                                <th>TTL</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($dnsZone->records as $record)
                                <tr>
                                    <td><code>{{ $record->name }}</code></td>
                                    <td><span class="badge text-bg-secondary">{{ $record->type }}</span></td>
                                    <td><code>{{ \Illuminate\Support\Str::limit((string) $record->content, 40) }}</code></td>
                                    <td class="text-muted">{{ $record->ttl ?? '—' }}</td>
                                    <td class="text-end">
                                        @if (\Illuminate\Support\Facades\Route::has('admin.dns-zones.records.show'))
                                            <a href="{{ route('admin.dns-zones.records.show', [$dnsZone, $record]) }}" class="btn btn-sm btn-outline-secondary" title="View">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                        @endif
                                        @if (\Illuminate\Support\Facades\Route::has('admin.dns-zones.records.edit'))
                                            <a href="{{ route('admin.dns-zones.records.edit', [$dnsZone, $record]) }}" class="btn btn-sm btn-outline-primary" title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-muted py-3">No records yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($recordsCount === 0 && \Illuminate\Support\Facades\Route::has('admin.dns-zones.records.create'))
                    <div class="text-center mt-3">
                        <a href="{{ route('admin.dns-zones.records.create', $dnsZone) }}" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-plus-lg me-1"></i> Create first record
                        </a>
                    </div>
                @endif
            </div>

            {{-- History / Activity --}}
            <div class="tab-pane fade {{ $activeTab === 'activity' ? 'show active' : '' }}" id="activity" role="tabpanel" aria-labelledby="activity-tab">
                <h6 class="text-muted mb-3"><i class="bi bi-clock-history me-1"></i> Activity</h6>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-borderless">
                        <tbody>
                            <tr><th class="w-25 text-muted">Created</th><td>{{ $dnsZone->created_at?->format('M j, Y H:i') ?? '—' }}</td></tr>
                            <tr><th class="text-muted">Last updated</th><td>{{ $dnsZone->updated_at?->format('M j, Y H:i') ?? '—' }}</td></tr>
                            <tr><th class="text-muted">Zone ID</th><td><code>#{{ $dnsZone->id }}</code></td></tr>
                            <tr><th class="text-muted">Domain</th><td><code>{{ $zoneDomain }}</code></td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="text-center py-4 border rounded bg-light">
                    <i class="bi bi-clock-history text-muted" style="font-size: 1.5rem;"></i>
                    <p class="text-muted mb-0 mt-1">No activity history yet.</p>
                    <small class="text-muted">Zone audit trail will appear here when available.</small>
                </div>
            </div>
        </x-adminlte.partials.detail-tabs>
    </x-adminlte-card>

    <x-adminlte.partials.confirm-modal
        id="delete-dns-zone-modal"
        title="Delete DNS zone"
        :message="'Delete DNS zone ' . $zoneDomain . '? This permanently removes the zone and all its records. This cannot be undone.'"
        :action="route('admin.dns-zones.destroy', $dnsZone)"
        confirm-label="Delete zone"
    />
@stop
