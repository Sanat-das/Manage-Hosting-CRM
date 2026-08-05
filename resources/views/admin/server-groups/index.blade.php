@extends('adminlte::page')

@section('title', 'Server Groups')

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Server Groups</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">Server Groups</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif
    @if (session('error'))
        <x-adminlte-alert theme="danger" dismissible>{{ session('error') }}</x-adminlte-alert>
    @endif

    <x-adminlte.partials.datatable
        icon="bi bi-collection"
        title="All Server Groups"
        :search-value="$search"
        search-placeholder="Search group name, description..."
        :status-options="['active' => 'Active', 'inactive' => 'Inactive']"
        :status-value="$status"
        :columns="[
            ['label' => 'Name'],
            ['label' => 'Description'],
            ['label' => 'Load Balancing'],
            ['label' => 'Servers', 'class' => 'text-end'],
            ['label' => 'Status'],
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
                    @can('hosting.manage')
                        <a href="{{ route('admin.server-groups.edit', $group) }}"
                           class="btn btn-sm btn-outline-secondary" title="Edit">
                            <i class="bi bi-pencil"></i>
                        </a>
                    @endcan
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="6" class="text-center text-muted py-4">
                    No server groups found.
                </td>
            </tr>
        @endforelse
    </x-adminlte.partials.datatable>
@stop
