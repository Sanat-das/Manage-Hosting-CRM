@extends('adminlte::page')

@section('title', 'Resource Types')

@section('content_header')
    <x-ui.page-header title="Resource Types" subtitle="Overview and management of resource types" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Resource Types','active' => true]]" />
@stop

@section('content')
    <x-adminlte.partials.flash-alert />

    <x-adminlte.partials.datatable
        icon="bi bi-cpu"
        title="All Resource Types"
        :search-value="$search"
        search-placeholder="Search name, slug, category..."
        :columns="[
            ['label' => 'Name', 'sort' => 'name'],
            ['label' => 'Slug', 'sort' => 'slug'],
            ['label' => 'Category', 'sort' => 'category'],
            ['label' => 'Unit', 'sort' => 'unit'],
            ['label' => 'Description', 'sort' => 'description'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$types"
    >
        <x-slot name="tools">
            <a href="{{ route('admin.resource-types.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Resource Type</a>
        </x-slot>

        @forelse ($types as $type)
            <tr>
                <td><a href="{{ route('admin.resource-types.show', $type) }}"><strong>{{ $type->name }}</strong></a></td>
                <td><code>{{ $type->slug }}</code></td>
                <td>{{ $type->category ?? '—' }}</td>
                <td>{{ $type->unit ?? '—' }}</td>
                <td class="text-muted">{{ $type->description ?? '—' }}</td>
                <td class="text-end">
                    <div class="table-actions">
                        <a href="{{ route('admin.resource-types.edit', $type) }}" class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit"><i class="bi bi-pencil"></i></a>
                    </div>
                </td>
            </tr>
        @empty
            <x-ui.empty-table-row colSpan="6" title="No resource types found." />
        @endforelse
    </x-adminlte.partials.datatable>
@stop

