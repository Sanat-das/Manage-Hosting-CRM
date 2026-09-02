<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Admin notification inbox.
 *
 * Same shape as the client controller: reads the authenticated User's
 * database notifications. Lightweight — no preference/policy coupling.
 */
class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $user = auth()->user();
        $status = trim((string) $request->query('status'));

        $notifications = $user->notifications()
            ->when($status === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->when($status === 'read', fn ($q) => $q->whereNotNull('read_at'))
            ->gridSort([
                'created_at' => 'created_at',
                'status' => 'read_at',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $notifications->getCollection()->each(function (DatabaseNotification $notification): void {
            $base = class_basename($notification->type);
            $base = preg_replace('/Notification$/', '', $base) ?? $base;
            $notification->title = Str::title(Str::snake($base));
        });

        return view('admin.notifications.index', compact('notifications', 'status'));
    }

    public function markRead(DatabaseNotification $notification): RedirectResponse
    {
        abort_if((int) $notification->notifiable_id !== auth()->id(), 403);

        $notification->markAsRead();

        return back()->with('success', 'Notification marked as read.');
    }

    public function markAllRead(): RedirectResponse
    {
        auth()->user()->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('success', 'All notifications marked as read.');
    }
}
