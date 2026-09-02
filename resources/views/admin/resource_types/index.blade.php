@extends('adminlte::page')

@section('title', 'Resource Types')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Resource Types</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">Resource Types</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif

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
            <tr><td colspan="6" class="text-center text-muted py-4">No resource types found.</td></tr>
        @endforelse
    </x-adminlte.partials.datatable>
@stop
