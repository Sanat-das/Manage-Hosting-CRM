<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Domain sync operation log.
 *
 * The table only has `created_at` (useCurrent) — no updated_at column, so
 * Eloquent's UPDATED_AT is disabled.
 */
#[Fillable(['provider', 'operation', 'status', 'payload', 'error'])]
class DomainSyncLog extends Model
{
    protected $table = 'domain_sync_log';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
