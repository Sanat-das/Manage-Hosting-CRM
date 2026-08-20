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
    <x-adminlte-card icon="bi bi-diagram-3" :title="'Hosting Tree — ' . $server->name">
        <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
            <div class="text-muted small align-self-center">
                Relationships where <code>{{ $server->name }}</code> (server #{{ $server->id }}) is the parent.
            </div>
            <a href="{{ route('admin.hosting-tree.index', ['server_id' => $server->id, 'csv' => 1]) }}"
               class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-download me-1"></i> Export CSV
            </a>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
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
                                <span class="badge bg-secondary">{{ $kinds[$relationship->child_kind] ?? $relationship->child_kind }}</span>
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
