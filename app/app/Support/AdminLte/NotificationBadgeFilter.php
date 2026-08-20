<?php

namespace App\Support\AdminLte;

use ColorlibHQ\AdminLte\Menu\Filters\FilterInterface;
use Illuminate\Support\Facades\Auth;

/**
 * Injects the unread-notification count into the sidebar badge.
 *
 * Runs at menu-render time (after boot, with the auth session available),
 * so it can read the authenticated user's `unreadNotifications` count. Items
 * whose route isn't one of the notification inboxes pass through unchanged.
 */
class NotificationBadgeFilter implements FilterInterface
{
    /** @var array<string, string> */
    private const NOTIFICATION_ROUTES = [
        'client.notifications.index',
        'admin.notifications.index',
    ];

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    public function transform(array $item): ?array
    {
        $route = is_array($item['route'] ?? null) ? ($item['route'][0] ?? null) : ($item['route'] ?? null);

        if (! in_array($route, self::NOTIFICATION_ROUTES, true)) {
            return $item;
        }

        $user = Auth::user();
        $unread = $user !== null ? $user->unreadNotifications()->count() : 0;

        if ($unread > 0) {
            $item['label'] = $unread;
            $item['label_color'] = 'primary';
        }

        return $item;
    }
}
