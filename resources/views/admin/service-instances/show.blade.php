@extends('adminlte::page')
@section('title', 'Service #'.$serviceInstance->id)
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">Service #{{ $serviceInstance->id }}</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item"><a href="{{ route('admin.service-instances.index') }}">Services</a></li><li class="breadcrumb-item active">#{{ $serviceInstance->id }}</li></ol></div></div>
@stop
@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif
    <div class="row">
        <div class="col-md-8">
            <x-adminlte-card icon="bi bi-info-circle" title="Details">
                <table class="table table-sm table-borderless mb-0">
                    <tbody>
                        <tr><th class="text-muted w-25">ID</th><td>#{{ $serviceInstance->id }}</td></tr>
                        <tr><th class="text-muted">Domain</th><td>{{ $serviceInstance->domain ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Username</th><td>{{ $serviceInstance->username ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Customer</th><td>{{ $serviceInstance->customer?->full_name ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Product</th><td>{{ $serviceInstance->catalogProduct?->name ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Server</th><td>{{ $serviceInstance->server?->name ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Server Group</th><td>{{ $serviceInstance->serverGroup?->name ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Provision Status</th><td><x-adminlte.partials.status-badge :status="$serviceInstance->provision_status" /></td></tr>
                        <tr><th class="text-muted">Status</th><td><x-adminlte.partials.status-badge :status="$serviceInstance->status" /></td></tr>
                        <tr><th class="text-muted">Created</th><td>{{ $serviceInstance->created_at?->format('Y-m-d H:i') }}</td></tr>
                    </tbody>
                </table>
            </x-adminlte-card>

            <x-adminlte-card icon="bi bi-clock-history" title="Provisioning Events">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Date</th><th>Type</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                            @forelse ($provisioningEvents as $event)
                                <tr>
                                    <td><a href="{{ route('admin.provisioning-events.show', $event) }}">{{ $event->created_at?->format('Y-m-d H:i') }}</a></td>
                                    <td><span class="badge text-bg-info">{{ $event->event_type }}</span></td>
                                    <td><x-adminlte.partials.status-badge :status="$event->event_status" /></td>
                                    <td class="text-end"><a href="{{ route('admin.provisioning-events.show', $event) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a></td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-3">No provisioning events.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-adminlte-card>
        </div>
        <div class="col-md-4">
            <x-adminlte-card icon="bi bi-arrow-repeat" title="Update Status">
                <form method="POST" action="{{ route('admin.service-instances.update', $serviceInstance) }}">
                    @csrf @method('PUT')
                    <x-adminlte-select name="status" label="Status">
                        @foreach (['active','suspended','cancelled','terminated','pending'] as $s)
                            <option value="{{ $s }}" @selected($serviceInstance->status === $s)>{{ ucfirst($s) }}</option>
                        @endforeach
                    </x-adminlte-select>
                    <x-adminlte-select name="provision_status" label="Provision Status">
                        @foreach (['pending','provisioning','provisioned','failed','suspended'] as $s)
                            <option value="{{ $s }}" @selected($serviceInstance->provision_status === $s)>{{ ucfirst($s) }}</option>
                        @endforeach
                    </x-adminlte-select>
                    <button type="submit" class="btn btn-primary w-100">Update</button>
                </form>
            </x-adminlte-card>
            <x-adminlte-card icon="bi bi-exclamation-triangle" title="Actions" class="mt-3">
                @if ($serviceInstance->status === 'active')
                    <button type="button" class="btn btn-warning w-100 mb-2" data-bs-toggle="modal" data-bs-target="#suspend-service-modal">
                        <i class="bi bi-pause-circle me-1"></i> Suspend
                    </button>
                @endif
                @if ($serviceInstance->status !== 'terminated')
                    <button type="button" class="btn btn-danger w-100" data-bs-toggle="modal" data-bs-target="#terminate-service-modal">
                        <i class="bi bi-x-circle me-1"></i> Terminate
                    </button>
                @endif
            </x-adminlte-card>
        </div>
    </div>

    @if ($serviceInstance->status === 'active')
        <x-adminlte.partials.confirm-modal
            id="suspend-service-modal"
            title="Suspend service"
            message="Suspend this service?"
            method="POST"
            :action="route('admin.service-instances.suspend', $serviceInstance)"
            confirm-label="Suspend service"
            confirm-theme="warning"
        />
    @endif
    @if ($serviceInstance->status !== 'terminated')
        <x-adminlte.partials.confirm-modal
            id="terminate-service-modal"
            title="Terminate service"
            message="Terminate this service? This cannot be undone."
            method="POST"
            :action="route('admin.service-instances.terminate', $serviceInstance)"
            confirm-label="Terminate service"
        />
    @endif
@stop
