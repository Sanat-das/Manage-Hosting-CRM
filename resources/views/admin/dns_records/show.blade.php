@extends('adminlte::page')
@section('title', 'DNS Record — '.$dnsRecord->name)
@section('content_header')
    <x-ui.page-header title="{{ $dnsRecord->name }} ({{ $dnsRecord->type }})" subtitle="View DNS record details" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Records', 'url' => route('admin.dns-zones.records.index', $dnsZone)],
        ['label' => $dnsRecord->name, 'active' => true],
    ]" />
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
