@extends('adminlte::page')

@section('title', 'License — '.$license->license_type)

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">{{ $license->license_type }}</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.licenses.index') }}">Licenses</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $license->license_type }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif
    <div class="d-flex justify-content-end mb-3">
        <a href="{{ route('admin.licenses.edit', $license) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i> Edit</a>
    </div>

    <div class="row">
        <div class="col-md-8">
            <x-adminlte-card icon="bi bi-info-circle" title="Details">
                <table class="table table-sm table-borderless mb-0">
                    <tbody>
                        <tr><th class="text-muted w-25">Type</th><td>{{ $license->license_type }}</td></tr>
                        <tr><th class="text-muted">Vendor</th><td>{{ $license->vendor ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Key</th><td><code>{{ $license->license_key }}</code></td></tr>
                        <tr><th class="text-muted">Seats</th><td>{{ $license->seats_available ?? '—' }} / {{ $license->seats ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Cost</th><td>{{ $license->cost ? '$' . number_format($license->cost, 2) : '—' }}</td></tr>
                        <tr><th class="text-muted">Expiry</th><td>{{ $license->expiry_date?->format('Y-m-d') ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Renewal</th><td>{{ $license->renewal_date?->format('Y-m-d') ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Status</th><td><x-adminlte.partials.status-badge :status="$license->status" /></td></tr>
                        <tr><th class="text-muted">PO</th><td>{{ $license->purchase_order ?? '—' }}</td></tr>
                    </tbody>
                </table>
            </x-adminlte-card>
        </div>
        <div class="col-md-4">
            <x-adminlte-card icon="bi bi-people" title="Assignments">
                @forelse ($license->assignments as $assignment)
                    <div class="d-flex justify-content-between">
                        <span>{{ $assignment->service?->domain ?? $assignment->customer?->full_name ?? '—' }}</span>
                        <x-adminlte.partials.status-badge :status="$assignment->status ?? 'active'" />
                    </div>
                @empty
                    <p class="text-muted mb-0">No active assignments.</p>
                @endforelse
            </x-adminlte-card>
        </div>
    </div>
@stop
