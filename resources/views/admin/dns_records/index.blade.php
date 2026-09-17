@extends('adminlte::page')
@section('title', 'DNS Records — '.$dnsZone->domain)
@section('content_header')
    <x-ui.page-header title="Records: {{ $dnsZone->domain }}" subtitle="Manage DNS records inventory" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'DNS Zones', 'url' => route('admin.dns-zones.index')],
        ['label' => $dnsZone->domain, 'url' => route('admin.dns-zones.show', $dnsZone)],
        ['label' => 'Records', 'active' => true],
    ]" />
@stop
@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif

    <x-adminlte.partials.datatable
        icon="bi bi-list-nested"
        title="All Records"
        :search-value="$search"
        search-placeholder="Search name, content..."
        status-field="type"
        status-placeholder="All Types"
        :status-options="array_combine(['A','AAAA','CNAME','MX','NS','TXT','SRV','PTR','SOA','CAA'], ['A','AAAA','CNAME','MX','NS','TXT','SRV','PTR','SOA','CAA'])"
        :status-value="$type"
        :columns="[
            ['label' => 'Name', 'sort' => 'name'],
            ['label' => 'Type', 'sort' => 'type'],
            ['label' => 'TTL', 'sort' => 'ttl'],
            ['label' => 'Priority', 'sort' => 'priority'],
            ['label' => 'Content', 'sort' => 'content'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$records"
    >
        <x-slot name="tools">
            <a href="{{ route('admin.dns-zones.records.create', $dnsZone) }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Record</a>
        </x-slot>

        @forelse ($records as $record)
            <tr>
                <td><code>{{ $record->name }}</code></td>
                <td><span class="badge text-bg-secondary">{{ $record->type }}</span></td>
                <td>{{ $record->ttl }}</td>
                <td>{{ $record->priority ?? '—' }}</td>
                <td title="{{ $record->content }}">{{ $record->content }}</td>
                <td class="text-end">
                    <div class="table-actions">
                        <a href="{{ route('admin.dns-zones.records.edit', [$dnsZone, $record]) }}" class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit"><i class="bi bi-pencil"></i></a>
                        <button type="button" class="btn btn-sm btn-outline-danger btn-icon" title="Delete" aria-label="Delete"
                                data-bs-toggle="modal" data-bs-target="#delete-dns-record-{{ $record->id }}"><i class="bi bi-trash"></i></button>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-center text-muted py-4">No DNS records found.</td></tr>
        @endforelse
    </x-adminlte.partials.datatable>

    @foreach ($records as $record)
        <x-adminlte.partials.confirm-modal
            :id="'delete-dns-record-' . $record->id"
            title="Delete DNS record"
            :message="'Delete the ' . $record->type . ' record for ' . $record->name . '? This cannot be undone.'"
            :action="route('admin.dns-zones.records.destroy', [$dnsZone, $record])"
            confirm-label="Delete record"
        />
    @endforeach
@stop
