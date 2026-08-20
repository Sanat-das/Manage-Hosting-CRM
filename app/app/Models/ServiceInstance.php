<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['customer_id', 'catalog_product_id', 'order_id', 'server_id', 'service_tag', 'username', 'domain', 'password_hash', 'provisioning_method', 'provisioning_config', 'provisioning_adapter_id', 'external_id', 'status', 'suspension_reason', 'suspended_at', 'terminated_at', 'next_billing_date'])]
class ServiceInstance extends Model
{
    use SoftDeletes;

    protected $table = 'service_instances';

    protected function casts(): array
    {
        return [
            'suspended_at' => 'datetime',
            'terminated_at' => 'datetime',
            'next_billing_date' => 'date',
            'provisioning_config' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function catalogProduct(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class, 'catalog_product_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * The server group the instance's server belongs to (via the
     * server_group_members pivot). A server can belong to several groups;
     * this resolves the first, matching the singular serverGroup usage in
     * the admin service-instance views.
     */
    public function serverGroup(): HasOneThrough
    {
        return $this->hasOneThrough(
            ServerGroup::class,
            ServerGroupMember::class,
            'server_id',      // FK on server_group_members → service_instances.server_id
            'id',             // PK on server_groups
            'server_id',      // local key on service_instances
            'server_group_id' // FK on server_group_members → server_groups
        );
    }

    public function adapter(): BelongsTo
    {
        return $this->belongsTo(ProvisioningAdapter::class, 'provisioning_adapter_id');
    }

    public function usageRecords(): HasMany
    {
        return $this->hasMany(UsageRecord::class, 'service_id');
    }

    public function subscriptionPeriods(): HasMany
    {
        return $this->hasMany(SubscriptionPeriod::class, 'service_id');
    }
}
