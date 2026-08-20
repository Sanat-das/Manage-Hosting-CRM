<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['customer_id', 'product_id', 'order_number', 'billing_cycle', 'quantity', 'total', 'status', 'domain_name', 'notes', 'payment_method', 'subscription_id', 'next_billing_date', 'last_billing_date'])]
class Order extends Model
{
    /**
     * Attribute casts. NOTE: previously declared via #[Cast(...)] class
     * attributes, which Eloquent's getCasts() (HasAttributes) does not read —
     * casts only resolve from the $casts property. Converted so the billing
     * date columns hydrate as Carbon instances.
     */
    protected $casts = [
        'total' => 'decimal:2',
        'next_billing_date' => 'date',
        'last_billing_date' => 'date',
    ];

    /**
     * Order statuses — DB-level enum (decisions.md #2). The DDD
     * pending/processing/completed/refunded vocabulary is NOT ported.
     */
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_TERMINATED = 'terminated';

    /** Exact values of the orders.status enum column. */
    public const STATUSES = ['pending', 'paid', 'provisioning', 'failed', 'active', 'suspended', 'cancelled', 'terminated'];

    /** Billing cycles for orders (same vocabulary as products.billing_cycle). */
    public const BILLING_CYCLES = ['monthly', 'quarterly', 'semi_annual', 'annual', 'biennial', 'one_time'];

    /**
     * Maximum orderable quantity per line — single source of truth for the
     * storefront cart (StoreController), the admin cart, and the admin order
     * form (OrderRequest). Kept shared so the caps can never drift again
     * (the admin cap had diverged to 100000 while the store kept 99).
     */
    public const MAX_QUANTITY = 99;

    /** Cycle -> months (recurring billing track, learnings.md). */
    public const CYCLE_MONTHS = [
        'monthly' => 1,
        'quarterly' => 3,
        'semi_annual' => 6,
        'annual' => 12,
        'biennial' => 24,
        'one_time' => 0,
    ];

    /**
     * Display id for orders: the generated ORD-{YEAR}-{seq} number, falling
     * back to the raw row id for legacy rows that predate order_number.
     */
    public function getOrderNoAttribute(): string
    {
        return $this->order_number ?? '#'.$this->id;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function hostingAccount(): HasOne
    {
        return $this->hasOne(HostingAccount::class);
    }

    public function domain(): HasOne
    {
        return $this->hasOne(Domain::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }
}
