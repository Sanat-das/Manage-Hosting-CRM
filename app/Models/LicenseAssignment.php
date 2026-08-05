<?php

namespace App\Models;

use App\Models\License;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['license_id', 'assigned_to_type', 'assigned_to_id', 'assigned_at', 'released_at', 'notes'])]
class LicenseAssignment extends Model
{
    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }
}
