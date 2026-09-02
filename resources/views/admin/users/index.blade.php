@extends('adminlte::page')

@section('title', 'Staff Users')

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Staff Users</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">Staff Users</li>
            </ol>
        </div>
    </div>
@stop

@php
    $roleBadges = [
        'admin' => 'danger',
        'staff' => 'primary',
        'support' => 'info',
        'sales' => 'success',
        'marketing' => 'warning',
        'client' => 'secondary',
    ];
@endphp

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif
    @if (session('error'))
        <x-adminlte-alert theme="danger" dismissible>{{ session('error') }}</x-adminlte-alert>
    @endif

    <x-adminlte.partials.datatable
        icon="bi bi-people"
        title="All Staff Accounts"
        :search-value="$search"
        search-placeholder="Search name or email..."
        :status-options="['active' => 'Active', 'inactive' => 'Inactive', 'suspended' => 'Suspended']"
        :status-value="$status"
        :columns="[
            ['label' => '#', 'sort' => 'id'],
            ['label' => 'Name', 'sort' => 'name'],
            ['label' => 'Email', 'sort' => 'email'],
            ['label' => 'Role', 'sort' => 'role'],
            ['label' => 'Status', 'sort' => 'status'],
            ['label' => 'Last login', 'sort' => 'last_login_at'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$users"
    >
        <x-slot name="tools">
            @can('users.create')
                <a href="{{ route('admin.users.create') }}" class="btn btn-sm btn-primary">
                    <i class="bi bi-person-plus me-1" aria-hidden="true"></i> Add Staff
                </a>
            @endcan
        </x-slot>

        @forelse ($users as $user)
            <tr>
                <td class="text-muted">{{ $user->id }}</td>
                <td>
                    <a href="{{ route('admin.users.show', $user) }}"><strong>{{ $user->full_name }}</strong></a>
                    @if ($user->id === auth()->id())
                        <span class="badge text-bg-dark ms-1">You</span>
                    @endif
                </td>
                <td class="text-muted">{{ $user->email }}</td>
                <td>
                    <span class="badge text-bg-{{ $roleBadges[$user->role] ?? 'secondary' }}">
                        {{ ucfirst($user->role) }}
                    </span>
                </td>
                <td>
                    <x-adminlte.partials.status-badge :status="$user->status" />
                </td>
                <td class="text-muted">
                    {{ $user->last_login_at?->format('M j, Y') ?? 'Never' }}
                </td>
                <td class="text-end">
                        <div class="table-actions">
                            @can('users.edit')
                            <a href="{{ route('admin.users.edit', $user) }}"
                            class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit">
                            <i class="bi bi-pencil"></i>
                            </a>
                            @endcan
                            @can('users.delete')
                            <button type="button" class="btn btn-sm btn-outline-danger btn-icon" title="Delete" aria-label="Delete"
                            data-bs-toggle="modal" data-bs-target="#delete-user-{{ $user->id }}">
                            <i class="bi bi-trash"></i>
                            </button>
                            @endcan
                        </div>
                    </td>
            </tr>
        @empty
            <tr>
                <td colspan="7" class="text-center text-muted py-4">
                    No staff accounts found.
                </td>
            </tr>
        @endforelse
    </x-adminlte.partials.datatable>

    @foreach ($users as $user)
        @can('users.delete')
            <x-adminlte.partials.confirm-modal
                :id="'delete-user-' . $user->id"
                title="Delete staff account"
                :message="'Delete ' . $user->full_name . '? This permanently removes the account and its role assignments.'"
                :action="route('admin.users.destroy', $user)"
                confirm-label="Delete account"
            />
        @endcan
    @endforeach
@stop
