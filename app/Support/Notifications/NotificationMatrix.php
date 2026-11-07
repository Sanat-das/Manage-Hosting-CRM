<?php

namespace App\Support\Notifications;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Global, admin-controlled gate for every notification event, per
 * (event, audience, channel) triple — the back end of the admin
 * "Notification Settings" matrix page.
 *
 * Lookup order for an applicable combination:
 *   1. A `notification_event_settings` row (admin override).
 *   2. The catalogue default from EventCatalogue.
 * Non-applicable combinations always deny.
 *
 * This service only GATES — it never dispatches. Per-user overrides live in
 * NotificationPreferenceService, which checks this matrix as its fallback.
 */
final class NotificationMatrix
{
    private const CACHE_KEY = 'notification_event_settings.v1';

    public static function allows(string $eventKey, ?string $audience, string $channel = EventCatalogue::CHANNEL_DATABASE): bool
    {
        if ($audience === null) {
            return false;
        }

        // Type unknown to the catalogue: opt-in by default (forward-compatible —
        // a new send path added before the catalogue is updated still sends).
        if (EventCatalogue::event($eventKey) === null) {
            return true;
        }

        $default = EventCatalogue::defaultFor($eventKey, $audience, $channel);

        if ($default === null) {
            return false;
        }

        $stored = self::stored();

        return $stored[$eventKey][$audience][$channel] ?? $default;
    }

    /**
     * Enabled staff audiences for an event/channel, in catalogue order.
     * Used by staff_broadcast events to build the recipient pool.
     *
     * @return array<int, string>
     */
    public static function enabledStaffRoles(string $eventKey, string $channel): array
    {
        $enabled = [];

        foreach (EventCatalogue::staffRoles() as $role) {
            if (self::allows($eventKey, $role, $channel)) {
                $enabled[] = $role;
            }
        }

        return $enabled;
    }

    /**
     * Matrix-enabled staff pool for staff_broadcast events: active users
     * whose `users.role` is enabled, UNION pivot RBAC grants of the same
     * role names (a pivot-only admin counts as an admin — probes must agree
     * with access control). Empty enabled-role set yields an empty pool.
     *
     * @return Collection<int, User>
     */
    public static function staffPool(string $eventKey, string $channel): Collection
    {
        $roles = self::enabledStaffRoles($eventKey, $channel);

        if ($roles === []) {
            return new Collection;
        }

        return User::query()
            ->where('status', 'active')
            ->where(function ($query) use ($roles): void {
                $query->whereIn('role', $roles)
                    ->orWhereHas('roles', static function ($roleQuery) use ($roles): void {
                        $roleQuery->whereIn('name', $roles);
                    });
            })
            ->get();
    }

    /**
     * Resolve the matrix audience for a notifiable model.
     *
     * Customer notifiables are `customer`. Staff audiences resolve from
     * `users.role` first, then the AdminLTE RBAC pivot in live staff order
     * (admin, then the canonical priority, then additional live roles
     * alphabetically); a client-role account with a pivot staff grant resolves
     * as that staff audience. Anything else yields null (matrix denies by
     * default).
     */
    public static function audienceFor(Model $notifiable): ?string
    {
        if ($notifiable instanceof Customer) {
            return 'customer';
        }

        if ($notifiable instanceof User) {
            $role = (string) $notifiable->role;
            $staffRoles = EventCatalogue::staffRoles();

            if (in_array($role, $staffRoles, true)) {
                return $role;
            }

            // Pivot RBAC grants resolve as staff even for client-role accounts,
            // so the matrix gate agrees with the pivot-aware digest pools.
            foreach ($staffRoles as $candidate) {
                if ($notifiable->hasRole($candidate)) {
                    return $candidate;
                }
            }

            return $role === 'client' ? 'customer' : null;
        }

        return null;
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Stored overrides shaped as [event_key => [audience => [channel => bool]]].
     *
     * @return array<string, array<string, array<string, bool>>>
     */
    private static function stored(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, static function (): array {
            $rows = [];

            foreach (DB::table('notification_event_settings')->get() as $row) {
                $rows[$row->event_key][$row->audience][$row->channel] = (bool) $row->enabled;
            }

            return $rows;
        });
    }
}
