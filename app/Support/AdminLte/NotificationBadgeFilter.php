<?php

namespace App\Support\AdminLte;

use App\Models\ProvisioningEvent;
use App\Models\Ticket;
use App\Models\UpgradeRequest;
use ColorlibHQ\AdminLte\Menu\Filters\FilterInterface;
use Illuminate\Support\Facades\Auth;

/**
 * Injects badge counts into the sidebar for notification inboxes and the
 * admin queues that need attention.
 *
 * Runs at menu-render time (after boot, with the auth session available),
 * so it can read the authenticated user's `unreadNotifications` count. The
 * admin items count rows by their authoritative status column. Items whose
 * route is neither kind pass through unchanged.
 */
class NotificationBadgeFilter implements FilterInterface
{
    /** @var array<string, string> */
    private const NOTIFICATION_ROUTES = [
        'client.notifications.index',
        'admin.notifications.index',
    ];

    /**
     * @var array<string, array{model: class-string, column: string, value: string, color: string, scope?: string}>
     */
    private const QUEUE_BADGES = [
        'admin.tickets.index' => [
            'model' => Ticket::class,
            'column' => 'status',
            'value' => 'open',
            'color' => 'warning',
            'scope' => 'visibleTo',
        ],
        'admin.upgrade-requests.index' => [
            'model' => UpgradeRequest::class,
            'column' => 'status',
            'value' => 'pending',
            'color' => 'warning',
        ],
        'admin.provisioning-events.index' => [
            'model' => ProvisioningEvent::class,
            'column' => 'status',
            'value' => 'failed',
            'color' => 'danger',
        ],
    ];

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    public function transform(array $item): ?array
    {
        $route = is_array($item['route'] ?? null) ? ($item['route'][0] ?? null) : ($item['route'] ?? null);

        if (in_array($route, self::NOTIFICATION_ROUTES, true)) {
            $user = Auth::user();
            $unread = $user !== null ? $user->unreadNotifications()->count() : 0;

            if ($unread > 0) {
                $item['label'] = $unread;
                $item['label_color'] = 'primary';
            }

            return $item;
        }

        $badge = self::QUEUE_BADGES[$route] ?? null;

        if ($badge !== null) {
            $query = $badge['model']::query();

            if (isset($badge['scope'])) {
                $user = Auth::user();

                if ($user === null) {
                    return $item;
                }

                $query->{$badge['scope']}($user);
            }

            $count = $query->where($badge['column'], $badge['value'])->count();

            if ($count > 0) {
                $item['label'] = $count;
                $item['label_color'] = $badge['color'];
            }
        }

        return $item;
    }
}
