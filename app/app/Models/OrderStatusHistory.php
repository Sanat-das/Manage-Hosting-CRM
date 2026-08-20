<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable audit row for every guarded order status transition (gap-fillup
 * T1.2). One row per hop — from_status, to_status, actor, optional notes.
 */
#[Fillable(['order_id', 'from_status', 'to_status', 'changed_by_user_id', 'notes'])]
class OrderStatusHistory extends Model
{
    protected $table = 'order_status_history';

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The user who performed the transition (nullable — system jobs act
     * without an authenticated user).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
