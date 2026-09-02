<?php

declare(strict_types=1);

namespace Modules\SnmpMonitor\Jobs;

use App\Models\Module;
use App\Services\Modules\ModuleManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\SnmpMonitor\Exceptions\SnmpException;
use Modules\SnmpMonitor\Models\SnmpTarget;
use Modules\SnmpMonitor\Services\SnmpCollector;
use Modules\SnmpMonitor\Services\TargetService;
use Throwable;

/**
 * Scheduled batch poller: collects one due group of SNMP targets per job on
 * the snmp-poll queue. Every target is isolated in its own try/catch — one
 * unreachable host never fails the batch, it only increments that target's
 * consecutive_failures (status flips to 'down' at three in a row) while its
 * next_poll_at still advances so a dead host is retried at the normal cadence.
 *
 * Successful polls write one snmp_host_samples row plus per-interface
 * snmp_if_samples rows on the monitoring connection. Interface rates are
 * derived against the prior snmp_latest payload and stored as bits per
 * second; they are NULL whenever they would be fabricated — first poll,
 * a counter that decreased (agent reboot / wrap without 64-bit counters)
 * or a collection gap wider than three effective intervals.
 *
 * Operations contract: this job only runs when BOTH a scheduler tick
 * (`php artisan schedule:run` every minute — Windows Task Scheduler or cron)
 * and a queue worker (`php artisan queue:work --queue=snmp-poll --tries=1
 * --timeout=120 --max-jobs=500 --max-time=3600`) are running.
 */
class PollHostBatch implements ShouldQueue
{
    use Dispatchable;

    /** @var string */
    public $queue = 'snmp-poll';

    /** @var int */
    public $tries = 1;

    /** @var int */
    public $timeout = 120;

    /** Targets per dispatched batch job. */
    private const CHUNK_SIZE = 25;

    private const MODULE_SLUG = 'snmp-monitor';

    /** Global fallback when neither the target nor its product config set an interval (seconds). */
    private const DEFAULT_INTERVAL_SECONDS = 300;

    /** Floor for any configured interval — below the scheduler's own 1-minute tick is meaningless. */
    private const MIN_INTERVAL_SECONDS = 60;

    /** Consecutive failures before status flips to 'down'. */
    private const DOWN_AFTER_FAILURES = 3;

    /** Rates are only computed for gaps up to RATE_GAP_FACTOR × interval. */
    private const RATE_GAP_FACTOR = 3;

    /**
     * @param  int[]  $targetIds
     */
    public function __construct(public readonly array $targetIds) {}

    /**
     * Dispatch one PollHostBatch per chunk of 25 enabled targets whose next
     * scheduled poll is due (or was never scheduled). Each target's
     * next_poll_at is advanced by its own effective interval BEFORE
     * dispatching, claiming the row for a full cadence: stamping it with
     * `now` would still satisfy the `next_poll_at <= now` predicate, so the
     * following tick re-queued the same host — one duplicate job per minute
     * for as long as the queue worker lagged. The batch re-stamps
     * next_poll_at on both success and failure, so the real cadence stays
     * measured from the poll itself.
     *
     * Returns the number of targets handed to the queue.
     */
    public static function dispatchDue(): int
    {
        $now = Carbon::now();
        $queued = 0;

        $manager = app(ModuleManager::class);
        $module = $manager->find(self::MODULE_SLUG);

        SnmpTarget::query()
            ->with('hostingAccount.product.moduleLinks')
            ->where('enabled', true)
            ->where(function ($query) use ($now): void {
                $query->whereNull('next_poll_at')->orWhere('next_poll_at', '<=', $now);
            })
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($targets) use (&$queued, $now, $manager, $module): void {
                // Grouped by cadence so a chunk of targets sharing one
                // interval (the common case) still costs a single UPDATE.
                $claims = [];

                foreach ($targets as $target) {
                    $interval = self::effectiveIntervalSeconds($target, self::configFor($target, $manager, $module));
                    $claims[$interval][] = $target->id;
                }

                foreach ($claims as $interval => $claimedIds) {
                    SnmpTarget::query()
                        ->whereIn('id', $claimedIds)
                        ->update(['next_poll_at' => $now->copy()->addSeconds($interval)]);
                }

                $ids = $targets->modelKeys();

                static::dispatch($ids);

                $queued += count($ids);
            });

        return $queued;
    }

    public function handle(ModuleManager $manager): void
    {
        if ($this->targetIds === []) {
            return;
        }

        $module = $manager->find(self::MODULE_SLUG);

        $targets = SnmpTarget::query()
            ->with('hostingAccount.product.moduleLinks')
            ->whereIn('id', $this->targetIds)
            ->get();

        foreach ($targets as $target) {
            try {
                $this->pollTarget($target, $manager, $module);
            } catch (Throwable $e) {
                Log::warning('SNMP poll failed.', [
                    'target_id' => $target->id,
                    'account_id' => $target->hosting_account_id,
                    'host' => $target->host,
                    'error' => $e->getMessage(),
                ]);

                $this->recordFailure($target, $manager, $module);
            }
        }
    }

    private function pollTarget(SnmpTarget $target, ModuleManager $manager, ?Module $module): void
    {
        $host = trim((string) $target->host);
        $resolvedHost = null;

        if ($host === '') {
            // Auto-resolve once a lease appears; per-hosting-account explicit
            // host (set via the hosting page IP selector) wins over this.
            $resolved = app(TargetService::class)->resolveForAccount($target->hostingAccount);
            $resolvedHost = trim((string) ($resolved['host'] ?? ''));
            $host = $resolvedHost;
        }

        if ($host === '') {
            throw new SnmpException("No resolvable SNMP host for target [{$target->id}].");
        }

        // Persist the auto-resolved host so the target row heals from null
        // after the first IP lease appears.
        if ($resolvedHost !== null && $resolvedHost !== '' && trim((string) $target->host) === '') {
            $target->forceFill(['host' => $resolvedHost])->save();
        }

        $config = self::configFor($target, $manager, $module);
        $intervalSeconds = self::effectiveIntervalSeconds($target, $config);

        $startedAt = hrtime(true);

        $payload = app(SnmpCollector::class)->collect(
            $host,
            $config + ['target_os' => $target->target_os],
        );

        $responseMs = (int) ((hrtime(true) - $startedAt) / 1e6);

        $this->persistSamples($target, $payload, Carbon::now(), $responseMs, $intervalSeconds);
        $this->recordSuccess($target, $intervalSeconds, $responseMs);
    }

    /**
     * The product module-link config with encrypted fields decrypted through
     * the module manager (never read raw from the pivot).
     *
     * @return array<string, mixed>
     */
    private static function configFor(SnmpTarget $target, ModuleManager $manager, ?Module $module): array
    {
        if ($module === null) {
            return [];
        }

        $link = $target->hostingAccount?->product?->moduleLinks
            ->firstWhere('module_id', $module->id);

        if ($link === null || ! $link->enabled) {
            return [];
        }

        return $manager->decryptConfig($module, $link->config ?? []);
    }

    /**
     * Effective poll cadence, in order: an explicit per-host override
     * (snmp_targets.poll_interval) wins, then the product's configured
     * default, then the global fallback. Either configured tier is floored
     * at MIN_INTERVAL_SECONDS so a bad value can never out-pace the
     * scheduler's own 1-minute tick.
     *
     * @param  array<string, mixed>  $config
     */
    private static function effectiveIntervalSeconds(SnmpTarget $target, array $config): int
    {
        if ($target->poll_interval !== null) {
            return max(self::MIN_INTERVAL_SECONDS, (int) $target->poll_interval);
        }

        if (isset($config['poll_interval']) && is_numeric($config['poll_interval'])) {
            return max(self::MIN_INTERVAL_SECONDS, (int) $config['poll_interval']);
        }

        return self::DEFAULT_INTERVAL_SECONDS;
    }

    /**
     * One snmp_host_samples row + one snmp_if_samples row per interface +
     * the snmp_latest payload upsert, all on the monitoring connection.
     */
    private function persistSamples(
        SnmpTarget $target,
        array $payload,
        Carbon $now,
        int $responseMs,
        int $intervalSeconds,
    ): void {
        $monitoring = DB::connection('monitoring');
        // datetime(3) columns — keep millisecond precision so two polls in
        // the same second (duplicate queued jobs) never collide on the
        // (host_id, collected_at) primary key.
        $collectedAt = $now->format('Y-m-d H:i:s.v');

        $prior = $monitoring->table('snmp_latest')->where('host_id', $target->id)->first();
        $priorPayload = $prior !== null ? json_decode((string) $prior->payload, true) : null;
        $priorAt = $prior?->collected_at !== null ? Carbon::parse($prior->collected_at) : null;

        $gapSeconds = $priorAt !== null ? max(0, $now->getTimestamp() - $priorAt->getTimestamp()) : null;
        $rateHorizonSeconds = self::RATE_GAP_FACTOR * $intervalSeconds;
        $priorInterfaces = [];

        foreach ((array) ($priorPayload['interfaces'] ?? []) as $interface) {
            if (isset($interface['index'])) {
                $priorInterfaces[(int) $interface['index']] = $interface;
            }
        }

        // Upsert (not insert): a duplicate queued job re-polling the same
        // millisecond overwrites the row instead of crashing the whole poll
        // on a primary-key violation.
        $monitoring->table('snmp_host_samples')->upsert([
            'host_id' => $target->id,
            'collected_at' => $collectedAt,
            'uptime_secs' => $this->uptimeSeconds($payload['uptime_human'] ?? null),
            'cpu_load1' => ($payload['cpu_source'] ?? null) === 'ucd-laLoad' ? $payload['cpu_load'] ?? null : null,
            'cpu_load5' => null,
            'cpu_load15' => null,
            'cpu_pct' => isset($payload['cpu_load'])
                && (($payload['cpu_source'] ?? null) !== 'ucd-laLoad')
                ? (float) $payload['cpu_load']
                : null,
            'cpu_source' => $payload['cpu_source'] ?? null,
            'mem_total_mb' => isset($payload['memory_total_mb']) ? (int) $payload['memory_total_mb'] : null,
            'mem_used_mb' => isset($payload['memory_used_mb']) ? (int) $payload['memory_used_mb'] : null,
            'storage_pct' => $this->storagePct((array) ($payload['disks'] ?? [])),
            'proc_count' => isset($payload['processes']) ? count($payload['processes']) : null,
            'response_ms' => $responseMs,
        ], ['host_id', 'collected_at'], [
            'uptime_secs', 'cpu_load1', 'cpu_load5', 'cpu_load15', 'cpu_pct', 'cpu_source',
            'mem_total_mb', 'mem_used_mb', 'storage_pct', 'proc_count', 'response_ms',
        ]);

        $interfaceRows = [];
        $latestInterfaces = [];

        foreach ((array) ($payload['interfaces'] ?? []) as $interface) {
            $index = (int) ($interface['index'] ?? 0);
            $inOctets = $this->intOrNull($interface['inOctets'] ?? $interface['in_octets'] ?? null);
            $outOctets = $this->intOrNull($interface['outOctets'] ?? $interface['out_octets'] ?? null);
            $priorInterface = $priorInterfaces[$index] ?? null;

            $inRate = $this->bpsRate(
                $this->intOrNull($priorInterface['inOctets'] ?? $priorInterface['in_octets'] ?? null),
                $inOctets,
                $gapSeconds,
                $rateHorizonSeconds,
            );
            $outRate = $this->bpsRate(
                $this->intOrNull($priorInterface['outOctets'] ?? $priorInterface['out_octets'] ?? null),
                $outOctets,
                $gapSeconds,
                $rateHorizonSeconds,
            );

            $interfaceRows[] = [
                'host_id' => $target->id,
                'if_index' => $index,
                'collected_at' => $collectedAt,
                'in_octets' => $inOctets,
                'out_octets' => $outOctets,
                'in_bps' => $inRate,
                'out_bps' => $outRate,
            ];

            $latestInterfaces[] = $interface + ['in_bps' => $inRate, 'out_bps' => $outRate];
        }

        if ($interfaceRows !== []) {
            $monitoring->table('snmp_if_samples')->upsert(
                $interfaceRows,
                ['host_id', 'if_index', 'collected_at'],
                ['in_octets', 'out_octets', 'in_bps', 'out_bps'],
            );
        }

        $latestPayload = $payload;
        $latestPayload['interfaces'] = $latestInterfaces;

        $monitoring->table('snmp_latest')->upsert(
            [[
                'host_id' => $target->id,
                'collected_at' => $collectedAt,
                'payload' => json_encode($latestPayload, JSON_UNESCAPED_UNICODE),
                'status' => SnmpTarget::STATUS_UP,
            ]],
            'host_id',
            ['collected_at', 'payload', 'status'],
        );
    }

    /**
     * Bits per second between two counter readings; NULL whenever a rate
     * would be fabricated: no prior reading, non-positive or too-wide gap,
     * missing counters or a decreased (wrapped/reset) counter.
     */
    private function bpsRate(?int $priorOctets, ?int $octets, ?float $gapSeconds, int $horizonSeconds): ?float
    {
        if ($priorOctets === null || $octets === null || $gapSeconds === null) {
            return null;
        }

        if ($gapSeconds <= 0 || $gapSeconds > $horizonSeconds) {
            return null;
        }

        if ($octets < $priorOctets) {
            return null;
        }

        return round((($octets - $priorOctets) * 8) / $gapSeconds, 2);
    }

    private function recordSuccess(SnmpTarget $target, int $intervalSeconds, int $responseMs): void
    {
        $target->forceFill([
            'consecutive_failures' => 0,
            'status' => SnmpTarget::STATUS_UP,
            'last_polled_at' => Carbon::now(),
            'last_response_ms' => $responseMs,
            'next_poll_at' => Carbon::now()->addSeconds($intervalSeconds),
        ])->save();
    }

    private function recordFailure(SnmpTarget $target, ModuleManager $manager, ?Module $module): void
    {
        $failures = $target->consecutive_failures + 1;
        $intervalSeconds = self::effectiveIntervalSeconds($target, self::configFor($target, $manager, $module));

        $target->forceFill([
            'consecutive_failures' => $failures,
            'status' => $failures >= self::DOWN_AFTER_FAILURES ? SnmpTarget::STATUS_DOWN : $target->status,
            'last_polled_at' => Carbon::now(),
            'next_poll_at' => Carbon::now()->addSeconds($intervalSeconds),
        ])->save();
    }

    /**
     * Parse "32 days, 20:45:30" / "4:12:33" back into total seconds.
     */
    private function uptimeSeconds(mixed $human): ?int
    {
        if (! is_string($human)) {
            return null;
        }

        if (preg_match('/^(?:(\d+) days,\s*)?(\d+):(\d{2}):(\d{2})$/', trim($human), $matches)) {
            return ((int) $matches[1]) * 86400
                + ((int) $matches[2]) * 3600
                + ((int) $matches[3]) * 60
                + (int) $matches[4];
        }

        return null;
    }

    /**
     * Aggregate disk usage as a percentage; NULL when no capacity is known.
     *
     * @param  list<array<string, mixed>>  $disks
     */
    private function storagePct(array $disks): ?float
    {
        $totalGb = array_sum(array_column($disks, 'total_gb'));

        if ($totalGb <= 0) {
            return null;
        }

        return round(array_sum(array_column($disks, 'used_gb')) / $totalGb * 100, 2);
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
