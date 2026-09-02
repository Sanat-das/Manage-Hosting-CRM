<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['invoice_id', 'product_id', 'description', 'quantity', 'unit_price', 'total', 'gst_enabled', 'gst_rate', 'gst_type', 'cgst_rate', 'cgst_amount', 'sgst_rate', 'sgst_amount', 'igst_rate', 'igst_amount'])]
class InvoiceItem extends Model
{
    protected $casts = [
        'unit_price' => 'decimal:2',
        'total' => 'decimal:2',
        'gst_enabled' => 'boolean',
        'gst_rate' => 'decimal:2',
        'cgst_rate' => 'decimal:2',
        'cgst_amount' => 'decimal:2',
        'sgst_rate' => 'decimal:2',
        'sgst_amount' => 'decimal:2',
        'igst_rate' => 'decimal:2',
        'igst_amount' => 'decimal:2',
    ];

    /**
     * The reference invoice_items table (schema.sql L202) has no created_at /
     * updated_at columns — disable Eloquent's automatic timestamps so inserts
     * don't reference the missing columns.
     */
    public $timestamps = false;

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
