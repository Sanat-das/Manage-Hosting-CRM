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

    <x-adminlte.partials.datatable
        icon="bi bi-diagram-3"
        title="All Asset Relationships"
        :search-value="$search"
        search-placeholder="Search label..."
        status-field="relationship_type"
        status-placeholder="All types"
        :status-options="collect($types)->mapWithKeys(fn ($type) => [$type => $type])->all()"
        :status-value="$relationshipType"
        :columns="[
            ['label' => 'Parent', 'sort' => 'parent'],
            ['label' => 'Relationship', 'sort' => 'relationship_type'],
            ['label' => 'Child', 'sort' => 'child'],
            ['label' => 'Label', 'sort' => 'label'],
            ['label' => 'Sort', 'sort' => 'sort_order', 'class' => 'text-end'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$relationships"
    >
        <x-slot name="tools">
            <form method="GET" action="{{ url()->current() }}" class="d-inline-flex align-items-center gap-2 me-2">
                <input type="hidden" name="search" value="{{ $search }}">
                @if(request('relationship_type'))<input type="hidden" name="relationship_type" value="{{ request('relationship_type') }}">@endif
                @if(request('sort'))<input type="hidden" name="sort" value="{{ request('sort') }}">@endif
                @if(request('direction'))<input type="hidden" name="direction" value="{{ request('direction') }}">@endif
                <select name="parent_kind" class="form-select form-select-sm w-auto" onchange="this.form.submit()" aria-label="Filter by parent kind">
                    <option value="">All parents</option>
                    @foreach ($kinds as $value => $label)
                        <option value="{{ $value }}" @selected($parentKind === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <select name="child_kind" class="form-select form-select-sm w-auto" onchange="this.form.submit()" aria-label="Filter by child kind">
                    <option value="">All children</option>
                    @foreach ($kinds as $value => $label)
                        <option value="{{ $value }}" @selected($childKind === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </form>
            @can('hosting.manage')
                <a href="{{ route('admin.asset-relationships.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Relationship</a>
            @endcan
        </x-slot>

        @forelse ($relationships as $relationship)
            <tr>
                <td>
                    <span class="badge text-bg-secondary">{{ $kinds[$relationship->parent_kind] ?? $relationship->parent_kind }}</span>
                    <code>#{{ $relationship->parent_id }}</code>
                </td>
                <td><code>{{ $relationship->relationship_type }}</code></td>
                <td>
                    <span class="badge text-bg-secondary">{{ $kinds[$relationship->child_kind] ?? $relationship->child_kind }}</span>
                    <code>#{{ $relationship->child_id }}</code>
                </td>
                <td class="text-muted">{{ $relationship->label ?? '—' }}</td>
                <td class="text-end">{{ $relationship->sort_order }}</td>
                <td class="text-end">
                    <div class="table-actions">
                        @can('hosting.manage')
                            <a href="{{ route('admin.asset-relationships.edit', $relationship) }}" class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit"><i class="bi bi-pencil"></i></a>
                            <button type="button" class="btn btn-sm btn-outline-danger btn-icon" title="Delete" aria-label="Delete"
                                    data-bs-toggle="modal" data-bs-target="#delete-relationship-{{ $relationship->id }}"><i class="bi bi-trash"></i></button>
                        @endcan
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-center text-muted py-4">No asset relationships found.</td></tr>
        @endforelse
    </x-adminlte.partials.datatable>

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
