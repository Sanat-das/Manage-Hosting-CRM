@extends('adminlte::page')

@section('title', 'License â€” '.$license->license_type)

@section('content_header')
    <x-ui.page-header title="{{ $license->license_type }}" subtitle="View license details" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Licenses','url' => route('admin.licenses.index')],['label' => $license->license_type,'active' => true]]" />
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
                        <tr><th class="text-muted">Vendor</th><td>{{ $license->vendor ?? 'â€”' }}</td></tr>
                        <tr><th class="text-muted">Key</th><td><code>{{ $license->license_key }}</code></td></tr>
                        <tr><th class="text-muted">Seats</th><td>{{ $license->seats_available ?? 'â€”' }} / {{ $license->seats ?? 'â€”' }}</td></tr>
                        <tr><th class="text-muted">Cost</th><td>{{ $license->cost ? '$' . number_format($license->cost, 2) : 'â€”' }}</td></tr>
                        <tr><th class="text-muted">Expiry</th><td>{{ $license->expiry_date?->format('Y-m-d') ?? 'â€”' }}</td></tr>
                        <tr><th class="text-muted">Renewal</th><td>{{ $license->renewal_date?->format('Y-m-d') ?? 'â€”' }}</td></tr>
                        <tr><th class="text-muted">Status</th><td><x-adminlte.partials.status-badge :status="$license->status" /></td></tr>
                        <tr><th class="text-muted">PO</th><td>{{ $license->purchase_order ?? 'â€”' }}</td></tr>
                    </tbody>
                </table>
            </x-adminlte-card>
        </div>
        <div class="col-md-4">
            <x-adminlte-card icon="bi bi-people" title="Assignments">
                @forelse ($license->assignments as $assignment)
                    <div class="d-flex justify-content-between">
                        <span>{{ $assignment->service?->domain ?? $assignment->customer?->full_name ?? 'â€”' }}</span>
                        <x-adminlte.partials.status-badge :status="$assignment->status ?? 'active'" />
                    </div>
                @empty
                    <p class="text-muted mb-0">No active assignments.</p>
                @endforelse
            </x-adminlte-card>
        </div>
    </div>
@stop

