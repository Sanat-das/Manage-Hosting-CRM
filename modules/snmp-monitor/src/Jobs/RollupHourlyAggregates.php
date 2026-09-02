<?php

declare(strict_types=1);

namespace Modules\SnmpMonitor\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Hourly rollup: aggregates the PREVIOUS completed hour of snmp_host_samples
 * into snmp_metric_hourly (one row per host and series) via a bulk
 * INSERT ... ON DUPLICATE KEY UPDATE (Laravel upsert()), so scheduler/worker
 * overlaps are idempotent — reruns refresh the same rows instead of failing
 * on the primary key or duplicating history.
 *
 * Aggregations ignore NULL metric cells (SQL AVG/MIN/MAX/COUNT semantics):
 * hosts that were unreachable part of the hour leave genuine GAPS in the
 * rolled-up series — they are excluded from samples count, never zeroed,
 * and a series with no non-null value in the window produces no row at all.
 *
 * Operations contract: runs on the snmp-poll queue five minutes past every
 * hour (`hourlyAt(':05')`) through the same worker as PollHostBatch.
 */
class RollupHourlyAggregates implements ShouldQueue
{
    use Dispatchable;

    /** @var string */
    public $queue = 'snmp-poll';

    /** @var int */
    public $tries = 1;

    /** @var int */
    public $timeout = 300;

    /**
     * Rolled-up series => snmp_host_samples column. Whitelist, never user input.
     */
    private const SERIES_COLUMNS = [
        'cpu_pct' => 'cpu_pct',
        'cpu_load1' => 'cpu_load1',
        'cpu_load5' => 'cpu_load5',
        'cpu_load15' => 'cpu_load15',
        'mem_total_mb' => 'mem_total_mb',
        'mem_used_mb' => 'mem_used_mb',
        'storage_pct' => 'storage_pct',
    ];

    public function handle(): void
    {
        $windowEnd = Carbon::now()->startOfHour();
        $windowStart = $windowEnd->copy()->subHour();

        $selects = ['host_id'];

        foreach (self::SERIES_COLUMNS as $series => $column) {
            $selects[] = "AVG(`{$column}`) as `{$series}_avg`";
            $selects[] = "MIN(`{$column}`) as `{$series}_min`";
            $selects[] = "MAX(`{$column}`) as `{$series}_max`";
            $selects[] = "COUNT(`{$column}`) as `{$series}_samples`";
        }

        $aggregates = DB::connection('monitoring')->table('snmp_host_samples')
            ->where('collected_at', '>=', $windowStart->format('Y-m-d H:i:s'))
            ->where('collected_at', '<', $windowEnd->format('Y-m-d H:i:s'))
            ->groupBy('host_id')
            ->selectRaw(implode(', ', $selects))
            ->get();

        $rows = [];

        foreach ($aggregates as $aggregate) {
            foreach (self::SERIES_COLUMNS as $series => $column) {
                $samples = (int) $aggregate->{$series.'_samples'};

                if ($samples === 0) {
                    continue;
                }

                $rows[] = [
                    'host_id' => (int) $aggregate->host_id,
                    'series' => $series,
                    'hour_start' => $windowStart->format('Y-m-d H:i:s'),
                    'v_avg' => round((float) $aggregate->{$series.'_avg'}, 6),
                    'v_min' => round((float) $aggregate->{$series.'_min'}, 6),
                    'v_max' => round((float) $aggregate->{$series.'_max'}, 6),
                    'samples' => min($samples, 32767),
                ];
            }
        }

        if ($rows === []) {
            return;
        }

        DB::connection('monitoring')->table('snmp_metric_hourly')->upsert(
            $rows,
            ['host_id', 'series', 'hour_start'],
            ['v_avg', 'v_min', 'v_max', 'samples'],
        );
    }
}
