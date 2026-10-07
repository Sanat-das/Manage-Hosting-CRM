<?php

namespace App\Models;

use App\Services\Licenses\LicenseSeatReconciler;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['license_id', 'assigned_to_type', 'assigned_to_id', 'assigned_at', 'released_at', 'notes'])]
class LicenseAssignment extends Model
{
    /**
     * The table records its own `assigned_at` / `released_at` moments; it has
     * no created_at / updated_at columns.
     */
    public $timestamps = false;

    /**
     * Any write to an assignment changes how many seats the parent licence has
     * consumed, so recompute it. The reconciler only writes to `licenses`, so
     * this cannot recurse back into an assignment event. A broken assignment
     * write must fail loudly: no try/catch around the reconcile.
     */
    protected static function booted(): void
    {
        static::created(function (LicenseAssignment $assignment): void {
            app(LicenseSeatReconciler::class)->reconcile($assignment->license);
        });

        static::updated(function (LicenseAssignment $assignment): void {
            app(LicenseSeatReconciler::class)->reconcile($assignment->license);
        });

        static::deleted(function (LicenseAssignment $assignment): void {
            app(LicenseSeatReconciler::class)->reconcile($assignment->license);
        });
    }

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
