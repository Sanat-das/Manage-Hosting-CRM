@extends('adminlte::page')

@section('title', 'Server Hosting Tree')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Server Hosting Tree</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">Server Hosting Tree</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    <x-adminlte-card icon="bi bi-diagram-3" :title="'Hosting Tree — ' . $server->name" bodyClass="p-0">
        <x-slot name="tools">
            <a href="{{ route('admin.hosting-tree.index', ['server_id' => $server->id, 'csv' => 1]) }}"
               class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-download me-1"></i> Export CSV
            </a>
        </x-slot>
        <div class="p-3 border-bottom text-muted small">
            Relationships where <code>{{ $server->name }}</code> (server #{{ $server->id }}) is the parent.
        </div>
        <div class="table-responsive">
            <table class="table table-grid table-striped align-middle m-0"
                   data-grid-resizable
                   data-grid-key="admin.server-hosting-tree.index">
                <thead>
                    <tr>
                        <th>Child Kind</th>
                        <th>Child Name</th>
                        <th>Relationship Type</th>
                        <th>Label</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($relationships as $relationship)
                        <tr>
                            <td>
                                <span class="badge text-bg-secondary">{{ $kinds[$relationship->child_kind] ?? $relationship->child_kind }}</span>
                            </td>
                            <td>
                                {{ $childNames[$relationship->id] ?? '—' }}
                                <code class="ms-1">#{{ $relationship->child_id }}</code>
                            </td>
                            <td><code>{{ $relationship->relationship_type }}</code></td>
                            <td class="text-muted">{{ $relationship->label ?? '—' }}</td>
                            <td class="text-muted">{{ $relationship->notes ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">
                                No hosting tree relationships found for this server.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-adminlte-card>
@stop
