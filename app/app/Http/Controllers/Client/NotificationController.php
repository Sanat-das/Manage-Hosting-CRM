<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Client notification inbox.
 *
 * Reads the framework's `notifications` / `unreadNotifications` relations on
 * the authenticated User — agnostic to how rows were produced (T3.3).
 */
class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        $notifications = $user->notifications()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        // Presentational title derived from the notification FQCN, e.g.
        // App\Notifications\InvoiceOverdueNotification -> "Invoice Overdue".
        $notifications->getCollection()->each(function (DatabaseNotification $notification): void {
            $base = class_basename($notification->type);
            $base = preg_replace('/Notification$/', '', $base) ?? $base;
            $notification->title = Str::title(Str::snake($base));
        });

        return view('client.notifications.index', compact('notifications'));
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
