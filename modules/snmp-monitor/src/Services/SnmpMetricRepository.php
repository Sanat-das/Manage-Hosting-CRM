<?php

declare(strict_types=1);

namespace Modules\SnmpMonitor\Services;

use App\Models\HostingAccount;
use App\Models\Module;
use App\Services\Modules\ModuleManager;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\SnmpMonitor\Exceptions\UnlinkedAccountException;
use Modules\SnmpMonitor\Models\SnmpTarget;
use stdClass;

/**
 * Read gateway for the admin SNMP dashboard — the ONLY place outside the
 * polling jobs that touches the dedicated `monitoring` connection.
 *
 * Every per-account entry point funnels through assertLinked(), which
 * requires the account's product to carry an ENABLED snmp-monitor module
 * link (product_module pivot). Without that guard, snmp_latest / sample
 * rows of one product would be readable through any other account's id.
 *
 * Series aggregation buckets samples with FLOOR(UNIX_TIMESTAMP(col) /
 * bucket) on MySQL and the equivalent integer strftime expression under
 * sqlite (tests): both yield identical floor-division bucket indices.
 * Ranges strictly beyond 30 days switch to the pre-aggregated
 * snmp_metric_hourly tier instead of scanning the partitioned samples.
 */
final class SnmpMetricRepository
{
    /** Host-level series from snmp_host_samples, aggregated AVG/MIN/MAX. */
    public const HOST_METRICS = [
        'cpu_pct' => 'CPU Usage (%)',
        'cpu_load1' => 'CPU Load (1m)',
        'mem_used_mb' => 'Memory Used (MB)',
        'storage_pct' => 'Storage Used (%)',
        'response_ms' => 'Response Time (ms)',
        'proc_count' => 'Processes',
    ];

    /** Interface series from snmp_if_samples, totaled SUM/MIN/MAX per bucket. */
    public const IF_METRICS = [
        'in_bps' => 'Traffic In (bps)',
        'out_bps' => 'Traffic Out (bps)',
    ];

    public const METRICS = self::HOST_METRICS + self::IF_METRICS;

    /** Server-rendered time-range options, label => seconds. */
    public const RANGES = [
        '1h' => 3600,
        '24h' => 86400,
        '7d' => 604800,
        '30d' => 2592000,
    ];

    /**
     * Allowed bucket sizes in seconds. range/400 snaps up onto this ladder
     * so charts render at most ~400 points at human-friendly widths.
     */
    private const BUCKET_LADDER = [60, 300, 900, 3600, 21600];

    /** Ranges strictly beyond this many seconds read the hourly tier. */
    public const HOURLY_TIER_THRESHOLD = 2592000;

    /** Points per listing-page sparkline. */
    private const SPARKLINE_BUCKETS = 20;

    /** Trend window for listing-page sparklines. */
    private const SPARKLINE_RANGE_SECONDS = 3600;

    private ?Module $moduleCache = null;

    public function __construct(private readonly ModuleManager $manager) {}

    /**
     * Bucket width for a range: range/400 snapped UP onto the ladder so the
     * point count never exceeds ~400.
     */
    public static function resolveBucket(int $rangeSeconds): int
    {
        $target = max(1, (int) ceil($rangeSeconds / 400));

        foreach (self::BUCKET_LADDER as $bucket) {
            if ($bucket >= $target) {
                return $bucket;
            }
        }

        return self::BUCKET_LADDER[array_key_last(self::BUCKET_LADDER)];
    }

    /**
     * CRITICAL isolation guard: resolve the snmp-monitor module row only
     * when the account's product has an enabled link to it, else throw.
     */
    public function assertLinked(HostingAccount $account): Module
    {
        $module = $this->module();

        if ($module !== null) {
            $link = $account->product?->moduleLinks->firstWhere('module_id', $module->id);

            if ($link !== null && $link->enabled) {
                return $module;
            }
        }

        throw new UnlinkedAccountException(sprintf(
            'Hosting account [%s] is not covered by an enabled snmp-monitor module link.',
            $account->username ?? (string) $account->id,
        ));
    }

    /**
     * Global host listing: snmp_targets joined to hosting accounts whose
     * products have an enabled module link, merged with snmp_latest. A
     * fixed handful of queries — never one per row.
     *
     * @param  array{account?: int|string|null, status?: string|null, q?: string|null, cpu_min?: mixed, mem_min?: mixed, disk_min?: mixed}  $filters
     * @return array{rows: list<array{t: stdClass, latest: ?array<string, mixed>}>, total: int, accounts: list<stdClass>, stats: array{total: int, up: int, down: int, unknown: int, failing: int}, metrics?: array<int, array<string, mixed>>}
     */
    public function index(array $filters = [], int $perPage = 25, int $page = 1): array
    {
        if (($filters['account'] ?? null) !== null && $filters['account'] !== '') {
            $this->assertLinked(HostingAccount::query()->findOrFail((int) $filters['account']));
        }

        $module = $this->module();

        if ($module === null) {
            return ['rows' => [], 'total' => 0, 'accounts' => [], 'stats' => ['total' => 0, 'up' => 0, 'down' => 0, 'unknown' => 0, 'failing' => 0]];
        }

        $base = DB::table('snmp_targets as t')
            ->join('hosting_accounts as ha', 'ha.id', '=', 't.hosting_account_id')
            ->join('products as p', 'p.id', '=', 'ha.product_id')
            ->join('product_module as pm', 'pm.product_id', '=', 'p.id')
            ->where('pm.module_id', $module->id)
            ->where('pm.enabled', true)
            ->when(
                ($filters['account'] ?? null) !== null && $filters['account'] !== '',
                fn ($query) => $query->where('t.hosting_account_id', (int) $filters['account']),
            )
            ->when(
                ($filters['status'] ?? '') !== '',
                fn ($query) => $query->where('t.status', (string) $filters['status']),
            )
            ->when(trim((string) ($filters['q'] ?? '')) !== '', function ($query) use ($filters): void {
                $search = trim((string) $filters['q']);
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search).'%';
                $query->where(function ($inner) use ($like): void {
                    // host_name is what the listing actually displays, so it
                    // has to be searchable or the filter misses the value the
                    // operator can see on screen.
                    $inner->where('t.host', 'like', $like)
                        ->orWhere('ha.host_name', 'like', $like)
                        ->orWhere('ha.username', 'like', $like);
                });
            });

        $metricFilters = $this->normalizeMetricFilters($filters);

        if ($metricFilters !== []) {
            return $this->indexFilteredByMetrics($base, $module, $metricFilters, $perPage, $page);
        }

        $total = (clone $base)->count();
        // Stats must be computed from a clean clone BEFORE the rows query
        // below mutates $base in place (orderBy/forPage/select), else the
        // aggregate would only cover the current page.
        $stats = $this->statusStats($base, $total);

        $rows = $base
            ->orderBy('ha.username')
            ->orderBy('t.host')
            ->when($perPage > 0, fn ($query) => $query->forPage(max(1, $page), $perPage))
            ->get(['t.*', 'ha.username as account_username', 'ha.host_name as account_host_name', 'p.name as product_name']);

        return [
            'rows' => $this->mergeLatest($rows),
            'total' => $total,
            'accounts' => $this->linkedAccounts($module),
            'stats' => $stats,
        ];
    }

    /**
     * CPU/memory/disk thresholds requested on `index()`: {metric => min%}.
     * These live in snmp_host_samples on the monitoring connection — a
     * different database from the snmp_targets/hosting_accounts join above
     * — so they can never be folded into that SQL query.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, float>
     */
    private function normalizeMetricFilters(array $filters): array
    {
        $out = [];

        foreach (['cpu_min' => 'cpu_pct', 'mem_min' => 'mem_pct', 'disk_min' => 'disk_pct'] as $filterKey => $metricKey) {
            $value = $filters[$filterKey] ?? null;

            if ($value !== null && $value !== '' && is_numeric($value)) {
                $out[$metricKey] = (float) $value;
            }
        }

        return $out;
    }

    /**
     * The metric-filtered path: CPU/memory/disk thresholds can't be
     * expressed in the snmp_targets SQL query (different database), so the
     * WHOLE filtered set is materialized here, cross-referenced against
     * latestSampleMetrics() in PHP, then paginated after filtering. Only
     * reached when a threshold filter is actually set — the common,
     * unfiltered path above never pays this cost.
     *
     * @param  array<string, float>  $metricFilters
     * @return array{rows: list<array{t: stdClass, latest: ?array<string, mixed>}>, total: int, accounts: list<stdClass>, stats: array{total: int, up: int, down: int, unknown: int, failing: int}, metrics: array<int, array<string, mixed>>}
     */
    private function indexFilteredByMetrics(Builder $base, Module $module, array $metricFilters, int $perPage, int $page): array
    {
        $all = $base
            ->orderBy('ha.username')
            ->orderBy('t.host')
            ->get(['t.*', 'ha.username as account_username', 'ha.host_name as account_host_name', 'p.name as product_name']);

        $allIds = $all->map(fn ($row) => (int) $row->id)->all();
        $allMetrics = $this->latestSampleMetrics($allIds);

        $filtered = $all->filter(function ($row) use ($allMetrics, $metricFilters) {
            $metrics = $allMetrics[(int) $row->id] ?? null;

            foreach ($metricFilters as $metricKey => $min) {
                $value = $metrics[$metricKey] ?? null;

                if ($value === null || $value < $min) {
                    return false;
                }
            }

            return true;
        })->values();

        $total = $filtered->count();
        $stats = $this->statusStatsFromRows($filtered, $total);

        $pageRows = $perPage > 0 ? $filtered->forPage(max(1, $page), $perPage)->values() : $filtered;
        $pageIds = $pageRows->map(fn ($row) => (int) $row->id)->all();

        return [
            'rows' => $this->mergeLatest($pageRows),
            'total' => $total,
            'accounts' => $this->linkedAccounts($module),
            'stats' => $stats,
            'metrics' => array_intersect_key($allMetrics, array_flip($pageIds)),
        ];
    }

    /**
     * Same shape as statusStats(), computed in PHP over an already-fetched
     * collection — used by the metric-filtered path, which has no SQL
     * query left to aggregate against once filtering happens in PHP.
     *
     * @param  Collection<int, stdClass>  $rows
     * @return array{total: int, up: int, down: int, unknown: int, failing: int}
     */
    private function statusStatsFromRows(Collection $rows, int $total): array
    {
        return [
            'total' => $total,
            'up' => $rows->filter(fn ($r) => $r->status === 'up')->count(),
            'down' => $rows->filter(fn ($r) => $r->status === 'down')->count(),
            'unknown' => $rows->filter(fn ($r) => $r->status === 'unknown')->count(),
            'failing' => $rows->filter(fn ($r) => (int) ($r->consecutive_failures ?? 0) > 0)->count(),
        ];
    }

    /**
     * Status breakdown across the FULL filtered set (not just the current
     * page), for the listing summary cards. A single conditional-aggregate
     * query over the already-joined, already-filtered base — keeps the
     * whole index() call at one query per aggregate, matching the "fixed
     * handful of queries" contract the rest of this method holds to.
     *
     * @return array{total: int, up: int, down: int, unknown: int, failing: int}
     */
    private function statusStats(Builder $base, int $total): array
    {
        $row = (clone $base)
            ->selectRaw("SUM(CASE WHEN t.status = 'up' THEN 1 ELSE 0 END) as up")
            ->selectRaw("SUM(CASE WHEN t.status = 'down' THEN 1 ELSE 0 END) as down")
            ->selectRaw("SUM(CASE WHEN t.status = 'unknown' THEN 1 ELSE 0 END) as unknown")
            ->selectRaw('SUM(CASE WHEN t.consecutive_failures > 0 THEN 1 ELSE 0 END) as failing')
            ->first();

        return [
            'total' => $total,
            'up' => (int) ($row->up ?? 0),
            'down' => (int) ($row->down ?? 0),
            'unknown' => (int) ($row->unknown ?? 0),
            'failing' => (int) ($row->failing ?? 0),
        ];
    }

    /**
     * Compact per-host trend for listing sparklines: one grouped query
     * across ALL visible hosts (never one query per row), fixed
     * SPARKLINE_BUCKETS AVG points over the last hour.
     *
     * @param  list<int>  $targetIds
     * @return array<int, list<?float>>
     */
    public function sparklines(array $targetIds, string $metric = 'cpu_pct'): array
    {
        if ($targetIds === [] || ! isset(self::HOST_METRICS[$metric])) {
            return [];
        }

        $rangeSeconds = self::SPARKLINE_RANGE_SECONDS;
        $bucket = max(1, intdiv($rangeSeconds, self::SPARKLINE_BUCKETS));
        $toTs = now()->getTimestamp();
        $fromTs = $toTs - $rangeSeconds;
        $column = self::safeColumn($metric);
        $conn = $this->monitoring();
        $sqlite = $conn->getDriverName() === 'sqlite';
        $sessionOffset = $this->sessionTimezoneOffset();

        $placeholders = implode(',', array_fill(0, count($targetIds), '?'));

        $sql = 'SELECT host_id, '.self::bucketIndexExpr($sqlite, 'collected_at', $bucket, $sessionOffset).' AS b,'
            ." AVG({$column}) AS v_avg"
            .' FROM snmp_host_samples'
            ." WHERE host_id IN ({$placeholders}) AND collected_at >= ? AND collected_at <= ?"
            .' GROUP BY host_id, b ORDER BY host_id, b ASC';

        $rows = $conn->select($sql, [...$targetIds, self::dt($fromTs), self::dt($toTs)]);

        $fromIdx = intdiv($fromTs, $bucket);
        $toIdx = intdiv($toTs, $bucket);

        $byHost = [];

        foreach ($rows as $row) {
            $byHost[(int) $row->host_id][(int) $row->b] = $row->v_avg !== null ? (float) $row->v_avg : null;
        }

        $result = [];

        foreach ($targetIds as $id) {
            $points = [];

            for ($idx = $fromIdx; $idx <= $toIdx; $idx++) {
                $points[] = $byHost[$id][$idx] ?? null;
            }

            $result[$id] = $points;
        }

        return $result;
    }

    /**
     * The most recent snmp_host_samples row per host, as ready SQL-numeric
     * gauges (CPU/memory/disk %) — the snmp_latest JSON payload only carries
     * raw collector fields, not these computed percentages. One join query
     * for the WHOLE requested set (never one per row): a per-host MAX(collected_at)
     * subquery joined back onto the samples table to pick that row.
     *
     * @param  list<int>  $targetIds
     * @return array<int, array{cpu_pct: ?float, mem_pct: ?float, disk_pct: ?float, response_ms: ?int, collected_at: ?string}>
     */
    public function latestSampleMetrics(array $targetIds): array
    {
        if ($targetIds === []) {
            return [];
        }

        $conn = $this->monitoring();
        $placeholders = implode(',', array_fill(0, count($targetIds), '?'));

        $sql = 'SELECT s.host_id, s.collected_at, s.cpu_pct, s.mem_used_mb, s.mem_total_mb, s.storage_pct, s.response_ms'
            .' FROM snmp_host_samples s'
            .' INNER JOIN ('
            ."SELECT host_id, MAX(collected_at) AS max_at FROM snmp_host_samples WHERE host_id IN ({$placeholders}) GROUP BY host_id"
            .') latest ON latest.host_id = s.host_id AND latest.max_at = s.collected_at'
            ." WHERE s.host_id IN ({$placeholders})";

        $rows = $conn->select($sql, [...$targetIds, ...$targetIds]);

        $result = [];

        foreach ($rows as $row) {
            $hostId = (int) $row->host_id;

            // A millisecond tie on collected_at could join back onto two
            // rows for the same host; keep the first one seen.
            if (isset($result[$hostId])) {
                continue;
            }

            $memPct = ($row->mem_total_mb !== null && (float) $row->mem_total_mb > 0 && $row->mem_used_mb !== null)
                ? round((float) $row->mem_used_mb / (float) $row->mem_total_mb * 100, 1)
                : null;

            $result[$hostId] = [
                'cpu_pct' => $row->cpu_pct !== null ? (float) $row->cpu_pct : null,
                'mem_pct' => $memPct,
                'disk_pct' => $row->storage_pct !== null ? (float) $row->storage_pct : null,
                'response_ms' => $row->response_ms !== null ? (int) $row->response_ms : null,
                'collected_at' => $row->collected_at,
            ];
        }

        return $result;
    }

    /**
     * One host's dashboard payload: target row plus decoded snmp_latest
     * snapshot, guarded by the module-link check.
     *
     * @return array{account: HostingAccount, target: ?stdClass, latest: ?array<string, mixed>}
     */
    public function summary(HostingAccount $account): array
    {
        $this->assertLinked($account);

        $target = DB::table('snmp_targets')
            ->where('hosting_account_id', $account->id)
            ->first();

        $latest = null;

        if ($target !== null) {
            $row = $this->monitoring()->table('snmp_latest')->where('host_id', $target->id)->first();

            if ($row !== null) {
                $latest = [
                    'status' => $row->status,
                    'collected_at' => $row->collected_at,
                    'payload' => $this->decodePayload($row->payload),
                ];
            }
        }

        return ['account' => $account, 'target' => $target, 'latest' => $latest];
    }

    /**
     * Chart-ready series: {labels:[], datasets:[{label,data,min,max}]}. One
     * dataset per requested metric; labels form a continuous bucket grid
     * across the window so gaps stay visible as nulls instead of silently
     * compressing the time axis.
     *
     * @param  list<string>  $metrics
     * @return array{labels: list<string>, datasets: list<array<string, mixed>>}
     */
    public function series(HostingAccount $account, array $metrics, int $rangeSeconds): array
    {
        $this->assertLinked($account);

        $metrics = array_values(array_intersect($metrics, array_keys(self::METRICS)));

        if ($metrics === []) {
            return ['labels' => [], 'datasets' => []];
        }

        $targetId = (int) DB::table('snmp_targets')
            ->where('hosting_account_id', $account->id)
            ->value('id');

        if ($targetId === 0) {
            return ['labels' => [], 'datasets' => []];
        }

        $bucket = self::resolveBucket($rangeSeconds);
        $toTs = now()->getTimestamp();
        $fromTs = $toTs - $rangeSeconds;
        $hourlyTier = $rangeSeconds > self::HOURLY_TIER_THRESHOLD;

        // The datetime columns store wall-clock strings in the app timezone
        // (see dt()); UNIX_TIMESTAMP()/strftime('%s') interpret them in the
        // DB SESSION timezone. When the two disagree the SQL bucket indices
        // shift off the PHP label grid and every chart point lands outside
        // the window (empty charts on non-UTC installs). Compute the actual
        // session-vs-app offset once and correct the bucket expression.
        $sessionOffset = $this->sessionTimezoneOffset();

        $fromIdx = intdiv($fromTs, $bucket);
        $toIdx = intdiv($toTs, $bucket);

        $labels = [];
        for ($idx = $fromIdx; $idx <= $toIdx; $idx++) {
            $labels[] = Carbon::createFromTimestamp($idx * $bucket, config('app.timezone'))->format('Y-m-d H:i');
        }

        $datasets = [];

        foreach ($metrics as $metric) {
            $points = isset(self::IF_METRICS[$metric])
                ? $this->interfacePoints($targetId, $metric, $fromIdx, $toIdx, $bucket, $fromTs, $toTs, $sessionOffset)
                : $this->samplePoints($targetId, $metric, $fromIdx, $toIdx, $bucket, $fromTs, $toTs, $hourlyTier, $sessionOffset);

            $datasets[] = [
                'label' => self::METRICS[$metric],
                'data' => $points['avg'],
                'min' => $points['min'],
                'max' => $points['max'],
            ];
        }

        return ['labels' => $labels, 'datasets' => $datasets];
    }

    /**
     * Seconds by which the DB session timezone LAGS the app timezone:
     * UNIX_TIMESTAMP(wall_string) - app_epoch. 0 when they agree (the
     * common case). Never throws — a probe failure means "no correction".
     */
    private function sessionTimezoneOffset(): int
    {
        try {
            $wall = Carbon::now(config('app.timezone'))->format('Y-m-d H:i:s');
            $appEpoch = Carbon::now(config('app.timezone'))->getTimestamp();
            $conn = $this->monitoring();

            $expr = $conn->getDriverName() === 'sqlite'
                ? "SELECT CAST(strftime('%s', ?) AS INTEGER) AS u"
                : 'SELECT UNIX_TIMESTAMP(?) AS u';

            return (int) $conn->selectOne($expr, [$wall])->u - $appEpoch;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * The ACTIVE snmp-monitor module row (cached per instance), or null
     * when undiscovered/disabled — no data can be read without it.
     */
    private function module(): ?Module
    {
        if ($this->moduleCache instanceof Module) {
            return $this->moduleCache;
        }

        $module = $this->manager->find('snmp-monitor');

        if ($module === null || $module->status !== Module::STATUS_ACTIVE) {
            return null;
        }

        return $this->moduleCache = $module;
    }

    /**
     * AVG/MIN/MAX of one host-level column per bucket, from raw samples or
     * (beyond 30 days) the pre-aggregated hourly rollups.
     *
     * @return array{avg: list<?float>, min: list<?float>, max: list<?float>}
     */
    private function samplePoints(
        int $targetId,
        string $metric,
        int $fromIdx,
        int $toIdx,
        int $bucket,
        int $fromTs,
        int $toTs,
        bool $hourlyTier,
        int $sessionOffset = 0,
    ): array {
        $conn = $this->monitoring();
        $sqlite = $conn->getDriverName() === 'sqlite';

        if ($hourlyTier) {
            $sql = 'SELECT '.self::bucketIndexExpr(true, 'hour_start', $bucket, $sessionOffset).' AS b,'
                .' AVG(v_avg) AS v_avg, MIN(v_min) AS v_min, MAX(v_max) AS v_max'
                .' FROM snmp_metric_hourly'
                .' WHERE host_id = :host AND series = :series AND hour_start >= :from AND hour_start <= :to'
                .' GROUP BY b ORDER BY b ASC';

            $rows = $conn->select($sql, [
                'host' => $targetId,
                'series' => $metric,
                'from' => self::dt($fromTs),
                'to' => self::dt($toTs),
            ]);
        } else {
            $column = self::safeColumn($metric);

            $sql = 'SELECT '.self::bucketIndexExpr($sqlite, 'collected_at', $bucket, $sessionOffset).' AS b,'
                ." AVG({$column}) AS v_avg, MIN({$column}) AS v_min, MAX({$column}) AS v_max"
                .' FROM snmp_host_samples'
                .' WHERE host_id = :host AND collected_at >= :from AND collected_at <= :to'
                .' GROUP BY b ORDER BY b ASC';

            $rows = $conn->select($sql, [
                'host' => $targetId,
                'from' => self::dt($fromTs),
                'to' => self::dt($toTs),
            ]);
        }

        return $this->padPoints($rows, $fromIdx, $toIdx);
    }

    /**
     * SUM/MIN/MAX of one snmp_if_samples rate column per bucket, totaling
     * every interface of the host into a single series.
     *
     * @return array{avg: list<?float>, min: list<?float>, max: list<?float>}
     */
    private function interfacePoints(
        int $targetId,
        string $metric,
        int $fromIdx,
        int $toIdx,
        int $bucket,
        int $fromTs,
        int $toTs,
        int $sessionOffset = 0,
    ): array {
        $sqlite = $this->monitoring()->getDriverName() === 'sqlite';
        $column = self::safeColumn($metric);

        $sql = 'SELECT '.self::bucketIndexExpr($sqlite, 'collected_at', $bucket, $sessionOffset).' AS b,'
            ." SUM({$column}) AS v_avg, MIN({$column}) AS v_min, MAX({$column}) AS v_max"
            .' FROM snmp_if_samples'
            .' WHERE host_id = :host AND collected_at >= :from AND collected_at <= :to'
            .' GROUP BY b ORDER BY b ASC';

        $rows = $this->monitoring()->select($sql, [
            'host' => $targetId,
            'from' => self::dt($fromTs),
            'to' => self::dt($toTs),
        ]);

        return $this->padPoints($rows, $fromIdx, $toIdx);
    }

    /**
     * Floor-division bucket index over a datetime column:
     * FLOOR(UNIX_TIMESTAMP(col) / bucket) on MySQL; under sqlite the same
     * value via integer strftime division. $sessionOffset (seconds the DB
     * session tz lags the app tz — see sessionTimezoneOffset()) re-aligns
     * the SQL epoch with the PHP label grid.
     */
    private static function bucketIndexExpr(bool $sqlite, string $column, int $bucket, int $sessionOffset = 0): string
    {
        $correction = $sessionOffset === 0 ? '' : (' - '.$sessionOffset);

        if ($sqlite) {
            return "((CAST(strftime('%s', ".$column.') AS INTEGER)'.$correction.') / '.$bucket.')';
        }

        return 'FLOOR((UNIX_TIMESTAMP('.$column.')'.$correction.') / '.$bucket.')';
    }

    /**
     * Metric names never reach SQL unvalidated; belt-and-braces whitelist
     * against identifier injection.
     */
    private static function safeColumn(string $metric): string
    {
        if (! isset(self::METRICS[$metric]) || ! preg_match('/^[a-z0-9_]+$/', $metric)) {
            throw new \InvalidArgumentException("Unsupported metric [{$metric}].");
        }

        return $metric;
    }

    /**
     * Spread aggregate rows over the continuous label grid — null wherever
     * no data landed in a bucket.
     *
     * @param  list<stdClass>  $rows
     * @return array{avg: list<?float>, min: list<?float>, max: list<?float>}
     */
    private function padPoints(array $rows, int $fromIdx, int $toIdx): array
    {
        $byIndex = [];

        foreach ($rows as $row) {
            $byIndex[(int) $row->b] = $row;
        }

        $avg = [];
        $min = [];
        $max = [];

        for ($idx = $fromIdx; $idx <= $toIdx; $idx++) {
            $row = $byIndex[$idx] ?? null;

            $avg[] = $row === null || $row->v_avg === null ? null : (float) $row->v_avg;
            $min[] = $row === null || $row->v_min === null ? null : (float) $row->v_min;
            $max[] = $row === null || $row->v_max === null ? null : (float) $row->v_max;
        }

        return ['avg' => $avg, 'min' => $min, 'max' => $max];
    }

    /**
     * The cached snmp_latest row for one target, decoded for the hosting-page
     * card. The caller owns the product-link guard (the provider only runs
     * after the enabled-link check); this stays a target-scoped read.
     *
     * @return array{status: string, collected_at: string, payload: array<string, mixed>}|null
     */
    public function latest(SnmpTarget $target): ?array
    {
        $row = $this->monitoring()->table('snmp_latest')->where('host_id', $target->id)->first();

        if ($row === null) {
            return null;
        }

        return [
            'status' => (string) $row->status,
            'collected_at' => (string) $row->collected_at,
            'payload' => $this->decodePayload($row->payload),
        ];
    }

    /**
     * Attach the snmp_latest snapshot to each target row with ONE extra IN
     * query on the monitoring connection.
     *
     * @param  Collection<int, stdClass>  $rows
     * @return list<array{t: stdClass, latest: ?array<string, mixed>}>
     */
    private function mergeLatest($rows): array
    {
        $hostIds = $rows->pluck('id')->all();
        $latestByHost = [];

        if ($hostIds !== []) {
            foreach ($this->monitoring()->table('snmp_latest')->whereIn('host_id', $hostIds)->get() as $row) {
                $latestByHost[(int) $row->host_id] = [
                    'status' => $row->status,
                    'collected_at' => $row->collected_at,
                    'payload' => $this->decodePayload($row->payload),
                ];
            }
        }

        return $rows
            ->map(fn ($row) => ['t' => $row, 'latest' => $latestByHost[(int) $row->id] ?? null])
            ->all();
    }

    /**
     * Accounts carrying targets on linked products, for the filter dropdown.
     *
     * @return list<stdClass>
     */
    private function linkedAccounts(Module $module): array
    {
        return DB::table('snmp_targets as t')
            ->join('hosting_accounts as ha', 'ha.id', '=', 't.hosting_account_id')
            ->join('product_module as pm', 'pm.product_id', '=', 'ha.product_id')
            ->where('pm.module_id', $module->id)
            ->where('pm.enabled', true)
            ->distinct()
            ->orderBy('ha.username')
            ->get(['ha.id', 'ha.username'])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(?string $raw): array
    {
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Epoch seconds rendered in the application timeframe the polling
     * pipeline writes DATETIME(3) columns in. The app timezone is passed
     * explicitly: createFromTimestamp() defaults to the PHP ini timezone
     * (often UTC), while Carbon::now()/inserts use config('app.timezone') —
     * mixing the two silently shifts the query window and empties charts.
     */
    private static function dt(int $timestamp): string
    {
        return Carbon::createFromTimestamp($timestamp, config('app.timezone'))->format('Y-m-d H:i:s');
    }

    private function monitoring(): Connection
    {
        return DB::connection('monitoring');
    }
}
