<?php

declare(strict_types=1);

namespace App\ViewModels\Admin;

use App\Models\Domain;
use App\Models\HostingAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * View data for the admin dashboard "Upcoming renewals (next 30 days)" widget.
 *
 * Pre-computes, defensively (mirroring DashboardController), everything the
 * partial needs: active hosting_accounts and domains scheduled to renew inside
 * the inclusive [now(), now()+30d] window, the full (uncapped) counts, and the
 * projected domain renewal value for that window.
 *
 * Domains renew on COALESCE(next_due_date, expiry_date) — the explicit due date
 * when set, otherwise the registrar expiry date. hosting_accounts carries no
 * stored amount column, so the projected value is a domain-only figure by
 * design; the partial surfaces that caveat.
 *
 * Each query is isolated in its own try/catch(Throwable) behind a
 * Schema::hasTable() guard so a missing table or failing query degrades to
 * 0 / an empty collection instead of throwing on a fresh or partial install.
 */
final readonly class UpcomingRenewalsViewModel
{
    public Collection $hostingRenewals;

    public int $hostingCount;

    public Collection $domainRenewals;

    public int $domainCount;

    public float $projectedDomainValue;

    public function __construct()
    {
        $this->hostingRenewals = $this->loadHostingRenewals();
        $this->hostingCount = $this->countHostingRenewals();
        $this->domainRenewals = $this->loadDomainRenewals();
        $this->domainCount = $this->countDomainRenewals();
        $this->projectedDomainValue = $this->sumProjectedDomainValue();
    }

    /**
     * Inclusive renewal window, day-aligned so a DATE column due "today" counts
     * (a bare now() would exclude today's midnight timestamp) and so day 30 is
     * included. Applies equally to hosting next_due_date and the domains
     * COALESCE(next_due_date, expiry_date) expression.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(): array
    {
        return [now()->startOfDay(), now()->addDays(30)->endOfDay()];
    }

    /**
     * Active hosting_accounts due within the window, ordered by due date
     * ascending, capped at a 5-row preview. No amount column exists on this
     * table, so none is exposed.
     */
    private function loadHostingRenewals(): Collection
    {
        try {
            if (! Schema::hasTable((new HostingAccount)->getTable())) {
                return collect();
            }
            [$from, $to] = $this->window();

            return HostingAccount::query()
                ->with('customer')
                ->where('status', 'active')
                ->whereBetween('next_due_date', [$from, $to])
                ->orderBy('next_due_date')
                ->limit(5)
                ->get();
        } catch (Throwable) {
            return collect();
        }
    }

    private function countHostingRenewals(): int
    {
        try {
            if (! Schema::hasTable((new HostingAccount)->getTable())) {
                return 0;
            }
            [$from, $to] = $this->window();

            return HostingAccount::query()
                ->where('status', 'active')
                ->whereBetween('next_due_date', [$from, $to])
                ->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Shared base query for the domain renewal window: active domains whose
     * effective renewal date — COALESCE(next_due_date, expiry_date) — falls in
     * the window. Reused by the preview load, the count and the projected sum
     * so all three agree on the window boundary.
     */
    private function domainWindowQuery(): Builder
    {
        [$from, $to] = $this->window();

        return Domain::query()
            ->where('status', 'active')
            ->whereBetween(DB::raw('COALESCE(next_due_date, expiry_date)'), [$from, $to]);
    }

    private function loadDomainRenewals(): Collection
    {
        try {
            if (! Schema::hasTable((new Domain)->getTable())) {
                return collect();
            }

            return $this->domainWindowQuery()
                ->with('customer')
                ->select('*')
                ->selectRaw('COALESCE(next_due_date, expiry_date) as renewal_due_date')
                ->orderByRaw('COALESCE(next_due_date, expiry_date) asc')
                ->limit(5)
                ->get();
        } catch (Throwable) {
            return collect();
        }
    }

    private function countDomainRenewals(): int
    {
        try {
            if (! Schema::hasTable((new Domain)->getTable())) {
                return 0;
            }

            return $this->domainWindowQuery()->count();
        } catch (Throwable) {
            return 0;
        }
    }

    private function sumProjectedDomainValue(): float
    {
        try {
            if (! Schema::hasTable((new Domain)->getTable())) {
                return 0.0;
            }

            return (float) $this->domainWindowQuery()->sum('recurring_amount');
        } catch (Throwable) {
            return 0.0;
        }
    }
}
