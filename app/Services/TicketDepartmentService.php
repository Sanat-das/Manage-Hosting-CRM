<?php

namespace App\Services;

use App\Models\TicketDepartment;
use Illuminate\Support\Facades\DB;

/**
 * Department `is_default` invariant — exactly one row is the default.
 *
 * Only this service may establish the default. No booted hook / observer
 * auto-clears — callers must go through setDefault so the clearing of
 * siblings and the promotion of the target remain atomic.
 */
final class TicketDepartmentService
{
    /**
     * Make $department the single default.
     *
     * Clears every other row's is_default in the same transaction, then
     * promotes the target. The caller must have persisted $department
     * (so id is available); if it is not yet persisted it is saved first
     * with is_default=true and siblings are cleared afterwards.
     */
    public function setDefault(TicketDepartment $department): void
    {
        DB::transaction(function () use ($department): void {
            // Ensure the department exists before we address siblings by id.
            // Store path creates the row unsaved with is_default=true via fill;
            // handle that by persisting first, then clearing others.
            if (! $department->exists) {
                $department->is_default = true;
                $department->save();
            }

            // Clear every other department.
            TicketDepartment::query()
                ->where('id', '!=', $department->id)
                ->update(['is_default' => false]);

            // Promote the target. Use a query update to avoid firing
            // model events that could re-enter observers; then refresh
            // the in-memory instance so callers see the truth.
            TicketDepartment::query()
                ->where('id', $department->id)
                ->update(['is_default' => true]);

            $department->refresh();
            // Ensure cached department list reflects the new default.
            TicketService::forgetDepartmentCache();
        });
    }
}
