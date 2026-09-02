<?php

namespace App\Services;

use App\Models\NotificationPreference;
use App\Support\AppSettings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-notifiable notification gate (T3.2).
 *
 * Resolves whether a given notification `type` is enabled for a notifiable
 * on a given `channel`. Resolution order:
 *   1. A matching `notification_preferences` row wins (its `enabled` flag).
 *   2. With no row, fall back to the mapped global `settings` key truthiness.
 *   3. With neither a row nor a mapped settings key, default to enabled.
 *
 * This service only GATES — it never dispatches. Event/listener wiring is T3.3.
 */
class NotificationPreferenceService
{
    /**
     * Global settings key => notification type.
     *
     * @var array<string, string>
     */
    public const SETTINGS_KEY_MAP = [
        'notify_overdue_invoices' => 'invoice.overdue',
        'notify_domain_expiry' => 'domain.expiring',
        'notify_new_tickets' => 'ticket.new',
    ];

    /**
     * Notification type => global settings key (inverse of SETTINGS_KEY_MAP).
     *
     * @var array<string, string>
     */
    private const TYPE_TO_KEY = [
        'invoice.overdue' => 'notify_overdue_invoices',
        'domain.expiring' => 'notify_domain_expiry',
        'ticket.new' => 'notify_new_tickets',
    ];

    /**
     * Default channel when none is specified.
     */
    public const DEFAULT_CHANNEL = 'database';

    /**
     * Resolve whether the notification type is enabled for the notifiable.
     */
    public function isEnabled(Model $notifiable, string $type, string $channel = self::DEFAULT_CHANNEL): bool
    {
        $preference = NotificationPreference::query()
            ->where('preferrable_type', $notifiable->getMorphClass())
            ->where('preferrable_id', $notifiable->getKey())
            ->where('type', $type)
            ->where('channel', $channel)
            ->first();

        if ($preference !== null) {
            return (bool) $preference->enabled;
        }

        $settingsKey = self::TYPE_TO_KEY[$type] ?? null;

        if ($settingsKey === null) {
            // Unknown type with no preference row and no mapped setting: opt-in by default.
            return true;
        }

        return AppSettings::bool($settingsKey, true);
    }

    /**
     * Upsert the preference for a (notifiable, type, channel) tuple.
     */
    public function setPreference(Model $notifiable, string $type, bool $enabled, string $channel = self::DEFAULT_CHANNEL): NotificationPreference
    {
        return NotificationPreference::updateOrCreate(
            [
                'preferrable_type' => $notifiable->getMorphClass(),
                'preferrable_id' => $notifiable->getKey(),
                'type' => $type,
                'channel' => $channel,
            ],
            [
                'enabled' => $enabled,
            ],
        );
    }

    /**
     * All preference rows for the notifiable, regardless of type/channel.
     *
     * @return Collection<int, NotificationPreference>
     */
    public function preferencesFor(Model $notifiable)
    {
        return NotificationPreference::query()
            ->where('preferrable_type', $notifiable->getMorphClass())
            ->where('preferrable_id', $notifiable->getKey())
            ->get();
    }
}
