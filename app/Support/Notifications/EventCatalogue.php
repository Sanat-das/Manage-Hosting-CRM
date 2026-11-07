<?php

namespace App\Support\Notifications;

use App\Models\Role;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Central catalogue of every notification event the application can send.
 *
 * This is the single source of truth for:
 * - the rows shown on the admin Notification Settings matrix page,
 * - the default state of `notification_event_settings` (seeder migration),
 * - which (audience, channel) combinations are applicable per event,
 * - whether staff recipients are drawn from the matrix-enabled roles
 *   (`staff_broadcast`) or targeted by the existing assignment/department
 *   logic with the matrix acting purely as a per-recipient filter.
 *
 * Audiences: the five staff panel roles from `users.role` plus `customer`
 * (a `client` user's linked `customers` row; client-role portal users and
 * `App\Models\Customer` notifiables both resolve to `customer`).
 *
 * Channels: `database` (Laravel database notifications) and `mail`
 * (queued `SendEmail` job). `channels` lists the channel axes present in
 * `defaults` somewhere; not every audience x channel combination is
 * applicable — combinations absent from `defaults` render disabled and
 * deny at the matrix.
 *
 * Defaults reproduce the behaviour that existed before centralization.
 */
final class EventCatalogue
{
    public const CHANNEL_DATABASE = 'database';

    public const CHANNEL_MAIL = 'mail';

    /**
     * Seeded baseline audience list, kept for the seeder contract. The live
     * list is `audiences()`, derived from `adminlte_roles`.
     */
    public const AUDIENCES = ['admin', 'staff', 'support', 'sales', 'marketing', 'customer'];

    public const STAFF_ROLES = ['admin', 'staff', 'support', 'sales', 'marketing'];

    /**
     * Canonical staff audience order; `admin` is always first, `customer` is
     * never a staff role.
     */
    public const STAFF_ROLE_PRIORITY = ['admin', 'staff', 'support', 'sales', 'marketing'];

    private const ROLE_CACHE_KEY = 'notification_audience_roles.v1';

    /**
     * @return array<int, array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     category: string,
     *     staff_broadcast: bool,
     *     audiences: array<int, string>,
     *     channels: array<int, string>,
     *     defaults: array<string, bool>
     * }>
     */
    public static function events(): array
    {
        return [
            // Orders & Billing
            [
                'key' => 'order.created',
                'label' => 'Order created',
                'description' => 'A customer placed an order. Staff members enabled here are notified for every new order.',
                'category' => 'Orders & Billing',
                'staff_broadcast' => true,
                'audiences' => self::AUDIENCES,
                'channels' => [self::CHANNEL_DATABASE, self::CHANNEL_MAIL],
                'defaults' => [
                    'database.customer' => true,
                    'database.admin' => true,
                    'database.staff' => false,
                    'database.support' => false,
                    'database.sales' => false,
                    'database.marketing' => false,
                    'mail.customer' => true,
                ],
            ],
            [
                'key' => 'order.paid',
                'label' => 'Order paid',
                'description' => 'An order was marked as paid. Staff members enabled here are notified for every payment.',
                'category' => 'Orders & Billing',
                'staff_broadcast' => true,
                'audiences' => self::AUDIENCES,
                'channels' => [self::CHANNEL_DATABASE],
                'defaults' => [
                    'database.customer' => true,
                    'database.admin' => true,
                    'database.staff' => false,
                    'database.support' => false,
                    'database.sales' => false,
                    'database.marketing' => false,
                ],
            ],
            [
                'key' => 'invoice.created',
                'label' => 'Invoice sent',
                'description' => 'An invoice email (with PDF) is sent automatically: storefront checkout, admin order creation, recurring renewal invoices. The manual "Send Invoice" button is not affected.',
                'category' => 'Orders & Billing',
                'staff_broadcast' => false,
                'audiences' => ['customer'],
                'channels' => [self::CHANNEL_MAIL],
                'defaults' => [
                    'mail.customer' => true,
                ],
            ],
            [
                'key' => 'invoice.overdue',
                'label' => 'Invoice overdue',
                'description' => 'The invoices:overdue-check command flips invoices past due date and notifies the customer.',
                'category' => 'Orders & Billing',
                'staff_broadcast' => false,
                'audiences' => ['customer'],
                'channels' => [self::CHANNEL_DATABASE, self::CHANNEL_MAIL],
                'defaults' => [
                    'database.customer' => true,
                    'mail.customer' => true,
                ],
            ],
            [
                'key' => 'invoice.paid',
                'label' => 'Payment received',
                'description' => 'An invoice is fully settled. The customer receives a receipt email with the invoice PDF attached.',
                'category' => 'Orders & Billing',
                'staff_broadcast' => false,
                'audiences' => ['customer'],
                'channels' => [self::CHANNEL_MAIL],
                'defaults' => [
                    'mail.customer' => true,
                ],
            ],
            [
                'key' => 'upgrade.requested',
                'label' => 'Upgrade requested',
                'description' => 'A customer (or staff on their behalf) requested a service upgrade.',
                'category' => 'Orders & Billing',
                'staff_broadcast' => false,
                'audiences' => ['customer'],
                'channels' => [self::CHANNEL_MAIL],
                'defaults' => [
                    'mail.customer' => true,
                ],
            ],
            [
                'key' => 'upgrade.applied',
                'label' => 'Upgrade applied',
                'description' => 'An upgrade was applied to a service (zero-net/credit path or on invoice payment).',
                'category' => 'Orders & Billing',
                'staff_broadcast' => false,
                'audiences' => ['customer'],
                'channels' => [self::CHANNEL_MAIL],
                'defaults' => [
                    'mail.customer' => true,
                ],
            ],
            [
                'key' => 'upgrade.cancelled',
                'label' => 'Upgrade cancelled',
                'description' => 'An upgrade request was cancelled by admin or superseded on renewal.',
                'category' => 'Orders & Billing',
                'staff_broadcast' => false,
                'audiences' => ['customer'],
                'channels' => [self::CHANNEL_MAIL],
                'defaults' => [
                    'mail.customer' => true,
                ],
            ],
            [
                'key' => 'service.activated',
                'label' => 'Service credentials email',
                'description' => 'Provisioning credentials for a newly activated service. Turning this off sends no credentials to new customers.',
                'category' => 'Orders & Billing',
                'staff_broadcast' => false,
                'audiences' => ['customer'],
                'channels' => [self::CHANNEL_MAIL],
                'defaults' => [
                    'mail.customer' => true,
                ],
            ],

            // Tickets
            [
                'key' => 'ticket.new',
                'label' => 'Ticket created',
                'description' => 'A new support ticket was opened (portal, admin or inbound mail). Staff delivery still honours department membership; the matrix filters which staff roles may receive it.',
                'category' => 'Tickets',
                'staff_broadcast' => false,
                'audiences' => self::AUDIENCES,
                'channels' => [self::CHANNEL_DATABASE, self::CHANNEL_MAIL],
                'defaults' => [
                    'database.customer' => true,
                    'mail.customer' => true,
                    'database.admin' => true,
                    'database.staff' => true,
                    'database.support' => true,
                    'database.sales' => true,
                    'database.marketing' => true,
                ],
            ],
            [
                'key' => 'ticket.reply',
                'label' => 'Ticket reply',
                'description' => 'A reply was posted to a ticket. Staff -> customer sends mail and an in-app note; customer -> staff notifies the assignee/department.',
                'category' => 'Tickets',
                'staff_broadcast' => false,
                'audiences' => self::AUDIENCES,
                'channels' => [self::CHANNEL_DATABASE, self::CHANNEL_MAIL],
                'defaults' => [
                    'database.customer' => true,
                    'mail.customer' => true,
                    'database.admin' => true,
                    'database.staff' => true,
                    'database.support' => true,
                    'database.sales' => true,
                    'database.marketing' => true,
                ],
            ],
            [
                'key' => 'ticket.transfer',
                'label' => 'Ticket transferred',
                'description' => 'A ticket was moved to the member\'s department. Internal staff alert only; customers are never notified.',
                'category' => 'Tickets',
                'staff_broadcast' => false,
                'audiences' => self::STAFF_ROLES,
                'channels' => [self::CHANNEL_DATABASE],
                'defaults' => [
                    'database.admin' => true,
                    'database.staff' => true,
                    'database.support' => true,
                    'database.sales' => true,
                    'database.marketing' => true,
                ],
            ],

            // Chat
            [
                'key' => 'chat.mention',
                'label' => 'Chat @mention',
                'description' => 'Someone was @mentioned in a chat conversation.',
                'category' => 'Chat',
                'staff_broadcast' => false,
                'audiences' => self::AUDIENCES,
                'channels' => [self::CHANNEL_DATABASE],
                'defaults' => [
                    'database.customer' => true,
                    'database.admin' => true,
                    'database.staff' => true,
                    'database.support' => true,
                    'database.sales' => true,
                    'database.marketing' => true,
                ],
            ],
            [
                'key' => 'chat.reply',
                'label' => 'Chat threaded reply',
                'description' => 'A reply was posted to a chat message the notifiable authored.',
                'category' => 'Chat',
                'staff_broadcast' => false,
                'audiences' => self::AUDIENCES,
                'channels' => [self::CHANNEL_DATABASE],
                'defaults' => [
                    'database.customer' => true,
                    'database.admin' => true,
                    'database.staff' => true,
                    'database.support' => true,
                    'database.sales' => true,
                    'database.marketing' => true,
                ],
            ],
            [
                'key' => 'chat.assign',
                'label' => 'Chat assigned',
                'description' => 'A chat conversation was assigned to the operator.',
                'category' => 'Chat',
                'staff_broadcast' => false,
                'audiences' => self::STAFF_ROLES,
                'channels' => [self::CHANNEL_DATABASE],
                'defaults' => [
                    'database.admin' => true,
                    'database.staff' => true,
                    'database.support' => true,
                    'database.sales' => true,
                    'database.marketing' => true,
                ],
            ],
            [
                'key' => 'chat.transcript',
                'label' => 'Chat transcript email',
                'description' => 'A conversation transcript is emailed when a chat is closed (when the chat setting send_transcript_on_close is on).',
                'category' => 'Chat',
                'staff_broadcast' => false,
                'audiences' => ['customer'],
                'channels' => [self::CHANNEL_MAIL],
                'defaults' => [
                    'mail.customer' => true,
                ],
            ],

            // Renewals & Hosting
            [
                'key' => 'renewal.upcoming',
                'label' => 'Renewal reminder',
                'description' => 'Renewal reminder ladder (configurable days). Customers get the reminder; staff members enabled here receive the daily admin digest.',
                'category' => 'Renewals & Hosting',
                'staff_broadcast' => true,
                'audiences' => self::AUDIENCES,
                'channels' => [self::CHANNEL_DATABASE, self::CHANNEL_MAIL],
                'defaults' => [
                    'database.customer' => true,
                    'mail.customer' => true,
                    'mail.admin' => true,
                    'mail.staff' => true,
                    'mail.support' => true,
                    'mail.sales' => false,
                    'mail.marketing' => false,
                ],
            ],
            [
                'key' => 'service.overdue',
                'label' => 'Service overdue digest',
                'description' => 'hosting:overdue-check reports newly-overdue services to staff. Staff members enabled here receive both the in-app alert and the email digest.',
                'category' => 'Renewals & Hosting',
                'staff_broadcast' => true,
                'audiences' => self::STAFF_ROLES,
                'channels' => [self::CHANNEL_DATABASE, self::CHANNEL_MAIL],
                'defaults' => [
                    'database.admin' => true,
                    'database.staff' => true,
                    'database.support' => true,
                    'database.sales' => false,
                    'database.marketing' => false,
                    'mail.admin' => true,
                    'mail.staff' => true,
                    'mail.support' => true,
                    'mail.sales' => false,
                    'mail.marketing' => false,
                ],
            ],
            [
                'key' => 'domain.expiring',
                'label' => 'Domain expiring',
                'description' => 'domains:expiry-check notifies the domain\'s customer about the upcoming expiry.',
                'category' => 'Renewals & Hosting',
                'staff_broadcast' => false,
                'audiences' => ['customer'],
                'channels' => [self::CHANNEL_DATABASE],
                'defaults' => [
                    'database.customer' => true,
                ],
            ],

            // System
            [
                'key' => 'scheduled.report',
                'label' => 'Scheduled business report',
                'description' => 'Weekly business summary email (reports:send-scheduled). Staff enabled here receive the report.',
                'category' => 'System',
                'staff_broadcast' => true,
                'audiences' => ['admin', 'staff', 'support'],
                'channels' => [self::CHANNEL_MAIL],
                'defaults' => [
                    'mail.admin' => true,
                    'mail.staff' => true,
                    'mail.support' => true,
                ],
            ],
        ];
    }

    /**
     * All applicable (audience, channel) combinations keyed as "{channel}.{audience}",
     * with their default enabled state.
     *
     * @return array<string, bool>
     */
    public static function defaultState(string $eventKey): array
    {
        $event = self::event($eventKey);

        return $event['defaults'] ?? [];
    }

    public static function event(string $eventKey): ?array
    {
        foreach (self::events() as $event) {
            if ($event['key'] === $eventKey) {
                return $event;
            }
        }

        return null;
    }

    /**
     * @return array<string, array<int, array<string, mixed>>> category => events, preserving declaration order
     */
    public static function groups(): array
    {
        $groups = [];

        foreach (self::events() as $event) {
            $groups[$event['category']][] = $event;
        }

        return $groups;
    }

    /**
     * Live role names from the AdminLTE RBAC table, alphabetical. Cached until
     * a Role model hook flushes it; no query on a warm cache.
     *
     * @return array<int, string>
     */
    public static function roleNames(): array
    {
        return Cache::rememberForever(self::ROLE_CACHE_KEY, static function (): array {
            return Schema::hasTable('adminlte_roles')
                ? Role::query()->orderBy('name')->pluck('name')->all()
                : [];
        });
    }

    /**
     * Live staff audiences: `admin` first, then the canonical priority order,
     * then every other live role alphabetically. `customer` is never a staff
     * role, and the list never contains duplicates.
     *
     * @return array<int, string>
     */
    public static function staffRoles(): array
    {
        $roleNames = self::roleNames();
        $roles = ['admin'];

        foreach (self::STAFF_ROLE_PRIORITY as $role) {
            if ($role !== 'admin' && in_array($role, $roleNames, true)) {
                $roles[] = $role;
            }
        }

        foreach ($roleNames as $role) {
            if ($role !== 'customer' && ! in_array($role, $roles, true)) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    /**
     * Live audience list used by the admin matrix: `admin`, the live staff
     * roles, then `customer` last. `admin` and `customer` are always present,
     * even when the roles table is empty.
     *
     * @return array<int, string>
     */
    public static function audiences(): array
    {
        $audiences = ['admin'];

        foreach (self::staffRoles() as $role) {
            if ($role !== 'admin') {
                $audiences[] = $role;
            }
        }

        $audiences[] = 'customer';

        return $audiences;
    }

    public static function flushAudiences(): void
    {
        Cache::forget(self::ROLE_CACHE_KEY);
    }

    public static function isApplicable(string $eventKey, string $audience, string $channel): bool
    {
        return self::defaultFor($eventKey, $audience, $channel) !== null;
    }

    /**
     * Default enabled state, or null when the combination is not applicable.
     *
     * A role that is not yet in the catalogue (a role created after this class
     * was written) inherits the staff axis wherever the canonical staff roles
     * have an entry for that channel, off by default — so new roles behave
     * like `staff`. Canonical roles keep their exact pre-existing
     * applicability, which is why e.g. `sales` stays a dash on
     * `scheduled.report`.
     */
    public static function defaultFor(string $eventKey, string $audience, string $channel): ?bool
    {
        $defaults = self::defaultState($eventKey);
        $key = $channel.'.'.$audience;

        if (array_key_exists($key, $defaults)) {
            return $defaults[$key];
        }

        if ($audience !== 'customer' && ! in_array($audience, self::STAFF_ROLE_PRIORITY, true)) {
            foreach (self::STAFF_ROLE_PRIORITY as $role) {
                if (array_key_exists($channel.'.'.$role, $defaults)) {
                    return false;
                }
            }
        }

        return null;
    }
}
