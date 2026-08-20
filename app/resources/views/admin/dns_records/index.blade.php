@extends('adminlte::page')
@section('title', 'DNS Records — '.$dnsZone->domain)
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">Records: {{ $dnsZone->domain }}</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item"><a href="{{ route('admin.dns-zones.index') }}">DNS Zones</a></li><li class="breadcrumb-item"><a href="{{ route('admin.dns-zones.show', $dnsZone) }}">{{ $dnsZone->domain }}</a></li><li class="breadcrumb-item active">Records</li></ol></div></div>
@stop
@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif
    <x-adminlte-card icon="bi bi-list-nested" title="All Records">
        <div class="row mb-3">
            <div class="col-md-6">
                <form method="GET" action="{{ route('admin.dns-zones.records.index', $dnsZone) }}" class="d-flex gap-2">
                    <x-adminlte-select name="type" label="Type">
                        <option value="">All Types</option>
                        @foreach (['A','AAAA','CNAME','MX','NS','TXT','SRV','PTR','SOA','CAA'] as $t)
                            <option value="{{ $t }}" @selected(request('type') === $t)>{{ $t }}</option>
                        @endforeach
                    </x-adminlte-select>
                    <button type="submit" class="btn btn-sm btn-outline-primary mt-4">Filter</button>
                </form>
            </div>
            <div class="col-md-6 text-end">
                <a href="{{ route('admin.dns-zones.records.create', $dnsZone) }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Record</a>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Name</th><th>Type</th><th>TTL</th><th>Priority</th><th>Content</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                    @forelse ($records as $record)
                        <tr>
                            <td><code>{{ $record->name }}</code></td>
                            <td><span class="badge bg-secondary">{{ $record->type }}</span></td>
                            <td>{{ $record->ttl }}</td>
                            <td>{{ $record->priority ?? '—' }}</td>
                            <td class="text-truncate" style="max-width:300px">{{ $record->content }}</td>
                            <td class="text-end">
                                <a href="{{ route('admin.dns-zones.records.edit', [$dnsZone, $record]) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                                <form method="POST" action="{{ route('admin.dns-zones.records.destroy', [$dnsZone, $record]) }}" class="d-inline" onsubmit="return confirm('Delete this record?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">No DNS records found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $records->links() }}
    </x-adminlte-card>
@stop
