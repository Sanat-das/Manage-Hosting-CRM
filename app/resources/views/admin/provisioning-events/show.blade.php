@extends('adminlte::page')
@section('title', 'Provisioning Event #'.$provisioningEvent->id)
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">Provisioning Event #{{ $provisioningEvent->id }}</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item"><a href="{{ route('admin.provisioning-events.index') }}">Events</a></li><li class="breadcrumb-item active">#{{ $provisioningEvent->id }}</li></ol></div></div>
@stop
@section('content')
    <x-adminlte-card icon="bi bi-info-circle" title="Details">
        <table class="table table-sm table-borderless mb-0">
            <tbody>
                <tr><th class="text-muted w-25">ID</th><td>#{{ $provisioningEvent->id }}</td></tr>
                <tr><th class="text-muted">Service</th><td><a href="{{ route('admin.service-instances.show', $provisioningEvent->service_instance_id) }}">#{{ $provisioningEvent->service_instance_id }}</a></td></tr>
                <tr><th class="text-muted">Type</th><td><span class="badge bg-info">{{ $provisioningEvent->event_type }}</span></td></tr>
                <tr><th class="text-muted">Status</th><td><x-adminlte.partials.status-badge :status="$provisioningEvent->event_status" /></td></tr>
                <tr><th class="text-muted">Triggered By</th><td>{{ $provisioningEvent->triggered_by ?? 'System' }}</td></tr>
                <tr><th class="text-muted">Created</th><td>{{ $provisioningEvent->created_at?->format('Y-m-d H:i:s') }}</td></tr>
            </tbody>
        </table>
    </x-adminlte-card>
    <div class="row">
        <div class="col-md-6">
            <x-adminlte-card icon="bi bi-arrow-right-square" title="Payload">
                <pre class="mb-0" style="max-height:300px;overflow:auto">{{ json_encode($provisioningEvent->payload, JSON_PRETTY_PRINT) }}</pre>
            </x-adminlte-card>
        </div>
        <div class="col-md-6">
            <x-adminlte-card icon="bi bi-arrow-left-square" title="Result">
                <pre class="mb-0" style="max-height:300px;overflow:auto">{{ json_encode($provisioningEvent->result, JSON_PRETTY_PRINT) }}</pre>
            </x-adminlte-card>
        </div>
    </div>
@stop
