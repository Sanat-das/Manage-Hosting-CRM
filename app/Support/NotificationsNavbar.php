<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Throwable;

/**
 * Feeds the top-navbar notification bell from the database notification inbox.
 *
 * The package default (NavbarData::notifications) reads a demo array, so the
 * bell either showed fixture rows or nothing at all. This install keeps its
 * notifications in the standard `notifications` table, so the dropdown is fed
 * from `User::unreadNotifications()` — the same source the inbox controllers
 * paginate — and every row carries the presentation the redesigned partial
 * renders: id, icon, color, title, text, time, url.
 *
 * Every method is defensive: the navbar renders on every page, so a missing
 * `notifications` table (legacy or partially migrated install) or any other
 * failure must degrade to an empty dropdown / zero badge, never a 500.
 */
final class NotificationsNavbar
{
    /** Rows shown in the dropdown; the badge is not capped. */
    public const LIMIT = 5;

    /**
     * Whitelist of Bootstrap contextual colours, so the Blade can interpolate
     * a colour name into a class without ever echoing a payload value.
     */
    public const COLORS = ['primary', 'success', 'danger', 'warning', 'info', 'secondary'];

    /**
     * Exact notification type => [Bootstrap icon, palette colour].
     *
     * Types are the stable `data.type` contract written by the notification
     * classes, not the PHP class names, so payloads keep rendering after a
     * notification class is renamed.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const PRESENTATION = [
        'order.created' => ['bi-bag-check', 'success'],
        'order.paid' => ['bi-credit-card', 'primary'],
        'invoice.overdue' => ['bi-receipt', 'danger'],
        'ticket.new' => ['bi-life-preserver', 'info'],
        'ticket.reply' => ['bi-chat-left-text', 'primary'],
        'ticket.transferred' => ['bi-arrow-left-right', 'info'],
        'domain.expiring' => ['bi-globe2', 'warning'],
        'chat.reply' => ['bi-chat-dots', 'info'],
        'chat.mention' => ['bi-chat-dots', 'info'],
        'chat.assign' => ['bi-person-check', 'info'],
    ];

    /**
     * Exact notification type => English dropdown title.
     *
     * @var array<string, string>
     */
    private const TITLES = [
        'order.created' => 'New order',
        'order.paid' => 'Payment received',
        'invoice.overdue' => 'Invoice overdue',
        'ticket.new' => 'New ticket',
        'ticket.reply' => 'Ticket reply',
        'ticket.transferred' => 'Ticket transferred',
        'domain.expiring' => 'Domain expiring',
        'chat.reply' => 'Chat reply',
        'chat.mention' => 'Chat mention',
        'chat.assign' => 'Chat assigned',
    ];

    /** Icon shown for an unknown or missing notification type. */
    private const FALLBACK_ICON = 'bi-bell-fill';

    /** Colour shown for an unknown or missing notification type. */
    private const FALLBACK_COLOR = 'secondary';

    /**
     * Payload entity id key => [staff route name, client route name].
     *
     * Iteration order is the deep-link priority when a payload somehow
     * carries more than one id.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const ENTITY_ROUTES = [
        'order_id' => ['admin.orders.show', 'client.orders.show'],
        'ticket_id' => ['admin.tickets.show', 'client.tickets.show'],
        'invoice_id' => ['admin.invoices.show', 'client.invoices.show'],
        'domain_id' => ['admin.domains.show', 'client.domains.show'],
    ];

    /**
     * Newest-first unread notification previews, capped at $limit.
     *
     * Shape matches what navbar-notifications.blade.php reads:
     * id, icon, color, title, text, time, url. The relation already orders
     * by `created_at` descending, so no re-sort is needed.
     *
     * @return list<array{id: string, icon: string, color: string, title: string, text: string, time: string, url: string}>
     */
    public static function rows(?User $user, int $limit = self::LIMIT): array
    {
        if ($user === null || ! $user->exists || $limit < 1) {
            return [];
        }

        try {
            return $user->unreadNotifications()
                ->limit($limit)
                ->get()
                ->map(fn (DatabaseNotification $notification): array => self::row($user, $notification))
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Badge number: every unread notification, not just the rows shown.
     */
    public static function count(?User $user): int
    {
        if ($user === null || ! $user->exists) {
            return 0;
        }

        try {
            return max(0, (int) $user->unreadNotifications()->count());
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Destination for "See all notifications" — the inbox for the user's
     * panel. Null when the user has no panel (or no permission to view it),
     * never the dead `'#'` link.
     */
    public static function indexUrl(?User $user): ?string
    {
        if ($user === null || ! $user->exists) {
            return null;
        }

        try {
            if (self::isClient($user)) {
                return Route::has('client.notifications.index')
                    ? route('client.notifications.index')
                    : null;
            }

            if ($user->hasPermission('notifications.view') && Route::has('admin.notifications.index')) {
                return route('admin.notifications.index');
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Destination for the "mark all read" action. Staff need the manage
     * permission; clients are gated by the portal, not by staff RBAC.
     */
    public static function markAllReadUrl(?User $user): ?string
    {
        if ($user === null || ! $user->exists) {
            return null;
        }

        try {
            if (self::isClient($user)) {
                return Route::has('client.notifications.markAllRead')
                    ? route('client.notifications.markAllRead')
                    : null;
            }

            if ($user->hasPermission('notifications.manage') && Route::has('admin.notifications.markAllRead')) {
                return route('admin.notifications.markAllRead');
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * One dropdown row.
     *
     * @return array{id: string, icon: string, color: string, title: string, text: string, time: string, url: string}
     */
    private static function row(User $user, DatabaseNotification $notification): array
    {
        $data = is_array($notification->data) ? $notification->data : [];
        $type = self::payloadType($data);
        $presentation = self::presentation($type);

        return [
            'id' => (string) $notification->id,
            'icon' => $presentation['icon'],
            'color' => $presentation['color'],
            'title' => self::title($type, $notification),
            'text' => self::text($type, $data),
            'time' => $notification->created_at?->diffForHumans() ?? '',
            'url' => self::url($user, $data),
        ];
    }

    /**
     * The `data.type` contract value, or null when the payload carries none
     * (older notifications written before the key existed).
     *
     * @param  array<string, mixed>  $data
     */
    private static function payloadType(array $data): ?string
    {
        $type = $data['type'] ?? null;

        return is_string($type) && $type !== '' ? $type : null;
    }

    /**
     * Icon plus colour for a type, falling back to a neutral bell.
     *
     * @return array{icon: string, color: string}
     */
    private static function presentation(?string $type): array
    {
        if ($type !== null && isset(self::PRESENTATION[$type])) {
            return [
                'icon' => self::PRESENTATION[$type][0],
                'color' => self::PRESENTATION[$type][1],
            ];
        }

        return ['icon' => self::FALLBACK_ICON, 'color' => self::FALLBACK_COLOR];
    }

    /**
     * Human title for a type. Payloads without a type key fall back to the
     * notification class name, exactly like Admin\NotificationController
     * (basename minus the `Notification` suffix, headline-cased), so the
     * dropdown and the inbox never disagree about the same row.
     */
    private static function title(?string $type, DatabaseNotification $notification): string
    {
        if ($type === null) {
            $base = class_basename((string) $notification->type);
            $base = preg_replace('/Notification$/', '', $base) ?? $base;

            // Str::headline, not Str::title(Str::snake(...)): title() keeps
            // the snake separators ("Order_Created"), headline() renders the
            // words the inbox shows ("Order Created").
            return Str::headline($base);
        }

        return self::TITLES[$type] ?? Str::headline(str_replace('.', ' ', $type));
    }

    /**
     * One-line preview. Chat payloads are escaped with `htmlspecialchars` at
     * write time (ChatReplyNotification / ChatMentionNotification), so only
     * they are decoded back; every other payload is handed to the view as
     * stored and Blade escapes it on output.
     *
     * @param  array<string, mixed>  $data
     */
    private static function text(?string $type, array $data): string
    {
        $message = $data['message'] ?? '';
        $text = is_scalar($message) ? (string) $message : '';

        if ($type !== null && str_starts_with($type, 'chat.')) {
            $text = html_entity_decode($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return Str::limit($text, 80);
    }

    /**
     * Row destination, in priority order: an explicit payload url, then the
     * entity deep link, then the inbox, then the dead `'#'` anchor as the
     * absolute last resort.
     *
     * @param  array<string, mixed>  $data
     */
    private static function url(User $user, array $data): string
    {
        $explicit = $data['url'] ?? null;

        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        return self::entityUrl($user, $data) ?? self::indexUrl($user) ?? '#';
    }

    /**
     * Deep link derived from a payload entity id. Route parameters are passed
     * positionally, so a route's parameter name (`id`, `order`, ...) cannot
     * break the link.
     *
     * @param  array<string, mixed>  $data
     */
    private static function entityUrl(User $user, array $data): ?string
    {
        foreach (self::ENTITY_ROUTES as $key => [$staffRoute, $clientRoute]) {
            $id = $data[$key] ?? null;

            if ((! is_int($id) && ! is_string($id)) || $id === '') {
                continue;
            }

            $route = self::isClient($user) ? $clientRoute : $staffRoute;

            if (Route::has($route)) {
                return route($route, [$id]);
            }
        }

        return null;
    }

    /**
     * Client-portal users are detected the same way the middleware does:
     * the `users.role` column or an attached role named `client`.
     */
    private static function isClient(User $user): bool
    {
        return $user->hasRole('client');
    }
}
