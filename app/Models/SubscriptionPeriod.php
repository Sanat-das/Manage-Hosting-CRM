<?php

namespace App\Models;

use App\Models\ServiceInstance;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['service_id', 'billing_cycle', 'start_date', 'end_date', 'next_invoice_date', 'amount', 'currency', 'tax_rate', 'status', 'parent_period_id'])]
class SubscriptionPeriod extends Model
{
    protected $table = 'subscription_periods';

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'next_invoice_date' => 'date',
            'amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(ServiceInstance::class, 'service_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_period_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_period_id');
    }
}
