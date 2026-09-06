@extends('adminlte::page')

@section('title', 'My Profile')

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">My Profile</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item active" aria-current="page">Profile</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif

    <x-adminlte-card icon="bi bi-person-circle" title="Account Details">
        <dl class="row mb-0">
            <dt class="col-sm-3">Name</dt>
            <dd class="col-sm-9">{{ $user->name ?? $user->email }}</dd>

            <dt class="col-sm-3">Email</dt>
            <dd class="col-sm-9">{{ $user->email }}</dd>

            <dt class="col-sm-3">Roles</dt>
            <dd class="col-sm-9">
                @forelse ($user->roles as $role)
                    <span class="badge text-bg-primary me-1">{{ $role->name }}</span>
                @empty
                    <span class="text-muted">No roles</span>
                @endforelse
            </dd>

            <dt class="col-sm-3">Member since</dt>
            <dd class="col-sm-9">{{ $user->created_at?->format('M j, Y') ?? '—' }}</dd>
        </dl>
    </x-adminlte-card>

    <x-adminlte-card icon="bi bi-pen" title="Ticket Signature">
        <p class="text-muted small mb-3">Appended automatically when you reply to a ticket. Leave blank to send no signature.</p>
        <form method="POST" action="{{ route('admin.profile.update') }}">
            @csrf
            <div class="mb-3">
                <label for="ticket_signature" class="form-label">Signature</label>
                <textarea name="ticket_signature" id="ticket_signature" class="form-control @error('ticket_signature') is-invalid @enderror"
                          rows="5" placeholder="e.g. Best regards,&#10;{{ $user->first_name }}&#10;Support Team" maxlength="2000">{{ old('ticket_signature', $user->ticket_signature) }}</textarea>
                @error('ticket_signature')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <div class="form-text">Plain text only. Max 2,000 characters.</div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Save signature</button>
        </form>
    </x-adminlte-card>

    {{-- Two-Factor Authentication management --}}
    @include('auth.two-factor-manage')
@stop
