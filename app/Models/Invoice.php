<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['invoice_no', 'customer_id', 'order_id', 'amount', 'tax', 'tax_rate', 'discount', 'total', 'gst_enabled', 'cgst_rate', 'cgst_amount', 'sgst_rate', 'sgst_amount', 'igst_rate', 'igst_amount', 'status', 'due_date', 'paid_at', 'notes', 'paid_amount', 'last_reminder_at', 'reminder_count'])]
class Invoice extends Model
{
    /**
     * Attribute casts. NOTE: previously declared via #[Cast(...)] class
     * attributes, which Eloquent's getCasts() (HasAttributes) does not read —
     * casts only resolve from the $casts property. Converted so date/datetime
     * columns (due_date, paid_at) actually hydrate as Carbon instances.
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'tax' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'cgst_rate' => 'decimal:2',
        'cgst_amount' => 'decimal:2',
        'sgst_rate' => 'decimal:2',
        'sgst_amount' => 'decimal:2',
        'igst_rate' => 'decimal:2',
        'igst_amount' => 'decimal:2',
        'gst_enabled' => 'boolean',
        'reminder_count' => 'integer',
        'due_date' => 'date',
        'paid_at' => 'datetime',
        'last_reminder_at' => 'datetime',
    ];

    /**
     * Invoice statuses — 7-value superset (decisions.md #1). The reference keeps
     * a 5-value PHP enum (draft/sent/paid/overdue/cancelled) but markPaid and
     * reconciliation write 'partial' and the DB enum adds 'void'; the port uses
     * plain string constants on the model.
     */
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_PAID = 'paid';

    public const STATUS_OVERDUE = 'overdue';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_VOID = 'void';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_LABELS = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_SENT => 'Sent',
        self::STATUS_PAID => 'Paid',
        self::STATUS_OVERDUE => 'Overdue',
        self::STATUS_PARTIAL => 'Partial',
        self::STATUS_VOID => 'Void',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    /**
     * Allowed status transitions from each current status. Only these statuses
     * may be selected when editing an invoice; amounts are locked (see
     * isAmountLocked) so status is the only mutable field once money moved.
     */
    public const STATUS_TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_SENT, self::STATUS_CANCELLED, self::STATUS_VOID],
        self::STATUS_SENT => [self::STATUS_DRAFT, self::STATUS_OVERDUE, self::STATUS_PAID, self::STATUS_CANCELLED, self::STATUS_VOID],
        self::STATUS_OVERDUE => [self::STATUS_SENT, self::STATUS_PAID, self::STATUS_CANCELLED, self::STATUS_VOID],
        self::STATUS_PARTIAL => [self::STATUS_PAID, self::STATUS_VOID],
        self::STATUS_PAID => [self::STATUS_VOID],
        self::STATUS_VOID => [self::STATUS_DRAFT],
        self::STATUS_CANCELLED => [self::STATUS_DRAFT],
    ];

    /**
     * Whether the invoice's amounts are locked and only status/due_date/notes
     * may be edited: once any payment exists (paid_amount > 0 or a payments
     * row) or the status is a terminal/settled one, the line items and totals
     * must stay frozen.
     */
    public function isAmountLocked(): bool
    {
        return (float) $this->paid_amount > 0
            || $this->payments()->exists()
            || in_array($this->status, [self::STATUS_PAID, self::STATUS_PARTIAL, self::STATUS_VOID, self::STATUS_CANCELLED], true);
    }

    /**
     * Statuses selectable when editing: the current status plus every allowed
     * transition. The current status is always kept first.
     *
     * @return array<string,string>  status => label
     */
    public function allowedStatusTransitions(): array
    {
        $statuses = [$this->status => self::STATUS_LABELS[$this->status] ?? ucfirst((string) $this->status)];

        foreach (self::STATUS_TRANSITIONS[$this->status] ?? [] as $status) {
            $statuses[$status] = self::STATUS_LABELS[$status] ?? ucfirst((string) $status);
        }

        return $statuses;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    // ─── Billing status helpers ────────────────────────────────────

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isPartial(): bool
    {
        return $this->status === self::STATUS_PARTIAL;
    }

    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_OVERDUE;
    }

    public function isVoid(): bool
    {
        return $this->status === self::STATUS_VOID;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Whether the invoice is settled, i.e. status 'paid' or the accumulated
     * paid_amount has reached the total.
     */
    public function isFullyPaid(): bool
    {
        return $this->isPaid() || (float) $this->paid_amount >= (float) $this->total;
    }

    /**
     * Outstanding balance (never negative).
     */
    public function dueAmount(): float
    {
        return max(0.0, (float) $this->total - (float) $this->paid_amount);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? ucfirst((string) $this->status);
    }

    /**
     * GST breakdown accessor: the summed CGST/SGST/IGST amounts and rates plus
     * the tax treatment type ('intra' / 'inter' / 'na').
     *
     * @return array{tax:float,cgst:float,cgst_rate:?float,sgst:float,sgst_rate:?float,igst:float,igst_rate:?float,type:string}
     */
    public function getGstBreakdownAttribute(): array
    {
        $tax = (float) ($this->tax ?? 0);
        $cgst = (float) ($this->cgst_amount ?? 0);
        $sgst = (float) ($this->sgst_amount ?? 0);
        $igst = (float) ($this->igst_amount ?? 0);

        $type = $tax <= 0 ? 'na' : ($igst > 0 ? 'inter' : 'intra');

        return [
            'tax' => $tax,
            'cgst' => $cgst,
            'cgst_rate' => $this->cgst_rate !== null ? (float) $this->cgst_rate : null,
            'sgst' => $sgst,
            'sgst_rate' => $this->sgst_rate !== null ? (float) $this->sgst_rate : null,
            'igst' => $igst,
            'igst_rate' => $this->igst_rate !== null ? (float) $this->igst_rate : null,
            'type' => $type,
        ];
    }
}
