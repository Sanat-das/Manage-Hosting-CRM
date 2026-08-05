<?php

namespace App\Models;

use App\Models\ResourceType;
use App\Models\ServiceInstance;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['service_id', 'resource_type_id', 'metric', 'value', 'unit', 'recorded_at', 'source', 'billing_period_start', 'billing_period_end', 'invoiced', 'invoice_item_id'])]
class UsageRecord extends Model
{
    protected $table = 'usage_records';

    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            'recorded_at' => 'datetime',
            'billing_period_start' => 'date',
            'billing_period_end' => 'date',
            'invoiced' => 'boolean',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(ServiceInstance::class, 'service_id');
    }

    public function resourceType(): BelongsTo
    {
        return $this->belongsTo(ResourceType::class, 'resource_type_id');
    }
}
