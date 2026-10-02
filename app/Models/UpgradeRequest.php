<?php

namespace App\Models;

use App\Services\Billing\UpgradeQuoteService;
use App\Services\Billing\UpgradeRequestService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A WHMCS-style product upgrade/downgrade request. The row snapshots the
 * prorated quote (credited/debited/payable/credit_amount) that the billing
 * engine computed at request time; the invoice (payable) or wallet credit
 * (credit_amount) is created on approval and materialized on apply.
 *
 * @see UpgradeQuoteService
 * @see UpgradeRequestService
 */
#[Fillable(['upgrade_no', 'order_id', 'customer_id', 'invoice_id', 'from_product_id', 'to_product_id', 'upgrade_type', 'change_type', 'status', 'billing_cycle', 'to_billing_cycle', 'options', 'credited', 'debited', 'setup_fee', 'payable', 'credit_amount', 'proration_days', 'period_days', 'approved_at', 'applied_at', 'cancelled_at', 'notes'])]
class UpgradeRequest extends Model
{
    use HasFactory;

    protected $casts = [
        'credited' => 'decimal:2',
        'debited' => 'decimal:2',
        'setup_fee' => 'decimal:2',
        'payable' => 'decimal:2',
        'credit_amount' => 'decimal:2',
        'options' => 'array',
        'approved_at' => 'date',
        'applied_at' => 'date',
        'cancelled_at' => 'date',
    ];

    /** Exact values of the upgrade_requests.status enum column. */
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = ['pending', 'applied', 'cancelled'];

    /**
     * Display id for upgrade requests: the generated UPG-{YEAR}-{seq} number,
     * falling back to the raw row id until OrderNumberService assigns one.
     *
     * Read from the raw attributes array on purpose: this accessor is named
     * for the exact column it wraps (`upgrade_no`), so `$this->upgrade_no`
     * would re-enter the accessor instead of the stored value.
     */
    public function getUpgradeNoAttribute(): string
    {
        return $this->attributes['upgrade_no'] ?? '#'.$this->id;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function fromProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'from_product_id');
    }

    public function toProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'to_product_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Bootstrap badge HTML for the change type (upgrade/downgrade/equal).
     * Returns an empty string when change_type is null (pre-migration rows).
     */
    public function changeTypeBadge(): string
    {
        return match ($this->change_type) {
            'upgrade' => '<span class="badge text-bg-success">Upgrade</span>',
            'downgrade' => '<span class="badge text-bg-info">Downgrade</span>',
            'equal' => '<span class="badge text-bg-secondary">Equal</span>',
            default => '',
        };
    }
}
