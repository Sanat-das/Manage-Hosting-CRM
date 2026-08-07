<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['domain_pricing_id', 'term_years', 'register_price', 'renew_price'])]
class DomainPricingTerm extends Model
{
    protected $table = 'domain_pricing_terms';

    protected function casts(): array
    {
        return [
            'register_price' => 'decimal:2',
            'renew_price' => 'decimal:2',
        ];
    }

    public function pricing(): BelongsTo
    {
        return $this->belongsTo(DomainPricing::class, 'domain_pricing_id');
    }
}
