@extends('adminlte::page')
@section('title', 'DNS Zone — '.$dnsZone->domain)
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">{{ $dnsZone->domain }}</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item"><a href="{{ route('admin.dns-zones.index') }}">DNS Zones</a></li><li class="breadcrumb-item active">{{ $dnsZone->domain }}</li></ol></div></div>
@stop
@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif
    <div class="d-flex justify-content-end mb-3">
        <a href="{{ route('admin.dns-zones.records.index', $dnsZone) }}" class="btn btn-sm btn-outline-info me-1"><i class="bi bi-list-nested me-1"></i> Records ({{ $dnsZone->records->count() }})</a>
        <a href="{{ route('admin.dns-zones.edit', $dnsZone) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i> Edit</a>
    </div>
    <div class="row">
        <div class="col-md-6">
            <x-adminlte-card icon="bi bi-info-circle" title="Zone Details">
                <table class="table table-sm table-borderless mb-0">
                    <tbody>
                        <tr><th class="text-muted w-25">Domain</th><td>{{ $dnsZone->domain }}</td></tr>
                        <tr><th class="text-muted">Type</th><td><span class="badge bg-info">{{ ucfirst($dnsZone->type) }}</span></td></tr>
                        <tr><th class="text-muted">Primary NS</th><td>{{ $dnsZone->primary_ns ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Admin Email</th><td>{{ $dnsZone->admin_email ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Serial</th><td>{{ $dnsZone->serial ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Status</th><td><x-adminlte.partials.status-badge :status="$dnsZone->status" /></td></tr>
                    </tbody>
                </table>
            </x-adminlte-card>
        </div>
        <div class="col-md-6">
            <x-adminlte-card icon="bi bi-list-nested" title="Recent Records">
                @forelse ($dnsZone->records->take(10) as $record)
                    <div class="d-flex justify-content-between">
                        <span><code>{{ $record->name }}</code> <span class="badge bg-secondary">{{ $record->type }}</span></span>
                        <span class="text-muted">{{ Str::limit($record->content, 30) }}</span>
                    </div>
                @empty
                    <p class="text-muted mb-0">No records yet.</p>
                @endforelse
                @if ($dnsZone->records->count() > 10)
                    <a href="{{ route('admin.dns-zones.records.index', $dnsZone) }}" class="btn btn-sm btn-outline-primary w-100 mt-2">View All</a>
                @endif
            </x-adminlte-card>
        </div>
    </div>
@stop
