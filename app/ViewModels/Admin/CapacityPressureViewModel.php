<?php

declare(strict_types=1);

namespace App\ViewModels\Admin;

use App\Models\HostingAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Dashboard "Capacity pressure" widget data.
 *
 * Fleet-wide aggregation of the legacy hosting_accounts quota columns
 * (disk_used / disk_quota / bandwidth_used / bandwidth_quota). The same sums
 * the admin server screen already reports per server live in
 * App\Http\Controllers\Admin\ServerController::consumptionForServer()
 * (ServerController.php:652-657); the dashboard has never shown them rolled up.
 *
 * The columns carry no unit of their own — the migration
 * (database/migrations/2026_07_30_120030_create_order_tables.php:49-52) only
 * declares unsignedInteger. Everything that labels them uses MB:
 * resources/views/admin/hosting/show.blade.php:423-424,
 * ServerController.php:660-663 and app/Console/Commands/UsageSyncCommand.php:26.
 * This widget only renders ratios (percentages of quota), so no unit is
 * emitted — the raw sums are deliberately not surfaced.
 *
 * Built defensively in the spirit of DashboardController's safe* helpers: a
 * missing table or a failing query degrades to zeros / empty rather than
 * throwing on a fresh install.
 */
final class CapacityPressureViewModel
{
    /**
     * Utilisation at or above which an account counts as being under pressure.
     * Single source of truth for the badge, the bar colours and the SQL count.
     */
    public const THRESHOLD_PERCENT = 80;

    /** 100 * SUM(disk_used) / SUM(disk_quota) across accounts that have a quota. */
    public readonly float $diskUsedPercent;

    /** Same shape over the bandwidth columns. */
    public readonly float $bandwidthUsedPercent;

    /** Denominator of $diskUsedPercent — accounts with disk_quota > 0. */
    public readonly int $accountsWithDiskQuota;

    /**
     * Denominator of the bandwidth line — accounts with bandwidth_quota > 0.
     *
     * A separate column from $accountsWithDiskQuota: the two populations are
     * not guaranteed to coincide, so the card must never divide bandwidth
     * pressure by a disk quota count.
     */
    public readonly int $accountsWithBandwidthQuota;

    /** Accounts with disk_quota > 0 whose used/quota ratio reaches the threshold. */
    public readonly int $accountsOverDiskThreshold;

    /** Accounts with bandwidth_quota > 0 whose used/quota ratio reaches the threshold. */
    public readonly int $accountsOverBandwidthThreshold;

    /** Active hosting accounts in total, regardless of whether quotas are set. */
    public readonly int $activeAccountCount;

    public function __construct()
    {
        $aggregate = $this->safeAggregate();

        $this->diskUsedPercent = $aggregate['disk_used_percent'];
        $this->bandwidthUsedPercent = $aggregate['bandwidth_used_percent'];
        $this->accountsWithDiskQuota = $aggregate['accounts_with_disk_quota'];
        $this->accountsWithBandwidthQuota = $aggregate['accounts_with_bandwidth_quota'];
        $this->accountsOverDiskThreshold = $aggregate['accounts_over_disk_threshold'];
        $this->accountsOverBandwidthThreshold = $aggregate['accounts_over_bandwidth_threshold'];
        $this->activeAccountCount = $aggregate['active_account_count'];
    }

    /**
     * One aggregate query over hosting_accounts. Never per-row PHP loops:
     * every count is a SQL CASE/SUM and every sum is COALESCE(SUM(...),0), so a
     * fresh table with no rows yields zeros instead of nulls.
     *
     * Ratios use integer arithmetic (used * 100 >= quota * threshold) so the
     * threshold comparison never drifts through float rounding.
     *
     * @return array{
     *     disk_used_percent: float,
     *     bandwidth_used_percent: float,
     *     accounts_with_disk_quota: int,
     *     accounts_with_bandwidth_quota: int,
     *     accounts_over_disk_threshold: int,
     *     accounts_over_bandwidth_threshold: int,
     *     active_account_count: int,
     * }
     */
    private function safeAggregate(): array
    {
        $empty = [
            'disk_used_percent' => 0.0,
            'bandwidth_used_percent' => 0.0,
            'accounts_with_disk_quota' => 0,
            'accounts_with_bandwidth_quota' => 0,
            'accounts_over_disk_threshold' => 0,
            'accounts_over_bandwidth_threshold' => 0,
            'active_account_count' => 0,
        ];

        try {
            if (! Schema::hasTable((new HostingAccount)->getTable())) {
                return $empty;
            }

            // Numerator and denominator are both restricted to accounts that
            // carry a quota: a quota-less row would otherwise add its used
            // bytes to the top of the fraction while contributing nothing to
            // the bottom, and a percentage over 100 would be reported for
            // usage nobody is actually capped on.
            $row = DB::table('hosting_accounts')
                ->selectRaw('COALESCE(SUM(CASE WHEN disk_quota > 0 THEN disk_used END),0) as disk_used_total')
                ->selectRaw('COALESCE(SUM(CASE WHEN bandwidth_quota > 0 THEN bandwidth_used END),0) as bandwidth_used_total')
                ->selectRaw('COALESCE(SUM(CASE WHEN disk_quota > 0 THEN disk_quota END),0) as disk_quota_total')
                ->selectRaw('COALESCE(SUM(CASE WHEN bandwidth_quota > 0 THEN bandwidth_quota END),0) as bandwidth_quota_total')
                ->selectRaw('COALESCE(SUM(CASE WHEN disk_quota > 0 THEN 1 ELSE 0 END),0) as accounts_with_disk_quota')
                ->selectRaw('COALESCE(SUM(CASE WHEN bandwidth_quota > 0 THEN 1 ELSE 0 END),0) as accounts_with_bandwidth_quota')
                ->selectRaw('COALESCE(SUM(CASE WHEN disk_quota > 0 AND disk_used * 100 >= disk_quota * ? THEN 1 ELSE 0 END),0) as accounts_over_disk_threshold', [self::THRESHOLD_PERCENT])
                ->selectRaw('COALESCE(SUM(CASE WHEN bandwidth_quota > 0 AND bandwidth_used * 100 >= bandwidth_quota * ? THEN 1 ELSE 0 END),0) as accounts_over_bandwidth_threshold', [self::THRESHOLD_PERCENT])
                ->selectRaw("COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END),0) as active_account_count")
                ->first();

            if ($row === null) {
                return $empty;
            }

            $diskQuotaTotal = (float) ($row->disk_quota_total ?? 0);
            $bandwidthQuotaTotal = (float) ($row->bandwidth_quota_total ?? 0);

            return [
                'disk_used_percent' => $diskQuotaTotal > 0 ? ((float) ($row->disk_used_total ?? 0) / $diskQuotaTotal) * 100 : 0.0,
                'bandwidth_used_percent' => $bandwidthQuotaTotal > 0 ? ((float) ($row->bandwidth_used_total ?? 0) / $bandwidthQuotaTotal) * 100 : 0.0,
                'accounts_with_disk_quota' => (int) ($row->accounts_with_disk_quota ?? 0),
                'accounts_with_bandwidth_quota' => (int) ($row->accounts_with_bandwidth_quota ?? 0),
                'accounts_over_disk_threshold' => (int) ($row->accounts_over_disk_threshold ?? 0),
                'accounts_over_bandwidth_threshold' => (int) ($row->accounts_over_bandwidth_threshold ?? 0),
                'active_account_count' => (int) ($row->active_account_count ?? 0),
            ];
        } catch (Throwable) {
            // Missing column, dead connection, partial migration — the widget
            // must still render. Zeros are the honest answer.
            return $empty;
        }
    }
}
