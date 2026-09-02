@extends('adminlte::page')
@section('title', 'DNS Record — '.$dnsRecord->name)
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">{{ $dnsRecord->name }} ({{ $dnsRecord->type }})</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item"><a href="{{ route('admin.dns-zones.records.index', $dnsZone) }}">Records</a></li><li class="breadcrumb-item active">{{ $dnsRecord->name }}</li></ol></div></div>
@stop
@section('content')
    <x-adminlte-card icon="bi bi-info-circle" title="Record Details">
        <table class="table table-sm table-borderless mb-0">
            <tbody>
                <tr><th class="text-muted w-25">Name</th><td><code>{{ $dnsRecord->name }}</code></td></tr>
                <tr><th class="text-muted">Type</th><td><span class="badge text-bg-secondary">{{ $dnsRecord->type }}</span></td></tr>
                <tr><th class="text-muted">TTL</th><td>{{ $dnsRecord->ttl }}s</td></tr>
                <tr><th class="text-muted">Priority</th><td>{{ $dnsRecord->priority ?? '—' }}</td></tr>
                <tr><th class="text-muted">Content</th><td>{{ $dnsRecord->content }}</td></tr>
            </tbody>
        </table>
    </x-adminlte-card>
@stop
