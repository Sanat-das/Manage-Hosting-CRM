<?php

declare(strict_types=1);

namespace App\ViewModels\Admin;

use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Money owed for the admin dashboard "Outstanding receivables" widget.
 *
 * Every query is defensive: a missing table or a failing query falls back to
 * 0 / empty so the dashboard never throws on a fresh install or a partial
 * migration (same idiom as DashboardController::safe* helpers).
 *
 * Outstanding is the per-row clamp of Invoice::dueAmount() —
 * max(0, total - paid_amount) — evaluated in SQL so a large ledger never
 * leaves the database. Overpaid rows contribute 0 and never make a total
 * negative. Money is INR everywhere (BillingSettings::$currency, single
 * app-wide currency); no currency column is read or invented.
 */
final class OutstandingReceivablesViewModel
{
    public readonly float $outstandingTotal;

    public readonly float $overdueTotal;

    public readonly float $currentTotal;

    public readonly int $overdueCount;

    public readonly int $oldestOverdueDays;

    /** @var Collection<int, Invoice> */
    public readonly Collection $topOverdue;

    public function __construct()
    {
        $this->outstandingTotal = $this->sumDue([
            Invoice::STATUS_SENT,
            Invoice::STATUS_OVERDUE,
            Invoice::STATUS_PARTIAL,
        ]);
        $this->overdueTotal = $this->sumDue([Invoice::STATUS_OVERDUE]);
        $this->currentTotal = $this->sumDue([Invoice::STATUS_SENT, Invoice::STATUS_PARTIAL]);
        $this->overdueCount = $this->countOverdue();
        $this->oldestOverdueDays = $this->oldestOverdueDays();
        $this->topOverdue = $this->topOverdue();
    }

    /**
     * SUM of the outstanding balance for the given invoice statuses.
     *
     * @param  array<int, string>  $statuses
     */
    private function sumDue(array $statuses): float
    {
        try {
            if (! Schema::hasTable((new Invoice)->getTable())) {
                return 0.0;
            }

            $due = Invoice::query()
                ->whereIn('status', $statuses)
                ->selectRaw('SUM(CASE WHEN COALESCE(total, 0) > COALESCE(paid_amount, 0) THEN COALESCE(total, 0) - COALESCE(paid_amount, 0) ELSE 0 END) as due')
                ->value('due');

            return (float) $due;
        } catch (Throwable) {
            return 0.0;
        }
    }

    /**
     * Invoices stuck on the 'overdue' status — mirrors the dashboard's
     * existing "overdue invoices" metric (status-based count).
     */
    private function countOverdue(): int
    {
        try {
            if (! Schema::hasTable((new Invoice)->getTable())) {
                return 0;
            }

            return (int) Invoice::query()
                ->where('status', Invoice::STATUS_OVERDUE)
                ->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Days since the earliest due_date among overdue invoices; 0 when there
     * are none. Future-dated rows clamp to 0 so the figure never goes
     * negative on inconsistent data.
     */
    private function oldestOverdueDays(): int
    {
        try {
            if (! Schema::hasTable((new Invoice)->getTable())) {
                return 0;
            }

            $earliest = Invoice::query()
                ->where('status', Invoice::STATUS_OVERDUE)
                ->whereNotNull('due_date')
                ->min('due_date');

            if ($earliest === null) {
                return 0;
            }

            return max(0, (int) Carbon::parse($earliest)->diffInDays(now()));
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * The five longest-overdue invoices for the preview table.
     *
     * @return Collection<int, Invoice>
     */
    private function topOverdue(): Collection
    {
        try {
            if (! Schema::hasTable((new Invoice)->getTable())) {
                return collect();
            }

            return Invoice::query()
                ->with('customer')
                ->where('status', Invoice::STATUS_OVERDUE)
                ->orderBy('due_date')
                ->limit(5)
                ->get();
        } catch (Throwable) {
            return collect();
        }
    }
}
