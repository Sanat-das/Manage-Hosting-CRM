@extends('adminlte::page')

@section('title', 'Asset Relationships')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Asset Relationships</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">Asset Relationships</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif

    <x-adminlte-card icon="bi bi-diagram-3" title="All Asset Relationships">
        <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
            <form method="GET" action="{{ route('admin.asset-relationships.index') }}" class="d-flex flex-wrap align-items-center gap-2">
                <input type="text" name="search" value="{{ $search }}" class="form-control form-control-sm"
                       placeholder="Search label..." aria-label="Search by label">
                <select name="parent_kind" class="form-select form-select-sm" aria-label="Filter by parent kind">
                    <option value="">All parents</option>
                    @foreach ($kinds as $value => $label)
                        <option value="{{ $value }}" @selected($parentKind === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <select name="child_kind" class="form-select form-select-sm" aria-label="Filter by child kind">
                    <option value="">All children</option>
                    @foreach ($kinds as $value => $label)
                        <option value="{{ $value }}" @selected($childKind === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <select name="relationship_type" class="form-select form-select-sm" aria-label="Filter by relationship type">
                    <option value="">All types</option>
                    @foreach ($types as $type)
                        <option value="{{ $type }}" @selected($relationshipType === $type)>{{ $type }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-funnel me-1"></i> Filter</button>
            </form>
            @can('hosting.manage')
                <a href="{{ route('admin.asset-relationships.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Relationship</a>
            @endcan
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Parent</th><th>Relationship</th><th>Child</th><th>Label</th><th class="text-end">Sort</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                    @forelse ($relationships as $relationship)
                        <tr>
                            <td>
                                <span class="badge bg-secondary">{{ $kinds[$relationship->parent_kind] ?? $relationship->parent_kind }}</span>
                                <code>#{{ $relationship->parent_id }}</code>
                            </td>
                            <td><code>{{ $relationship->relationship_type }}</code></td>
                            <td>
                                <span class="badge bg-secondary">{{ $kinds[$relationship->child_kind] ?? $relationship->child_kind }}</span>
                                <code>#{{ $relationship->child_id }}</code>
                            </td>
                            <td class="text-muted">{{ $relationship->label ?? '—' }}</td>
                            <td class="text-end">{{ $relationship->sort_order }}</td>
                            <td class="text-end">
                                @can('hosting.manage')
                                    <a href="{{ route('admin.asset-relationships.edit', $relationship) }}" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
                                    <button type="button" class="btn btn-sm btn-outline-danger" title="Delete"
                                            data-bs-toggle="modal" data-bs-target="#delete-relationship-{{ $relationship->id }}">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">No asset relationships found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $relationships->links() }}
    </x-adminlte-card>

    @foreach ($relationships as $relationship)
        @can('hosting.manage')
            <x-adminlte.partials.confirm-modal
                :id="'delete-relationship-' . $relationship->id"
                title="Delete asset relationship"
                :message="'Delete this ' . $relationship->relationship_type . ' link? This cannot be undone.'"
                :action="route('admin.asset-relationships.destroy', $relationship)"
                confirm-label="Delete relationship"
            />
        @endcan
    @endforeach
@stop
