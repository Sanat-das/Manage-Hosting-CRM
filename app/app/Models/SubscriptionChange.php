<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['service_id', 'from_subscription_period_id', 'to_subscription_period_id', 'change_type', 'credit_amount', 'charge_amount', 'proration_days', 'invoice_id', 'effective_date'])]
class SubscriptionChange extends Model
{
    protected $table = 'subscription_changes';

    protected function casts(): array
    {
        return [
            'credit_amount' => 'decimal:2',
            'charge_amount' => 'decimal:2',
            'proration_days' => 'integer',
            'effective_date' => 'date',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(ServiceInstance::class, 'service_id');
    }

    public function fromPeriod(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPeriod::class, 'from_subscription_period_id');
    }

    public function toPeriod(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPeriod::class, 'to_subscription_period_id');
    }
}
