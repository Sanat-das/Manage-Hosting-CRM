@extends('adminlte::page')

@section('title', 'Tags')

@section('content_header')
    <x-ui.page-header title="Tags" subtitle="Catalogue of inventory tags" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Tags', 'active' => true],
    ]" />
@stop

@section('content')
    @php($canManage = auth()->user()?->can('devices.manage') ?? false)

    <x-adminlte.partials.flash-alert />

    <x-adminlte.partials.datatable
        icon="bi bi-tags"
        title="All Tags"
        :search-value="$search"
        search-placeholder="Search tag, slug, description..."
        :columns="[
            ['label' => 'Tag', 'sort' => 'name'],
            ['label' => 'Slug', 'sort' => 'slug'],
            ['label' => 'Assets', 'class' => 'text-end'],
            ['label' => 'Description'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$tags"
    >
        <x-slot name="tools">
            @if ($canManage)
                <a href="{{ route('admin.tags.create') }}" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Add Tag
                </a>
            @endif
        </x-slot>

        @forelse ($tags as $tag)
            <tr>
                <td><span class="badge text-white" style="background-color: {{ $tag->color }};">{{ $tag->name }}</span></td>
                <td class="text-muted"><code>{{ $tag->slug }}</code></td>
                <td class="text-end">{{ $tag->assets_count }}</td>
                <td class="text-muted">{{ $tag->description ?? '—' }}</td>
                <td class="text-end">
                    @if ($canManage)
                        <div class="table-actions">
                            <a href="{{ route('admin.tags.edit', $tag) }}" class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit"><i class="bi bi-pencil"></i></a>
                            <button type="button" class="btn btn-sm btn-outline-danger btn-icon" title="Delete" aria-label="Delete"
                                    data-bs-toggle="modal" data-bs-target="#delete-tag-{{ $tag->id }}"><i class="bi bi-trash"></i></button>
                        </div>
                    @endif
                </td>
            </tr>
        @empty
            <x-ui.empty-table-row :col-span="5" title="No tags found." />
        @endforelse
    </x-adminlte.partials.datatable>

    @if ($canManage)
        @foreach ($tags as $tag)
            <x-adminlte.partials.confirm-modal
                :id="'delete-tag-' . $tag->id"
                title="Delete tag"
                :message="'Delete ' . $tag->name . '? Its asset assignments will be removed.'"
                :action="route('admin.tags.destroy', $tag)"
                confirm-label="Delete tag"
            />
        @endforeach
    @endif
@stop
