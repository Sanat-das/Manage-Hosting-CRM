<?php

namespace App\Services;

use App\Models\NotificationPreference;
use App\Support\Notifications\NotificationMatrix;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-notifiable notification gate.
 *
 * Resolves whether a given notification `type` is enabled for a notifiable
 * on a given `channel`. Resolution order:
 *   1. A matching `notification_preferences` row wins (its `enabled` flag).
 *   2. With no row, fall back to the global matrix
 *      (notification_event_settings, admin-controlled, keyed by the
 *      notifiable's audience — see EventCatalogue/NotificationMatrix).
 *   3. Unknown types with no catalogue entry default to enabled.
 *
 * This service only GATES — it never dispatches.
 */
class NotificationPreferenceService
{
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

        return NotificationMatrix::allows($type, NotificationMatrix::audienceFor($notifiable), $channel);
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
