<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['customer_id', 'amount', 'type', 'description'])]
class Credit extends Model
{
    protected $casts = [
        'amount' => 'decimal:2',
    ];
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
