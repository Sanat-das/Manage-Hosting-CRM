@extends('adminlte::page')

@section('title', 'Notifications')

@section('content_header')
    <x-ui.page-header title="Notifications" subtitle="Overview and management of notifications" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Notifications','active' => true]]" />
@stop

@section('content')
    <x-adminlte.partials.flash-alert />

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
                    <x-adminlte.partials.status-badge :status="$notification->read_at ? 'read' : 'unread'" :map="['read' => 'secondary', 'unread' => 'primary']" />
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
            <x-ui.empty-table-row colSpan="4" title="No notifications." />
        @endforelse
    </x-adminlte.partials.datatable>
@stop

