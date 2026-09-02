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
                    <span class="badge text-bg-{{ $roleBadges[$user->role] ?? 'secondary' }}">{{ ucfirst($user->role) }}</span>
                    <x-adminlte.partials.status-badge :status="$user->status" />
                    @if ($user->id === auth()->id())
                        <span class="badge text-bg-dark">You</span>
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
                    <button type="button" class="btn btn-sm btn-outline-secondary"
                            data-bs-toggle="modal" data-bs-target="#reset-password-modal">
                        <i class="bi bi-envelope me-1"></i> Send Reset Email
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-warning"
                            data-bs-toggle="modal" data-bs-target="#set-password-modal">
                        <i class="bi bi-key me-1"></i> Set Password
                    </button>
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
                        <tr><th class="text-muted">Role</th><td><span class="badge text-bg-{{ $roleBadges[$user->role] ?? 'secondary' }}">{{ ucfirst($user->role) }}</span></td></tr>
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
                            <strong class="badge text-bg-{{ $roleBadges[$role->name] ?? 'secondary' }}">{{ $role->label ?? ucfirst($role->name) }}</strong>
                            @if ($role->permissions->count())
                                <div class="mt-1">
                                    @foreach ($role->permissions as $perm)
                                        <span class="badge text-bg-light me-1 mb-1">{{ $perm->label ?? $perm->name }}</span>
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

    @can('users.edit')
        <x-adminlte.partials.confirm-modal
            id="reset-password-modal"
            title="Send password reset email"
            :message="'Send a password reset link to ' . $user->email . '? They will receive an email with a link to set a new password.'"
            method="POST"
            :action="route('admin.users.reset-password', $user)"
            confirm-label="Send reset email"
            confirm-theme="primary"
        />

        <div class="modal fade" id="set-password-modal" tabindex="-1" aria-labelledby="set-password-modal-label" aria-hidden="true">
            <div class="modal-dialog">
                <form method="POST" action="{{ route('admin.users.set-password', $user) }}">
                    @csrf
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="set-password-modal-label">Set Password — {{ $user->full_name }}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            @error('new_password')
                                <div class="alert alert-danger py-2">{{ $message }}</div>
                            @enderror
                            <div class="mb-3">
                                <label for="new_password" class="form-label">New Password</label>
                                <input type="password" class="form-control" id="new_password" name="new_password" autocomplete="new-password" required>
                                <div class="form-text">Min 8 characters, must include uppercase, lowercase, and a number.</div>
                            </div>
                            <div class="mb-3">
                                <label for="new_password_confirmation" class="form-label">Confirm Password</label>
                                <input type="password" class="form-control" id="new_password_confirmation" name="new_password_confirmation" autocomplete="new-password" required>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-warning">Set Password</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    @endcan

    @if ($errors->has('new_password'))
        @push('js')
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    new bootstrap.Modal(document.getElementById('set-password-modal')).show();
                });
            </script>
        @endpush
    @endif
@stop
