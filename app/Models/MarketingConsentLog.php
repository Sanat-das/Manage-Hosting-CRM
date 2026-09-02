<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['customer_id', 'contact_type', 'consent_status', 'source', 'ip_address', 'user_agent'])]
class MarketingConsentLog extends Model
{
    protected $table = 'marketing_consent_log';

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
