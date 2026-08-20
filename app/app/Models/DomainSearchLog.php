<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A single domain availability search (whois-style check history).
 *
 * The table only has `created_at` (useCurrent) — no updated_at column, so
 * Eloquent's UPDATED_AT is disabled.
 */
#[Fillable(['customer_id', 'domain_name', 'results'])]
class DomainSearchLog extends Model
{
    protected $table = 'domain_search_logs';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'results' => 'array',
        ];
    }
}
