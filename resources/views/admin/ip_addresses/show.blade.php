@extends('adminlte::page')

@section('title', 'IP Address — '.$ipAddress->ip_address)

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">{{ $ipAddress->ip_address }}</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.ip-addresses.index') }}">IP Addresses</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $ipAddress->ip_address }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif

    <div class="d-flex justify-content-end mb-3">
        <a href="{{ route('admin.ip-addresses.edit', $ipAddress) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i> Edit</a>
    </div>

    <x-adminlte-card icon="bi bi-info-circle" title="Details">
        <table class="table table-sm table-borderless mb-0">
            <tbody>
                <tr><th class="text-muted w-25">IP Address</th><td>{{ $ipAddress->ip_address }}</td></tr>
                <tr><th class="text-muted">Version</th><td>IPv{{ $ipAddress->ip_version }}</td></tr>
                <tr><th class="text-muted">Type</th><td>{{ ucfirst($ipAddress->type) }}</td></tr>
                <tr><th class="text-muted">Subnet</th><td>{{ $ipAddress->subnet?->cidr ?? '—' }}</td></tr>
                <tr><th class="text-muted">PTR Record</th><td>{{ $ipAddress->ptr_record ?? '—' }}</td></tr>
                <tr><th class="text-muted">Notes</th><td>{{ $ipAddress->notes ?? '—' }}</td></tr>
            </tbody>
        </table>
    </x-adminlte-card>
@stop
