@extends('adminlte::page')

@section('title', 'Customer Groups')

@section('content_header')
    <x-ui.page-header title="Customer Groups" subtitle="Browse and manage customer groups and hierarchies" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')], ['label' => 'Customer Groups', 'active' => true]]" />
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif

    <x-adminlte.partials.datatable icon="bi bi-folder" title="Customer Groups"
        :columns="[
            ['label' => 'Name', 'sort' => 'name'],
            ['label' => 'Description', 'sort' => 'description'],
            ['label' => 'Parent', 'sort' => 'parent'],
            ['label' => 'Products', 'class' => 'text-end'],
            ['label' => 'Status', 'sort' => 'status'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$groups">
        <x-slot name="tools">
            <a href="{{ route('admin.customer-groups.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> New Group</a>
        </x-slot>
        @forelse ($groups as $group)
            <tr>
                <td><a href="{{ route('admin.customer-groups.show', $group) }}"><strong>{{ $group->name }}</strong></a></td>
                <td class="text-muted">{{ $group->description ?? '—' }}</td>
                <td>{{ $group->parent?->name ?? '—' }}</td>
                <td>{{ $group->products_count }}</td>
                <td>
                    @if ($group->status === 'active')
                        <span class="badge text-bg-success">Active</span>
                    @else
                        <span class="badge text-bg-secondary">Inactive</span>
                    @endif
                </td>
                <td class="text-end">
<div class="table-actions">
                    <a href="{{ route('admin.customer-groups.edit', $group) }}" class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit"><i class="bi bi-pencil"></i></a>                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-center text-muted py-4">No customer groups.</td></tr>
        @endforelse
        <x-slot name="pagination">{{ $groups->links() }}</x-slot>
    </x-adminlte.partials.datatable>
@stop
