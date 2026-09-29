@extends('adminlte::page')

@section('title', 'Server Groups')

@section('content_header')
    <x-ui.page-header title="Server Groups" subtitle="Manage server groups inventory" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Server Groups', 'active' => true],
    ]" />
@stop

@section('content')
    <x-adminlte.partials.flash-alert />

    <x-adminlte.partials.datatable
        icon="bi bi-collection"
        title="All Server Groups"
        :search-value="$search"
        search-placeholder="Search group name, description..."
        :status-options="['active' => 'Active', 'inactive' => 'Inactive']"
        :status-value="$status"
        :columns="[
            ['label' => 'Name', 'sort' => 'name'],
            ['label' => 'Description', 'sort' => 'description'],
            ['label' => 'Load Balancing', 'sort' => 'load_balancing'],
            ['label' => 'Servers', 'class' => 'text-end'],
            ['label' => 'Status', 'sort' => 'status'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$groups"
    >
        <x-slot name="tools">
            @can('hosting.manage')
                <a href="{{ route('admin.server-groups.create') }}" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Add Server Group
                </a>
            @endcan
        </x-slot>

        @forelse ($groups as $group)
            <tr>
                <td><strong>{{ $group->name }}</strong></td>
                <td class="text-muted">{{ $group->description ?? '—' }}</td>
                <td>{{ ucfirst(str_replace('_', ' ', $group->load_balancing)) }}</td>
                <td class="text-end">{{ $group->servers_count }}</td>
                <td>
                    <x-adminlte.partials.status-badge :status="$group->status" />
                </td>
                <td class="text-end">
                        <div class="table-actions">
                            @can('hosting.manage')
                            <a href="{{ route('admin.server-groups.edit', $group) }}"
                            class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit">
                            <i class="bi bi-pencil"></i>
                            </a>
                            @endcan
                        </div>
                    </td>
            </tr>
        @empty
            <x-ui.empty-table-row colSpan="6" title="No server groups found." />
        @endforelse
    </x-adminlte.partials.datatable>
@stop
