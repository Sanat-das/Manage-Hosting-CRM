<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tld', 'register_price', 'renew_price', 'transfer_price', 'currency', 'premium', 'enabled', 'synced_at'])]
class DomainPricing extends Model
{
    protected $table = 'domain_pricing';

    protected function casts(): array
    {
        return [
            'register_price' => 'decimal:2',
            'renew_price' => 'decimal:2',
            'transfer_price' => 'decimal:2',
            'premium' => 'boolean',
            'enabled' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function terms(): HasMany
    {
        return $this->hasMany(DomainPricingTerm::class);
    }
}
