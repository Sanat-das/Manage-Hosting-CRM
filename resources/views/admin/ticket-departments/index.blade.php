@extends('adminlte::page')

@section('title', 'Support Departments')

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Support Departments</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">Support Departments</li>
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
        icon="bi bi-diagram-2"
        title="All Departments"
        :search-value="$search"
        search-placeholder="Search name, key, email address..."
        :status-options="['active' => 'Active', 'inactive' => 'Inactive']"
        :status-value="$status"
        :columns="[
            ['label' => 'Name', 'sort' => 'name'],
            ['label' => 'Key', 'sort' => 'slug'],
            ['label' => 'Email Address', 'sort' => 'email_address'],
            ['label' => 'Incoming Mailbox'],
            ['label' => 'Tickets', 'class' => 'text-end'],
            ['label' => 'Status', 'sort' => 'status'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$departments"
    >
        <x-slot name="tools">
            @can('settings.manage')
                <a href="{{ route('admin.ticket-departments.create') }}" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Add Department
                </a>
            @endcan
        </x-slot>

        @forelse ($departments as $department)
            <tr>
                <td><strong>{{ $department->name }}</strong></td>
                <td><code>{{ $department->slug }}</code></td>
                <td class="text-muted">{{ $department->email_address ?: 'Not set' }}</td>
                <td>
                    @if ($department->hasMailbox())
                        <span class="text-muted">{{ $department->imap_host }}:{{ $department->imap_port }}</span>
                    @else
                        <span class="text-muted">Uses global settings</span>
                    @endif
                </td>
                <td class="text-end">{{ $department->tickets_count }}</td>
                <td>
                    <x-adminlte.partials.status-badge :status="$department->enabled ? 'active' : 'inactive'" />
                </td>
                <td class="text-end">
                    <div class="table-actions">
                        @can('settings.manage')
                            <a href="{{ route('admin.ticket-departments.edit', $department) }}"
                               class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form method="POST" action="{{ route('admin.ticket-departments.destroy', $department) }}"
                                  class="d-inline"
                                  onsubmit="return confirm('Delete {{ $department->name }}? Departments that still have tickets cannot be deleted.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger btn-icon" title="Delete" aria-label="Delete">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        @endcan
                    </div>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="7" class="text-center text-muted py-4">
                    No departments found.
                </td>
            </tr>
        @endforelse
    </x-adminlte.partials.datatable>

    <p class="text-muted small mt-2 mb-0">
        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
        Give each department its own real mailbox — not an alias, and never one shared with another
        department, or every customer reply is imported twice. Departments without their own mailbox
        fall back to <a href="{{ route('admin.settings.index', ['tab' => 'email']) }}">Settings &rsaquo; Email &rsaquo; Incoming Mail</a>.
    </p>
@stop
