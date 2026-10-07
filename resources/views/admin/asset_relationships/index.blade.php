@extends('adminlte::page')

@section('title', 'Asset Relationships')

@section('content_header')
    <x-ui.page-header title="Asset Relationships" subtitle="Overview and management of asset relationships" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Asset Relationships','active' => true]]" />
@stop

@section('content')
    <x-adminlte.partials.flash-alert />

    <x-adminlte.partials.datatable
        icon="bi bi-diagram-3"
        title="All Asset Relationships"
        :search-value="$search"
        search-placeholder="Search label..."
        status-field="relationship_type"
        status-placeholder="All types"
        :status-options="collect($types)->mapWithKeys(fn ($type) => [$type => ucfirst(str_replace('_', ' ', $type))])->all()"
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
            @can('asset-relationships.manage')
                <a href="{{ route('admin.asset-relationships.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Relationship</a>
            @endcan
        </x-slot>

        <x-slot name="filters">
            <div class="d-flex align-items-center gap-1">
                <label for="filter-parent-kind" class="form-label small fw-medium mb-0">Parent kind</label>
                <select id="filter-parent-kind" name="parent_kind" class="form-select form-select-sm w-auto" onchange="this.form.submit()" aria-label="Filter by parent kind">
                    <option value="">All parents</option>
                    @foreach ($kinds as $value => $label)
                        <option value="{{ $value }}" @selected($parentKind === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="d-flex align-items-center gap-1">
                <label for="filter-child-kind" class="form-label small fw-medium mb-0">Child kind</label>
                <select id="filter-child-kind" name="child_kind" class="form-select form-select-sm w-auto" onchange="this.form.submit()" aria-label="Filter by child kind">
                    <option value="">All children</option>
                    @foreach ($kinds as $value => $label)
                        <option value="{{ $value }}" @selected($childKind === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </x-slot>

        @forelse ($relationships as $relationship)
            <tr>
                <td>
                    <span class="badge text-bg-secondary">{{ $kinds[$relationship->parent_kind] ?? $relationship->parent_kind }}</span>
                    @php($parentRef = $assetReferences[$relationship->id]['parent'] ?? null)
                    @if ($parentRef && $parentRef['name'] !== null)
                        <a class="ms-1" href="{{ $parentRef['url'] }}">{{ $parentRef['name'] }}</a>
                    @else
                        <code class="ms-1">#{{ $relationship->parent_id }}</code>
                    @endif
                </td>
                <td><code>{{ $relationship->relationship_type }}</code></td>
                <td>
                    <span class="badge text-bg-secondary">{{ $kinds[$relationship->child_kind] ?? $relationship->child_kind }}</span>
                    @php($childRef = $assetReferences[$relationship->id]['child'] ?? null)
                    @if ($childRef && $childRef['name'] !== null)
                        <a class="ms-1" href="{{ $childRef['url'] }}">{{ $childRef['name'] }}</a>
                    @else
                        <code class="ms-1">#{{ $relationship->child_id }}</code>
                    @endif
                </td>
                <td class="text-muted">{{ $relationship->label ?? '—' }}</td>
                <td class="text-end">{{ $relationship->sort_order }}</td>
                <td class="text-end">
                    <div class="table-actions">
                        @can('asset-relationships.manage')
                            <a href="{{ route('admin.asset-relationships.edit', $relationship) }}" class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit"><i class="bi bi-pencil"></i></a>
                            <button type="button" class="btn btn-sm btn-outline-danger btn-icon" title="Delete" aria-label="Delete"
                                    data-bs-toggle="modal" data-bs-target="#delete-relationship-{{ $relationship->id }}"><i class="bi bi-trash"></i></button>
                        @endcan
                    </div>
                </td>
            </tr>
        @empty
            <x-ui.empty-table-row colSpan="6" title="No asset relationships found." />
        @endforelse
    </x-adminlte.partials.datatable>

    @foreach ($relationships as $relationship)
        @can('asset-relationships.manage')
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

