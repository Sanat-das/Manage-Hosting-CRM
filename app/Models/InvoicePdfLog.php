<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['invoice_id', 'customer_id', 'generated_by', 'file_name', 'file_size', 'file_path', 'mime_title'])]
class InvoicePdfLog extends Model
{
    protected $table = 'invoice_pdf_log';

    protected $casts = [
        'file_size' => 'integer',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
