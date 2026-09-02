<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit trail for module events and failures. No updated_at —
 * log rows are never modified.
 */
#[Fillable(['module_id', 'event', 'service_instance_id', 'status', 'error'])]
class ModuleLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'module_log';

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }
}
