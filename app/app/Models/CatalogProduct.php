<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['sku', 'name', 'category_id', 'description', 'product_type', 'provisioning_method', 'provisioning_config', 'billing_model', 'require_domain', 'show_in_order', 'only_admin', 'sort_order', 'status', 'version'])]
class CatalogProduct extends Model
{
    use SoftDeletes;

    protected $table = 'catalog_products';

    protected function casts(): array
    {
        return [
            'require_domain' => 'boolean',
            'show_in_order' => 'boolean',
            'only_admin' => 'boolean',
            'sort_order' => 'integer',
            'version' => 'integer',
            'provisioning_config' => 'array',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'category_id');
    }

    public function serviceInstances(): HasMany
    {
        return $this->hasMany(ServiceInstance::class, 'catalog_product_id');
    }
}
