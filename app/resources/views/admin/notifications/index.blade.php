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

    <x-adminlte-card icon="bi bi-bell" title="Your Notifications">
        @if ($notifications->isNotEmpty() && auth()->user()->unreadNotifications()->count() > 0)
            <div class="text-end mb-3">
                <form method="POST" action="{{ route('admin.notifications.markAllRead') }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-check2-all me-1"></i> Mark all read
                    </button>
                </form>
            </div>
        @endif

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Notification</th>
                        <th>Received</th>
                        <th>Status</th>
                        <th class="text-end"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($notifications as $notification)
                        <tr>
                            <td>
                                <strong>{{ $notification->title }}</strong>
                                @if (! empty($notification->data['message'] ?? null))
                                    <div class="text-muted small">{{ $notification->data['message'] }}</div>
                                @endif
                            </td>
                            <td class="text-muted" style="white-space: nowrap;">
                                {{ $notification->created_at?->format('M j, Y g:i A') }}
                            </td>
                            <td>
                                @if ($notification->read_at)
                                    <span class="badge bg-secondary">Read</span>
                                @else
                                    <span class="badge bg-primary">Unread</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @if (! $notification->read_at)
                                    <form method="POST" action="{{ route('admin.notifications.markRead', $notification) }}" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-check-lg me-1"></i> Mark read
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted py-4">No notifications.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $notifications->links() }}
    </x-adminlte-card>
@stop
