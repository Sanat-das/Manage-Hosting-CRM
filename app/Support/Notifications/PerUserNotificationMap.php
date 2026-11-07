<?php

namespace App\Support\Notifications;

use App\Models\Customer;
use App\Models\User;
use App\Services\NotificationPreferenceService;
use Illuminate\Database\Eloquent\Model;

/**
 * Frozen per-user notification preference maps for the client portal and the
 * staff panel. These are the only (type, channel) cells a user may override
 * for themselves; the global matrix stays admin-controlled.
 *
 * Resolution, labels and defaults for display are the caller's concern; this
 * class only carries the frozen map, the notifiable resolution and the
 * view-row builder so the save controllers, profile controllers and views
 * never drift apart.
 */
final class PerUserNotificationMap
{
    /**
     * @var array<string, array{label: string, description: string, notifiable: string, channels: list<string>, roles?: list<string>}>
     */
    public const CLIENT = [
        'order.created' => ['label' => 'Order created', 'description' => 'Confirmation when you place an order.', 'notifiable' => 'customer', 'channels' => ['database', 'mail']],
        'order.paid' => ['label' => 'Order paid', 'description' => 'In-app note when your order is marked as paid.', 'notifiable' => 'customer', 'channels' => ['database']],
        'invoice.overdue' => ['label' => 'Invoice overdue', 'description' => 'Reminder when an invoice passes its due date.', 'notifiable' => 'customer', 'channels' => ['database', 'mail']],
        'invoice.paid' => ['label' => 'Payment received', 'description' => 'When you pay an invoice, you get a receipt email with the PDF attached.', 'notifiable' => 'customer', 'channels' => ['mail']],
        'ticket.new' => ['label' => 'Ticket created', 'description' => 'Confirmation when a support ticket is opened.', 'notifiable' => 'customer', 'channels' => ['database', 'mail']],
        'ticket.reply' => ['label' => 'Ticket reply', 'description' => 'When support replies to your ticket.', 'notifiable' => 'customer', 'channels' => ['database', 'mail']],
        'domain.expiring' => ['label' => 'Domain expiring', 'description' => 'Heads-up before one of your domains expires.', 'notifiable' => 'customer', 'channels' => ['database']],
        'renewal.upcoming' => ['label' => 'Renewal reminder', 'description' => 'Reminder before your services and domains renew.', 'notifiable' => 'user', 'channels' => ['database', 'mail']],
        'chat.mention' => ['label' => 'Chat @mention', 'description' => 'When someone @mentions you in a chat conversation.', 'notifiable' => 'user', 'channels' => ['database']],
        'chat.reply' => ['label' => 'Chat threaded reply', 'description' => 'When someone replies to a chat message you authored.', 'notifiable' => 'user', 'channels' => ['database']],
        'chat.transcript' => ['label' => 'Chat transcript email', 'description' => 'A transcript emailed when a chat is closed.', 'notifiable' => 'user', 'channels' => ['mail']],
        'service.activated' => ['label' => 'Service credentials email', 'description' => 'Login credentials when a service is activated. Turning this off sends no credentials.', 'notifiable' => 'customer', 'channels' => ['mail']],
        'upgrade.requested' => ['label' => 'Upgrade requested', 'description' => 'Confirmation when a service upgrade is requested.', 'notifiable' => 'customer', 'channels' => ['mail']],
        'upgrade.applied' => ['label' => 'Upgrade applied', 'description' => 'Confirmation when a service upgrade is applied.', 'notifiable' => 'customer', 'channels' => ['mail']],
        'upgrade.cancelled' => ['label' => 'Upgrade cancelled', 'description' => 'Notice when a service upgrade is cancelled.', 'notifiable' => 'customer', 'channels' => ['mail']],
    ];

    /**
     * @var array<string, array{label: string, description: string, notifiable: string, channels: list<string>, roles?: list<string>}>
     */
    public const STAFF = [
        'order.created' => ['label' => 'Order created', 'description' => 'When a customer places an order.', 'notifiable' => 'user', 'channels' => ['database']],
        'order.paid' => ['label' => 'Order paid', 'description' => 'When an order is marked as paid.', 'notifiable' => 'user', 'channels' => ['database']],
        'ticket.new' => ['label' => 'Ticket created', 'description' => 'When a support ticket is opened.', 'notifiable' => 'user', 'channels' => ['database']],
        'ticket.reply' => ['label' => 'Ticket reply', 'description' => 'When a ticket receives a reply.', 'notifiable' => 'user', 'channels' => ['database']],
        'ticket.transfer' => ['label' => 'Ticket transferred', 'description' => 'When a ticket is moved to your department.', 'notifiable' => 'user', 'channels' => ['database']],
        'renewal.upcoming' => ['label' => 'Renewal reminder digest', 'description' => 'The daily renewal reminder email.', 'notifiable' => 'user', 'channels' => ['mail']],
        'service.overdue' => ['label' => 'Service overdue digest', 'description' => 'When services become overdue: in-app alert and email digest.', 'notifiable' => 'user', 'channels' => ['database', 'mail']],
        'chat.mention' => ['label' => 'Chat @mention', 'description' => 'When someone @mentions you in a chat conversation.', 'notifiable' => 'user', 'channels' => ['database']],
        'chat.reply' => ['label' => 'Chat threaded reply', 'description' => 'When someone replies to a chat message you authored.', 'notifiable' => 'user', 'channels' => ['database']],
        'chat.assign' => ['label' => 'Chat assigned', 'description' => 'When a chat conversation is assigned to you.', 'notifiable' => 'user', 'channels' => ['database']],
        'scheduled.report' => ['label' => 'Scheduled business report', 'description' => 'The weekly business summary email.', 'notifiable' => 'user', 'channels' => ['mail'], 'roles' => ['admin', 'staff', 'support']],
    ];

    /**
     * The client map, in display order.
     *
     * @return array<string, array{label: string, description: string, notifiable: string, channels: list<string>, roles?: list<string>}>
     */
    public static function client(): array
    {
        return self::CLIENT;
    }

    /**
     * The staff map, in display order, minus rows the user's role may not see.
     *
     * @return array<string, array{label: string, description: string, notifiable: string, channels: list<string>, roles?: list<string>}>
     */
    public static function staffFor(User $user): array
    {
        return array_filter(
            self::STAFF,
            fn (array $entry): bool => ! isset($entry['roles']) || $user->hasRole($entry['roles']),
        );
    }

    /**
     * Read a requested toggle. Present & truthy = true; absent = false.
     *
     * @param  array<string, mixed>  $input  The decoded notification_preferences payload.
     */
    public static function boolValue(array $input, string $type, string $channel): bool
    {
        $entry = $input[$type] ?? null;
        $value = is_array($entry) ? ($entry[$channel] ?? null) : null;

        return $value !== null && filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Resolve the notifiable for a map entry, or null when it is unavailable
     * (a client account with no linked Customer record).
     */
    public static function notifiable(array $entry, User $user, ?Customer $customer): ?Model
    {
        return $entry['notifiable'] === 'customer' ? $customer : $user;
    }

    /**
     * Build the view rows: display text plus, per channel, whether the cell is
     * applicable and its resolved initial state (null when not applicable or
     * when the notifiable is unavailable).
     *
     * @return list<array{key: string, label: string, description: string, notifiable: string, locked: bool, channels: array<string, array{applicable: bool, enabled: bool|null}>}>
     */
    public static function rows(array $map, User $user, ?Customer $customer, NotificationPreferenceService $preferences): array
    {
        $rows = [];

        foreach ($map as $key => $entry) {
            $notifiable = self::notifiable($entry, $user, $customer);
            $locked = $notifiable === null;

            $channels = [];
            foreach (['database', 'mail'] as $channel) {
                $applicable = in_array($channel, $entry['channels'], true);
                $channels[$channel] = [
                    'applicable' => $applicable,
                    'enabled' => ($applicable && ! $locked)
                        ? $preferences->isEnabled($notifiable, $key, $channel)
                        : null,
                ];
            }

            $rows[] = [
                'key' => $key,
                'label' => $entry['label'],
                'description' => $entry['description'],
                'notifiable' => $entry['notifiable'],
                'locked' => $locked,
                'channels' => $channels,
            ];
        }

        return $rows;
    }
}
