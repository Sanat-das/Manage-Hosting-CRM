@extends('adminlte::page')
@section('title', 'Provisioning Event #'.$provisioningEvent->id)
@section('content_header')
    <x-ui.page-header title="Provisioning Event #{{ $provisioningEvent->id }}" subtitle="View provisioning event details" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Provisioning Events','url' => route('admin.provisioning-events.index')],['label' => '#' . $provisioningEvent->id,'active' => true]]" />
@stop
@section('content')
    <x-adminlte-card icon="bi bi-info-circle" title="Details">
        <table class="table table-sm table-borderless mb-0">
            <tbody>
                <tr><th class="text-muted w-25">ID</th><td>#{{ $provisioningEvent->id }}</td></tr>
                <tr><th class="text-muted">Service</th><td>
                    @if ($provisioningEvent->service_instance_id)
                        <a href="{{ route('admin.service-instances.show', $provisioningEvent->service_instance_id) }}">#{{ $provisioningEvent->service_instance_id }}</a>
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </td></tr>
                <tr><th class="text-muted">Type</th><td><span class="badge text-bg-info">{{ $provisioningEvent->event_type }}</span></td></tr>
                <tr><th class="text-muted">Status</th><td><x-adminlte.partials.status-badge :status="$provisioningEvent->event_status" /></td></tr>
                <tr><th class="text-muted">Triggered By</th><td>{{ $provisioningEvent->triggered_by ?? 'System' }}</td></tr>
                <tr><th class="text-muted">Created</th><td>{{ $provisioningEvent->created_at?->format('Y-m-d H:i:s') }}</td></tr>
                @if (! empty($provisioningEvent->last_error))
                    <tr><th class="text-muted">Error</th><td>{{ $provisioningEvent->last_error }}</td></tr>
                @endif
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

