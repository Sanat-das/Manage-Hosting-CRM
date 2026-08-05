@extends('adminlte::page')

@section('title', 'Usage Record #'.$usageRecord->id)

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Usage Record #{{ $usageRecord->id }}</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.usage-records.index') }}">Usage Records</a></li>
                <li class="breadcrumb-item active" aria-current="page">#{{ $usageRecord->id }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    <x-adminlte-card icon="bi bi-info-circle" title="Details">
        <table class="table table-sm table-borderless mb-0">
            <tbody>
                <tr><th class="text-muted w-25">ID</th><td>#{{ $usageRecord->id }}</td></tr>
                <tr><th class="text-muted">Service</th><td>{{ $usageRecord->service?->domain ?? $usageRecord->service?->username ?? '—' }}</td></tr>
                <tr><th class="text-muted">Resource Type</th><td>{{ $usageRecord->resourceType?->name ?? '—' }}</td></tr>
                <tr><th class="text-muted">Quantity</th><td>{{ number_format($usageRecord->quantity, 2) }} {{ $usageRecord->resourceType?->unit ?? '' }}</td></tr>
                <tr><th class="text-muted">Unit Cost</th><td>${{ number_format($usageRecord->unit_cost ?? 0, 4) }}</td></tr>
                <tr><th class="text-muted">Total Cost</th><td>${{ number_format($usageRecord->total_cost ?? 0, 2) }}</td></tr>
                <tr><th class="text-muted">Recorded At</th><td>{{ $usageRecord->recorded_at?->format('Y-m-d H:i') ?? '—' }}</td></tr>
            </tbody>
        </table>
    </x-adminlte-card>
@stop
