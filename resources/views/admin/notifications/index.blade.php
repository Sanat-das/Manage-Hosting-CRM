@extends('adminlte::page')

@section('title', 'Notifications')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Notifications</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">Notifications</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif

    <x-adminlte.partials.datatable
        icon="bi bi-bell"
        title="Your Notifications"
        status-placeholder="All notifications"
        :status-options="['unread' => 'Unread', 'read' => 'Read']"
        :status-value="$status"
        :columns="[
            ['label' => 'Notification'],
            ['label' => 'Received', 'sort' => 'created_at'],
            ['label' => 'Status', 'sort' => 'status'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$notifications"
    >
        <x-slot name="tools">
            @if ($notifications->isNotEmpty() && auth()->user()->unreadNotifications()->count() > 0)
                <form method="POST" action="{{ route('admin.notifications.markAllRead') }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-check2-all me-1"></i> Mark all read
                    </button>
                </form>
            @endif
        </x-slot>

        @forelse ($notifications as $notification)
            <tr>
                <td>
                    <strong>{{ $notification->title }}</strong>
                    @if (! empty($notification->data['message'] ?? null))
                        <div class="text-muted small">{{ $notification->data['message'] }}</div>
                    @endif
                </td>
                <td class="text-muted text-nowrap">{{ $notification->created_at?->format('M j, Y g:i A') }}</td>
                <td>
                    @if ($notification->read_at)
                        <span class="badge text-bg-secondary">Read</span>
                    @else
                        <span class="badge text-bg-primary">Unread</span>
                    @endif
                </td>
                <td class="text-end">
                    <div class="table-actions">
                        @if (! $notification->read_at)
                            <form method="POST" action="{{ route('admin.notifications.markRead', $notification) }}" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-primary btn-icon" title="Mark read" aria-label="Mark read">
                                    <i class="bi bi-check-lg"></i>
                                </button>
                            </form>
                        @endif
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="text-center text-muted py-4">No notifications.</td></tr>
        @endforelse
    </x-adminlte.partials.datatable>
@stop
