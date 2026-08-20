<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['ip_address_id', 'action', 'previous_assigned_to_type', 'previous_assigned_to_id', 'new_assigned_to_type', 'new_assigned_to_id', 'changed_by_user_id', 'ip_address_snapshot', 'changed_at', 'notes'])]
class IpAllocationHistory extends Model
{
    protected $table = 'ip_allocation_history';

    /**
     * The ledger records its own `changed_at` moment; the table has no
     * created_at / updated_at columns.
     */
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
        ];
    }

    public function ipAddress(): BelongsTo
    {
        return $this->belongsTo(IpAddress::class, 'ip_address_id');
    }
}
