<?php

declare(strict_types=1);

namespace Modules\SnmpMonitor\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\HostingAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\SnmpMonitor\Exceptions\UnlinkedAccountException;
use Modules\SnmpMonitor\Services\SnmpMetricRepository;

/**
 * Admin SNMP dashboard: global host listing plus per-host Chart.js views
 * backed by the JSON series endpoint. All reads go through
 * SnmpMetricRepository, which enforces the product-link isolation guard —
 * an unlinked account aborts with 403 before any monitoring query runs.
 */
final class DashboardController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(private readonly SnmpMetricRepository $repository) {}

    /**
     * Host listing with server-rendered GET filters (account, status,
     * hostname search) and simple pagination.
     */
    public function index(Request $request)
    {
        $filters = $this->filtersFrom($request);

        try {
            $data = $this->repository->index(
                $filters,
                self::PER_PAGE,
                max(1, (int) $request->query('page', '1')),
            );
        } catch (UnlinkedAccountException $exception) {
            abort(403, $exception->getMessage());
        }

        $targetIds = array_map(fn (array $row) => (int) $row['t']->id, $data['rows']);
        $data['sparklines'] = $this->repository->sparklines($targetIds);
        // The metric-filtered index() path already computed this page's
        // CPU/memory/disk gauges while filtering; only pay for a second
        // query when no threshold filter was applied.
        $data['metrics'] ??= $this->repository->latestSampleMetrics($targetIds);

        return view('snmp-monitor::dashboard', $data + ['filters' => $filters]);
    }

    /**
     * JSON refresh feed for the listing page's auto-refresh: same filters
     * as index(), but only the fields the page actually redraws (summary
     * cards + per-row status/last-poll/latest-sample). Sparklines are left
     * out — redrawn inline SVGs on every poll tick isn't worth the churn,
     * so the trend only updates on a full page load or filter change.
     */
    public function refresh(Request $request)
    {
        $filters = $this->filtersFrom($request);

        try {
            $data = $this->repository->index(
                $filters,
                self::PER_PAGE,
                max(1, (int) $request->query('page', '1')),
            );
        } catch (UnlinkedAccountException $exception) {
            abort(403, $exception->getMessage());
        }

        $targetIds = array_map(fn (array $row) => (int) $row['t']->id, $data['rows']);
        $metrics = $data['metrics'] ?? $this->repository->latestSampleMetrics($targetIds);

        return response()->json([
            'stats' => $data['stats'],
            'rows' => array_map(function (array $row) use ($metrics) {
                $target = $row['t'];
                $gauges = $metrics[(int) $target->id] ?? null;

                return [
                    'account_id' => (int) $target->hosting_account_id,
                    'status' => $target->status,
                    'consecutive_failures' => (int) ($target->consecutive_failures ?? 0),
                    // short: true must match the server-rendered cell in
                    // dashboard.blade.php, or the first auto-refresh tick
                    // silently swaps "3m ago" for "3 minutes ago" and re-wraps
                    // every row.
                    'last_polled_human' => $target->last_polled_at
                        ? Carbon::parse($target->last_polled_at)->diffForHumans(short: true)
                        : null,
                    'cpu_pct' => $gauges['cpu_pct'] ?? null,
                    'mem_pct' => $gauges['mem_pct'] ?? null,
                    'disk_pct' => $gauges['disk_pct'] ?? null,
                ];
            }, $data['rows']),
        ]);
    }

    /**
     * @return array{account: ?string, status: ?string, q: ?string, cpu_min: ?string, mem_min: ?string, disk_min: ?string}
     */
    private function filtersFrom(Request $request): array
    {
        return [
            'account' => $request->query('account'),
            'status' => $request->query('status'),
            'q' => $request->query('q'),
            'cpu_min' => $request->query('cpu_min'),
            'mem_min' => $request->query('mem_min'),
            'disk_min' => $request->query('disk_min'),
        ];
    }

    /**
     * Per-host chart page. The GET filter form (metric multiselect +
     * time range) is fully server-rendered; Chart.js fetches the series
     * endpoint client-side, so page loads never trigger polling work.
     */
    public function show(Request $request, HostingAccount $hostingAccount)
    {
        try {
            $summary = $this->repository->summary($hostingAccount);
        } catch (UnlinkedAccountException $exception) {
            abort(403, $exception->getMessage());
        }

        return view('snmp-monitor::partials.host-charts', $summary + [
            'metricCatalog' => SnmpMetricRepository::METRICS,
            'ranges' => SnmpMetricRepository::RANGES,
            'selectedMetrics' => $this->selectedMetrics($request),
            'selectedRange' => $this->selectedRange($request),
            'seriesEndpoint' => route('admin.snmp-monitor.series', $hostingAccount),
            'dashboardUrl' => route('admin.snmp-monitor.dashboard'),
        ]);
    }

    /**
     * JSON series feed: {labels:[], datasets:[{label,data,min,max}]}.
     *
     * Input is validated explicitly instead of via $request->validate():
     * bootstrap/app.php renders validation exceptions as redirects for
     * non-api paths, while this endpoint must answer malformed metrics /
     * range with a machine-readable 422 JSON payload, never a redirect or
     * a 500. Unlinked accounts abort 403 inside the repository guard.
     */
    public function series(Request $request, HostingAccount $hostingAccount)
    {
        $validator = Validator::make($request->query(), [
            'metrics' => ['required', 'array', 'min:1'],
            'metrics.*' => ['string', Rule::in(array_keys(SnmpMetricRepository::METRICS))],
            'range' => ['required', 'string', Rule::in(array_keys(SnmpMetricRepository::RANGES))],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        try {
            $payload = $this->repository->series(
                $hostingAccount,
                array_values((array) $validated['metrics']),
                SnmpMetricRepository::RANGES[$validated['range']],
            );
        } catch (UnlinkedAccountException $exception) {
            abort(403, $exception->getMessage());
        }

        return response()->json($payload);
    }

    /**
     * Metric selection sanitized against the catalog; falls back to CPU %.
     *
     * @return list<string>
     */
    private function selectedMetrics(Request $request): array
    {
        $selected = collect((array) $request->query('metrics', ['cpu_pct']))
            ->map(fn ($metric) => (string) $metric)
            ->intersect(array_keys(SnmpMetricRepository::METRICS))
            ->values()
            ->all();

        return $selected === [] ? ['cpu_pct'] : $selected;
    }

    private function selectedRange(Request $request): string
    {
        $range = (string) $request->query('range', '24h');

        return isset(SnmpMetricRepository::RANGES[$range]) ? $range : '24h';
    }
}
