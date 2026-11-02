<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rung of one renewal billing cycle resolved: the cycle's due date, the
 * lead time in days, and whether the reminder was delivered. `sent_at` NULL
 * means the rung was superseded or suppressed, not delivered.
 */
#[Fillable(['order_id', 'cycle_date', 'days_before', 'sent_at'])]
class RenewalReminderLog extends Model
{
    protected $casts = [
        'cycle_date' => 'date',
        'sent_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
