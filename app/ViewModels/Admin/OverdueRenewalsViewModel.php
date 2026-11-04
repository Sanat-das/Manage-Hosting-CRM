<?php

declare(strict_types=1);

namespace App\ViewModels\Admin;

use App\Models\Domain;
use App\Models\HostingAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * View data for the admin dashboard "Overdue renewals" widget.
 *
 * Pre-computes, defensively (mirroring DashboardController), everything the
 * partial needs: hosting_accounts and domains whose effective renewal date has
 * lapsed unpaid. The effective due date matches the hosting:overdue-check
 * command canonical rule: COALESCE(next_due_date, order.next_billing_date)
 * for hosting accounts, COALESCE(next_due_date, expiry_date) for domains,
 * strictly before today(). Terminated hosting accounts and non-active domains
 * are ignored.
 *
 * Each query is isolated in its own try/catch(Throwable) behind a
 * Schema::hasTable() guard so a missing table or failing query degrades to
 * 0 / an empty collection instead of throwing on a fresh or partial install.
 */
final readonly class OverdueRenewalsViewModel
{
    public Collection $hostingOverdue;

    public int $hostingCount;

    public Collection $domainOverdue;

    public int $domainCount;

    public function __construct()
    {
        $this->hostingOverdue = $this->loadHostingOverdue();
        $this->hostingCount = $this->countHostingOverdue();
        $this->domainOverdue = $this->loadDomainOverdue();
        $this->domainCount = $this->countDomainOverdue();
    }

    /**
     * The hosting effective due date expression, mirroring the admin grid's
     * sort and the hosting:overdue-check command.
     */
    private function hostingDueExpression(): string
    {
        return 'COALESCE(next_due_date, (SELECT next_billing_date FROM orders WHERE orders.id = hosting_accounts.order_id))';
    }

    /**
     * Non-terminated hosting_accounts whose effective due date is strictly
     * before today, ordered by due date ascending, capped at a 5-row preview.
     * No amount column exists on this table, so none is exposed.
     */
    private function loadHostingOverdue(): Collection
    {
        try {
            if (! Schema::hasTable((new HostingAccount)->getTable())) {
                return collect();
            }

            return HostingAccount::query()
                ->with(['customer', 'order:id,next_billing_date,order_number'])
                ->where('status', '!=', 'terminated')
                ->whereRaw($this->hostingDueExpression().' < ?', [now()->toDateString()])
                ->orderByRaw($this->hostingDueExpression().' ASC')
                ->limit(5)
                ->get();
        } catch (Throwable) {
            return collect();
        }
    }

    private function countHostingOverdue(): int
    {
        try {
            if (! Schema::hasTable((new HostingAccount)->getTable())) {
                return 0;
            }

            return HostingAccount::query()
                ->where('status', '!=', 'terminated')
                ->whereRaw($this->hostingDueExpression().' < ?', [now()->toDateString()])
                ->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Shared base query for the domain overdue signal: active domains whose
     * effective renewal date — COALESCE(next_due_date, expiry_date) — is
     * strictly before today. Reused by the preview load and the count so both
     * agree on the boundary.
     */
    private function domainOverdueQuery(): Builder
    {
        return Domain::query()
            ->where('status', 'active')
            ->whereRaw('COALESCE(next_due_date, expiry_date) < ?', [now()->toDateString()]);
    }

    private function loadDomainOverdue(): Collection
    {
        try {
            if (! Schema::hasTable((new Domain)->getTable())) {
                return collect();
            }

            return $this->domainOverdueQuery()
                ->with('customer')
                ->select('*')
                ->selectRaw('COALESCE(next_due_date, expiry_date) as renewal_due_date')
                ->orderByRaw('COALESCE(next_due_date, expiry_date) ASC')
                ->limit(5)
                ->get();
        } catch (Throwable) {
            return collect();
        }
    }

    private function countDomainOverdue(): int
    {
        try {
            if (! Schema::hasTable((new Domain)->getTable())) {
                return 0;
            }

            return $this->domainOverdueQuery()->count();
        } catch (Throwable) {
            return 0;
        }
    }
}
