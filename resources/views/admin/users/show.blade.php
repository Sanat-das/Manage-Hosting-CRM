@extends('adminlte::page')

@section('title', $user->full_name)

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">{{ $user->full_name }}</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.users.index') }}">Staff Users</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $user->id }}</li>
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

    <x-adminlte-card>
        <div class="d-flex flex-wrap align-items-center gap-3">
            <div class="d-flex align-items-center justify-content-center rounded-circle bg-primary text-white"
                 style="width: 56px; height: 56px; font-size: 1.25rem; flex-shrink: 0;">
                {{ strtoupper(substr($user->first_name ?? 'S', 0, 1)) }}{{ strtoupper(substr($user->last_name ?? '', 0, 1)) }}
            </div>
            <div class="flex-grow-1">
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <h4 class="mb-0">{{ $user->full_name }}</h4>
                    <span class="badge bg-{{ $roleBadges[$user->role] ?? 'secondary' }}">{{ ucfirst($user->role) }}</span>
                    <x-adminlte.partials.status-badge :status="$user->status" />
                    @if ($user->id === auth()->id())
                        <span class="badge bg-dark">You</span>
                    @endif
                </div>
                <div class="text-muted mt-1">
                    <i class="bi bi-envelope me-1"></i>{{ $user->email }}
                    @if ($user->phone)
                        <span class="mx-2">|</span><i class="bi bi-telephone me-1"></i>{{ $user->phone }}
                    @endif
                </div>
            </div>
            <div class="d-flex gap-2">
                @can('users.edit')
                    <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-pencil me-1"></i> Edit
                    </a>
                @endcan
                @can('users.edit')
                    <form method="POST" action="{{ route('admin.users.toggle-status', $user) }}" class="d-inline">
                        @csrf
                        <button class="btn btn-sm btn-outline-{{ $user->status === 'active' ? 'warning' : 'success' }}">
                            <i class="bi bi-toggle-{{ $user->status === 'active' ? 'on' : 'off' }} me-1"></i>
                            {{ $user->status === 'active' ? 'Deactivate' : 'Activate' }}
                        </button>
                    </form>
                @endcan
                @can('users.edit')
                    <form method="POST" action="{{ route('admin.users.reset-password', $user) }}" class="d-inline"
                          onsubmit="return confirm('Send password reset email to {{ $user->email }}?');">
                        @csrf
                        <button class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-key me-1"></i> Reset Password
                        </button>
                    </form>
                @endcan
            </div>
        </div>
    </x-adminlte-card>

    <div class="row">
        <div class="col-md-6">
            <x-adminlte-card title="Account Details">
                <table class="table table-sm table-borderless">
                    <tbody>
                        <tr><th class="w-25 text-muted">ID</th><td>{{ $user->id }}</td></tr>
                        <tr><th class="text-muted">Email</th><td>{{ $user->email }}</td></tr>
                        <tr><th class="text-muted">Phone</th><td>{{ $user->phone ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Role</th><td><span class="badge bg-{{ $roleBadges[$user->role] ?? 'secondary' }}">{{ ucfirst($user->role) }}</span></td></tr>
                        <tr><th class="text-muted">Status</th><td><x-adminlte.partials.status-badge :status="$user->status" /></td></tr>
                        <tr><th class="text-muted">Last login</th><td>{{ $user->last_login_at?->format('M j, Y H:i') ?? 'Never' }}</td></tr>
                        <tr><th class="text-muted">Created</th><td>{{ $user->created_at?->format('M j, Y H:i') }}</td></tr>
                    </tbody>
                </table>
            </x-adminlte-card>
        </div>
        <div class="col-md-6">
            <x-adminlte-card title="Permissions">
                @if ($user->roles->count())
                    @foreach ($user->roles as $role)
                        <div class="mb-3">
                            <strong class="badge bg-{{ $roleBadges[$role->name] ?? 'secondary' }}">{{ $role->label ?? ucfirst($role->name) }}</strong>
                            @if ($role->permissions->count())
                                <div class="mt-1">
                                    @foreach ($role->permissions as $perm)
                                        <span class="badge bg-light text-dark me-1 mb-1">{{ $perm->label ?? $perm->name }}</span>
                                    @endforeach
                                </div>
                            @else
                                <div class="text-muted small mt-1">No permissions assigned</div>
                            @endif
                        </div>
                    @endforeach
                @else
                    <p class="text-muted mb-0">No roles assigned.</p>
                @endif
            </x-adminlte-card>
        </div>
    </div>
@stop
