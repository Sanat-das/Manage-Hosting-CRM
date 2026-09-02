<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Per-notifiable notification preference gate (T3.2).
 *
 * One row per (preferrable, type, channel). `enabled` is the authoritative
 * override: when a row exists its truthiness wins; when no row exists the
 * caller falls back to the global `settings` key (see
 * NotificationPreferenceService::SETTINGS_KEY_MAP) and then to "enabled".
 *
 * The compound unique index (preferrable_type, preferrable_id, type, channel)
 * is owned by the T3.1 migration — do not duplicate it here.
 */
#[Fillable(['preferrable_type', 'preferrable_id', 'type', 'channel', 'enabled'])]
class NotificationPreference extends Model
{
    public const CHANNEL_DATABASE = 'database';

    protected $casts = [
        'enabled' => 'boolean',
    ];

    /**
     * The notifiable entity (Customer, User, ...).
     */
    public function preferable(): MorphTo
    {
        return $this->morphTo();
    }
}
