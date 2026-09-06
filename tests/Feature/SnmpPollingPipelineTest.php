<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Modules\ModuleManager;
use FreeDSx\Snmp\Oid;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Modules\SnmpMonitor\Jobs\PollHostBatch;
use Modules\SnmpMonitor\Jobs\RollupHourlyAggregates;
use Modules\SnmpMonitor\Models\SnmpTarget;
use Tests\Support\InteractsWithSnmpMonitorModule;
use Tests\TestCase;

// Worktrees share one composer vendor junction, so the autoloader resolves
// Tests\ against another checkout; load this suite's support trait directly.
require_once __DIR__.'/../Support/InteractsWithSnmpMonitorModule.php';

/**
 * Plan task 5 — polling pipeline: scheduler dispatch math, queued batch
 * collection with per-target isolation, interface rate derivation against
 * the prior snmp_latest payload, failure bookkeeping, hourly rollups and
 * the schedule/queue configuration contract.
 *
 * Every SNMP interaction runs against the container-bound scripted client —
 * no network, no sleeps; time is controlled with Carbon::setTestNow().
 */
final class SnmpPollingPipelineTest extends TestCase
{
    use InteractsWithSnmpMonitorModule;
    use RefreshDatabase;

    /**
     * Swap the scripted ifTable walk for a fresh snapshot of counters —
     * the fixture walks are immutable once built.
     *
     * @param  object  $fake  The anonymous scripted SnmpClient from the trait.
     */
    private function swapIfTable(
        object $fake,
        int $loIn,
        int $loOut,
        int $eth0In,
        int $eth0Out,
    ): void {
        $fake->walks['1.3.6.1.2.1.2.2.1'] = $this->fakeSnmpWalk([
            Oid::fromInteger('1.3.6.1.2.1.2.2.1.1.1', 1),
            Oid::fromString('1.3.6.1.2.1.2.2.1.2.1', 'lo'),
            Oid::fromInteger('1.3.6.1.2.1.2.2.1.10.1', $loIn),
            Oid::fromInteger('1.3.6.1.2.1.2.2.1.16.1', $loOut),
            Oid::fromInteger('1.3.6.1.2.1.2.2.1.1.2', 2),
            Oid::fromString('1.3.6.1.2.1.2.2.1.2.2', 'eth0'),
            Oid::fromInteger('1.3.6.1.2.1.2.2.1.10.2', $eth0In),
            Oid::fromInteger('1.3.6.1.2.1.2.2.1.16.2', $eth0Out),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerSnmpMonitorAutoloader();
        $this->ensureSnmpMonitoringTables(app(ModuleManager::class));
    }

    // ------------------------------------------------------------------
    // dispatchDue(): enabled + due targets only, chunks of 25, one job per
    // chunk, next_poll_at claimed for a full cadence BEFORE dispatch.
    // ------------------------------------------------------------------

    public function test_dispatch_due_chunks_thirty_targets_into_two_jobs_and_stamps_next_poll_at(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-25 10:00:00'));

        $manager = app(ModuleManager::class);
        $module = $this->activateSnmpMonitorModule($manager);
        $product = $this->makeMonitoredProduct($manager, $module);

        $dueIds = [];
        for ($i = 0; $i < 30; $i++) {
            // snmp_targets allows exactly one row per hosting account.
            $dueIds[] = $this->makeTarget(
                $this->makeAccount($product),
                ['host' => "192.0.2.{$i}"]
            )->id;
        }

        $disabled = $this->makeTarget($this->makeAccount($product), ['host' => '192.0.9.1', 'enabled' => false]);
        $scheduled = $this->makeTarget($this->makeAccount($product), ['host' => '192.0.9.2', 'next_poll_at' => now()->addMinutes(10)]);
        // No per-host override: the claim must fall through to the product
        // config / global default tier (300s here), not to the 60s floor.
        $inherited = $this->makeTarget($this->makeAccount($product), ['host' => '192.0.9.3', 'poll_interval' => null]);

        $dispatched = PollHostBatch::dispatchDue();

        $this->assertSame(31, $dispatched);
        Queue::assertPushedOn('snmp-poll', PollHostBatch::class);
        Queue::assertPushed(PollHostBatch::class, 2);

        $sizes = [];
        Queue::assertPushed(PollHostBatch::class, function (PollHostBatch $job) use (&$sizes): bool {
            $sizes[] = count($job->targetIds);

            return true;
        });
        sort($sizes);
        $this->assertSame([6, 25], $sizes, 'One job per 25-target chunk is required.');

        foreach ($dueIds as $id) {
            $target = SnmpTarget::query()->findOrFail($id);
            $this->assertNotNull($target->next_poll_at, "Target [{$id}] must be stamped before dispatch.");
            // Claimed for its own cadence (poll_interval=60), NOT for `now` —
            // a `now` stamp is still due on the very next tick, which
            // re-queued the host once a minute whenever the worker lagged.
            $this->assertSame(
                now()->addSeconds(60)->getTimestamp(),
                $target->next_poll_at->getTimestamp(),
                "Target [{$id}] must be claimed for a full interval before dispatch."
            );
        }

        $this->assertSame(
            now()->addSeconds(300)->getTimestamp(),
            $inherited->fresh()->next_poll_at->getTimestamp(),
            'A target without an override is claimed for the inherited default cadence.'
        );

        $this->assertNull($disabled->fresh()->next_poll_at, 'Disabled targets must not be dispatched or stamped.');
        $this->assertTrue(
            $scheduled->fresh()->next_poll_at->equalTo(now()->addMinutes(10)),
            'Not-yet-due targets must keep their original schedule.'
        );
    }

    public function test_dispatch_due_does_not_requeue_a_claimed_target_on_the_next_tick(): void
    {
        Queue::fake();
        Carbon::setTestNow($t0 = Carbon::parse('2026-08-25 10:00:00'));

        $manager = app(ModuleManager::class);
        $module = $this->activateSnmpMonitorModule($manager);
        $product = $this->makeMonitoredProduct($manager, $module);
        $target = $this->makeTarget($this->makeAccount($product), ['poll_interval' => 300]);

        $this->assertSame(1, PollHostBatch::dispatchDue());

        // A minute later the queue worker still has not run the batch: the
        // claim must hold, or every tick piles up another duplicate job.
        Carbon::setTestNow($t0->copy()->addMinute());
        $this->assertSame(0, PollHostBatch::dispatchDue());
        Queue::assertPushed(PollHostBatch::class, 1);

        // Once the claimed cadence elapses the target is due again.
        Carbon::setTestNow($t0->copy()->addSeconds(300));
        $this->assertSame(1, PollHostBatch::dispatchDue());
        Queue::assertPushed(PollHostBatch::class, 2);

        $this->assertSame(
            $t0->copy()->addSeconds(600)->getTimestamp(),
            $target->fresh()->next_poll_at->getTimestamp(),
            'The re-claim advances by one further interval.'
        );
    }

    public function test_dispatch_due_returns_zero_when_nothing_is_due(): void
    {
        Queue::fake();

        $this->assertSame(0, PollHostBatch::dispatchDue());
        Queue::assertNothingPushed();
    }

    // ------------------------------------------------------------------
    // Happy path: one collect() per target with decrypted product config +
    // merged target_os; host sample row, per-interface rows and latest
    // upsert on the monitoring connection; target bookkeeping reset.
    // ------------------------------------------------------------------

    public function test_successful_poll_writes_samples_rates_and_latest_upsert(): void
    {
        Carbon::setTestNow($t0 = Carbon::parse('2026-08-25 10:00:00'));

        $manager = app(ModuleManager::class);
        $module = $this->activateSnmpMonitorModule($manager);
        $product = $this->makeMonitoredProduct($manager, $module);
        $target = $this->makeTarget(
            $this->makeAccount($product),
            ['consecutive_failures' => 2, 'status' => SnmpTarget::STATUS_UNKNOWN]
        );

        $fake = $this->fakeSnmpClient();
        $collector = $this->bindCapturingCollector($fake);

        (new PollHostBatch([$target->id]))->handle(app(ModuleManager::class));

        // Config contract: decryptConfig output + target_os merge.
        $this->assertCount(1, $collector->calls);
        [$host, $config] = $collector->calls[0];
        $this->assertSame('192.0.2.50', $host);
        $this->assertSame('linux', $config['target_os']);
        $this->assertSame('pub-community', $config['snmp_community']);
        $this->assertSame('s3cret-pass', $config['snmp_auth_password'], 'Encrypted pivot fields must arrive decrypted.');

        // Host sample row.
        $sample = $this->monitoring()->table('snmp_host_samples')->where('host_id', $target->id)->first();
        $this->assertNotNull($sample, 'One snmp_host_samples row must be written.');
        $this->assertSame(0.42, (float) $sample->cpu_load1);
        $this->assertNull($sample->cpu_pct, 'UCD laLoad source maps to cpu_load1, never to a fake percentage.');
        $this->assertSame('ucd-laLoad', $sample->cpu_source);
        $this->assertSame(4096, (int) $sample->mem_total_mb);
        $this->assertSame(2048, (int) $sample->mem_used_mb);
        $this->assertSame(50.0, round((float) $sample->storage_pct, 1));
        $this->assertNotNull($sample->response_ms);
        $this->assertSame(2839530, (int) $sample->uptime_secs);

        // Interface rows: counters stored, rates NULL on the first poll.
        $ifRows = $this->monitoring()->table('snmp_if_samples')
            ->where('host_id', $target->id)->orderBy('if_index')->get();
        $this->assertCount(2, $ifRows);
        $lo = $ifRows[0];
        $eth0 = $ifRows[1];
        $this->assertSame(1, (int) $lo->if_index);
        $this->assertSame(500, (int) $lo->in_octets);
        $this->assertNull($lo->in_bps, 'First poll has no prior payload so every rate is NULL.');
        $this->assertNull($eth0->out_bps);

        // Latest upsert.
        $latest = $this->monitoring()->table('snmp_latest')->where('host_id', $target->id)->first();
        $this->assertNotNull($latest);
        $payload = json_decode((string) $latest->payload, true);
        $this->assertSame('LINUX-VPS-01', $payload['hostname']);
        $this->assertSame('up', $latest->status);
        $this->assertSame(1000, (int) $payload['interfaces'][1]['inOctets']);

        // Target bookkeeping: reset + interval advance (poll_interval=60).
        $target = $target->fresh();
        $this->assertSame(SnmpTarget::STATUS_UP, $target->status);
        $this->assertSame(0, $target->consecutive_failures);
        $this->assertSame((int) $t0->getTimestamp(), (int) $target->last_polled_at->getTimestamp());
        $this->assertSame($t0->copy()->addSeconds(60)->getTimestamp(), $target->next_poll_at->getTimestamp());

        // Second poll 30s later with grown counters: rates appear. The
        // scripted walk is immutable, so swap in a new ifTable snapshot.
        Carbon::setTestNow($t0->copy()->addSeconds(30));
        $this->swapIfTable($fake, loIn: 700, loOut: 900, eth0In: 16000, eth0Out: 26000);

        (new PollHostBatch([$target->id]))->handle(app(ModuleManager::class));

        $eth0Second = $this->monitoring()->table('snmp_if_samples')
            ->where('host_id', $target->id)->where('if_index', 2)
            ->orderByDesc('collected_at')->first();
        $this->assertSame(16000, (int) $eth0Second->in_octets);
        $this->assertSame(4000.0, round((float) $eth0Second->in_bps, 0), '15000 octets over 30s = 4000 bps.');
        $this->assertSame(6400.0, round((float) $eth0Second->out_bps, 0), '24000 octets over 30s = 6400 bps.');

        $payloadSecond = json_decode(
            (string) $this->monitoring()->table('snmp_latest')->where('host_id', $target->id)->value('payload'),
            true,
        );
        // Whole-number rates may round-trip through JSON as ints.
        $this->assertSame(4000.0, (float) $payloadSecond['interfaces'][1]['in_bps']);
    }

    // ------------------------------------------------------------------
    // Interval resolution: explicit per-host override wins, then the
    // product's configured default, then the global fallback (300s).
    // ------------------------------------------------------------------

    public function test_interval_uses_product_config_when_target_override_is_null(): void
    {
        Carbon::setTestNow($t0 = Carbon::parse('2026-08-25 10:00:00'));

        $manager = app(ModuleManager::class);
        $module = $this->activateSnmpMonitorModule($manager);
        $product = $this->makeMonitoredProduct($manager, $module, ['poll_interval' => '900']);
        $target = $this->makeTarget($this->makeAccount($product), ['poll_interval' => null]);

        $this->bindCapturingCollector($this->fakeSnmpClient());

        (new PollHostBatch([$target->id]))->handle($manager);

        $this->assertSame(
            $t0->copy()->addSeconds(900)->getTimestamp(),
            $target->fresh()->next_poll_at->getTimestamp(),
            'A null target override must fall back to the product-configured interval.'
        );
    }

    public function test_interval_uses_global_default_when_neither_target_nor_product_config_set_it(): void
    {
        Carbon::setTestNow($t0 = Carbon::parse('2026-08-25 10:00:00'));

        $manager = app(ModuleManager::class);
        $module = $this->activateSnmpMonitorModule($manager);
        $product = $this->makeMonitoredProduct($manager, $module);
        $target = $this->makeTarget($this->makeAccount($product), ['poll_interval' => null]);

        $this->bindCapturingCollector($this->fakeSnmpClient());

        (new PollHostBatch([$target->id]))->handle($manager);

        $this->assertSame(
            $t0->copy()->addSeconds(300)->getTimestamp(),
            $target->fresh()->next_poll_at->getTimestamp(),
            'With no override anywhere, the 300s global default must apply.'
        );
    }

    public function test_interval_target_override_wins_over_product_config(): void
    {
        Carbon::setTestNow($t0 = Carbon::parse('2026-08-25 10:00:00'));

        $manager = app(ModuleManager::class);
        $module = $this->activateSnmpMonitorModule($manager);
        $product = $this->makeMonitoredProduct($manager, $module, ['poll_interval' => '900']);
        $target = $this->makeTarget($this->makeAccount($product), ['poll_interval' => 120]);

        $this->bindCapturingCollector($this->fakeSnmpClient());

        (new PollHostBatch([$target->id]))->handle($manager);

        $this->assertSame(
            $t0->copy()->addSeconds(120)->getTimestamp(),
            $target->fresh()->next_poll_at->getTimestamp(),
            'An explicit per-host override must win over the product default.'
        );
    }

    public function test_interval_resolution_also_applies_to_failed_polls(): void
    {
        Carbon::setTestNow($t0 = Carbon::parse('2026-08-25 10:00:00'));

        $manager = app(ModuleManager::class);
        $module = $this->activateSnmpMonitorModule($manager);
        $product = $this->makeMonitoredProduct($manager, $module, ['poll_interval' => '900']);
        $target = $this->makeTarget($this->makeAccount($product), ['poll_interval' => null]);

        $fake = $this->fakeSnmpClient();
        $fake->getException = new \RuntimeException('agent unreachable');
        $this->bindCapturingCollector($fake);

        (new PollHostBatch([$target->id]))->handle($manager);

        $this->assertSame(
            $t0->copy()->addSeconds(900)->getTimestamp(),
            $target->fresh()->next_poll_at->getTimestamp(),
            'Failure bookkeeping must reschedule using the same product-config fallback as a success.'
        );
    }

    // ------------------------------------------------------------------
    // Rate guards: counter decrease and > 3×interval gap both yield NULL.
    // ------------------------------------------------------------------

    public function test_rate_is_null_when_the_counter_decreases(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 10:00:00'));

        $manager = app(ModuleManager::class);
        $module = $this->activateSnmpMonitorModule($manager);
        $target = $this->makeTarget($this->makeAccount($this->makeMonitoredProduct($manager, $module)));

        $fake = $this->fakeSnmpClient();
        $this->bindCapturingCollector($fake);

        (new PollHostBatch([$target->id]))->handle(app(ModuleManager::class));

        Carbon::setTestNow(Carbon::parse('2026-08-25 10:00:30'));
        // Agent rebooted / counter wrapped backwards.
        $this->swapIfTable($fake, loIn: 400, loOut: 600, eth0In: 100, eth0Out: 1500);

        (new PollHostBatch([$target->id]))->handle(app(ModuleManager::class));

        $row = $this->monitoring()->table('snmp_if_samples')
            ->where('host_id', $target->id)->where('if_index', 2)
            ->orderByDesc('collected_at')->first();
        $this->assertSame(100, (int) $row->in_octets);
        $this->assertNull($row->in_bps, 'A decreased counter must never produce a bogus rate.');
    }

    public function test_rate_is_null_when_gap_exceeds_three_times_the_interval(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 10:00:00'));

        $manager = app(ModuleManager::class);
        $module = $this->activateSnmpMonitorModule($manager);
        $target = $this->makeTarget($this->makeAccount($this->makeMonitoredProduct($manager, $module)));

        $fake = $this->fakeSnmpClient();
        $this->bindCapturingCollector($fake);

        (new PollHostBatch([$target->id]))->handle(app(ModuleManager::class));

        // poll_interval=60 -> rate horizon is 180s; poll again after 181s
        // with strictly increasing counters: still no rate.
        Carbon::setTestNow(Carbon::parse('2026-08-25 10:03:01'));
        $this->swapIfTable($fake, loIn: 800, loOut: 1200, eth0In: 999999, eth0Out: 300000);

        (new PollHostBatch([$target->id]))->handle(app(ModuleManager::class));

        $row = $this->monitoring()->table('snmp_if_samples')
            ->where('host_id', $target->id)->where('if_index', 2)
            ->orderByDesc('collected_at')->first();
        $this->assertSame(999999, (int) $row->in_octets);
        $this->assertNull($row->in_bps, 'A gap wider than 3×interval must store NULL, not a fabricated rate.');
    }

    // ------------------------------------------------------------------
    // Failure bookkeeping: three consecutive failures flip status down,
    // next_poll_at STILL advances, and no zero-filled samples are written.
    // ------------------------------------------------------------------

    public function test_three_consecutive_failures_flip_status_down_without_writing_zero_samples(): void
    {
        Carbon::setTestNow($now = Carbon::parse('2026-08-25 10:00:00'));

        $manager = app(ModuleManager::class);
        $module = $this->activateSnmpMonitorModule($manager);
        $target = $this->makeTarget($this->makeAccount($this->makeMonitoredProduct($manager, $module)));

        $fake = $this->fakeSnmpClient();
        $fake->getException = new \RuntimeException('agent unreachable');
        $this->bindCapturingCollector($fake);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            // Per-target try/catch: the exception must NEVER escape the job,
            // otherwise one bad host would fail the whole batch.
            (new PollHostBatch([$target->id]))->handle(app(ModuleManager::class));

            $target = $target->fresh();
            $this->assertSame($attempt, $target->consecutive_failures);

            if ($attempt < 3) {
                $this->assertNotSame(SnmpTarget::STATUS_DOWN, $target->status, "Status must stay pre-down after {$attempt} failures.");
            }

            // Still advances its schedule even while failing.
            $expected = $now->copy()->addSeconds(60);
            $this->assertSame($expected->getTimestamp(), $target->next_poll_at->getTimestamp());

            $now = $now->copy()->addSeconds(61);
            Carbon::setTestNow($now);
        }

        $this->assertSame(SnmpTarget::STATUS_DOWN, $target->fresh()->status);
        $this->assertSame(
            0,
            $this->monitoring()->table('snmp_host_samples')->where('host_id', $target->id)->count(),
            'Failed polls must leave gaps in the samples, never zero-filled rows.'
        );
        $this->assertSame(
            0,
            $this->monitoring()->table('snmp_latest')->where('host_id', $target->id)->count()
        );
    }

    public function test_one_bad_target_does_not_fail_the_batch(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 10:00:00'));

        $manager = app(ModuleManager::class);
        $module = $this->activateSnmpMonitorModule($manager);
        $product = $this->makeMonitoredProduct($manager, $module);

        // Good target carries an explicit host; bad target has none and its
        // account holds no IP leases, so resolution fails for it alone.
        $good = $this->makeTarget($this->makeAccount($product), ['host' => '192.0.2.60']);
        $bad = $this->makeTarget($this->makeAccount($product), ['host' => null]);

        $this->bindCapturingCollector($this->fakeSnmpClient());

        (new PollHostBatch([$good->id, $bad->id]))->handle(app(ModuleManager::class));

        $this->assertSame(
            1,
            $this->monitoring()->table('snmp_host_samples')->where('host_id', $good->id)->count(),
            'Good target must produce its sample row despite its failing sibling.'
        );
        $this->assertSame(SnmpTarget::STATUS_UP, $good->fresh()->status);
        $this->assertSame(1, $bad->fresh()->consecutive_failures, 'Bad target accumulates failures independently.');
        $this->assertNotNull($bad->fresh()->next_poll_at, 'Failed target still advances its schedule.');
    }

    // ------------------------------------------------------------------
    // RollupHourlyAggregates: previous completed hour, NULL gaps excluded
    // (not zeroed), idempotent via ON DUPLICATE KEY UPDATE.
    // ------------------------------------------------------------------

    public function test_rollup_aggregates_previous_hour_with_null_gaps_excluded(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 11:05:00'));

        $manager = app(ModuleManager::class);
        $module = $this->activateSnmpMonitorModule($manager);
        $product = $this->makeMonitoredProduct($manager, $module);
        $targetA = $this->makeTarget($this->makeAccount($product));
        $targetB = $this->makeTarget($this->makeAccount($product));

        $insertSample = function (int $hostId, string $at, ?float $cpu, ?float $mem): void {
            $this->monitoring()->table('snmp_host_samples')->insert([
                'host_id' => $hostId,
                'collected_at' => $at,
                'cpu_pct' => $cpu,
                'mem_used_mb' => $mem,
            ]);
        };

        // Host A within [10:00, 11:00): cpu 10, null, 30, 20, null -> avg 20 min 10 max 30 n=3.
        $insertSample($targetA->id, '2026-08-25 10:00:00', 10.0, 100.0);
        $insertSample($targetA->id, '2026-08-25 10:15:00', null, 110.0);
        $insertSample($targetA->id, '2026-08-25 10:30:00', 30.0, null);
        $insertSample($targetA->id, '2026-08-25 10:45:00', 20.0, null);
        $insertSample($targetA->id, '2026-08-25 10:59:59', null, null);
        // Out-of-window rows must never leak into the window aggregates.
        $insertSample($targetA->id, '2026-08-25 09:59:59', 999.0, null);
        $insertSample($targetA->id, '2026-08-25 11:00:00', 888.0, null);
        // Host B: single sample.
        $insertSample($targetB->id, '2026-08-25 10:20:00', 4.0, 40.0);

        (new RollupHourlyAggregates)->handle();

        $rows = $this->monitoring()->table('snmp_metric_hourly')->orderBy('host_id')->orderBy('series')->get();

        $cpuA = $rows->first(fn ($r) => $r->host_id === $targetA->id && $r->series === 'cpu_pct');
        $this->assertNotNull($cpuA);
        $this->assertSame(20.0, round((float) $cpuA->v_avg, 5));
        $this->assertSame(10.0, round((float) $cpuA->v_min, 5));
        $this->assertSame(30.0, round((float) $cpuA->v_max, 5));
        $this->assertSame(3, (int) $cpuA->samples, 'NULL gap rows are excluded from the count, not zeroed.');
        $this->assertSame('2026-08-25 10:00:00', substr((string) $cpuA->hour_start, 0, 19));

        $memA = $rows->first(fn ($r) => $r->host_id === $targetA->id && $r->series === 'mem_used_mb');
        $this->assertNotNull($memA);
        $this->assertSame(105.0, round((float) $memA->v_avg, 5));
        $this->assertSame(2, (int) $memA->samples);

        $this->assertNull(
            $rows->first(fn ($r) => $r->host_id === $targetA->id && $r->series === 'storage_pct'),
            'Series without any non-null value must be omitted entirely.'
        );

        $cpuB = $rows->first(fn ($r) => $r->host_id === $targetB->id && $r->series === 'cpu_pct');
        $this->assertNotNull($cpuB);
        $this->assertSame(4.0, round((float) $cpuB->v_avg, 5));

        // Idempotent rerun: upsert, never duplicate. Four rows exist in
        // total: cpu_pct + mem_used_mb for each of the two hosts.
        (new RollupHourlyAggregates)->handle();

        $this->assertSame(4, $this->monitoring()->table('snmp_metric_hourly')->count());
    }

    // ------------------------------------------------------------------
    // Scheduler wiring: three entries present WITHOUT onOneServer /
    // runInBackground (no redis cache, no pcntl on Windows PHP).
    // ------------------------------------------------------------------

    public function test_schedule_entries_present_without_on_one_server_or_run_in_background(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('snmp-poll-dispatch-due')
            ->expectsOutputToContain('snmp-rollup-hourly')
            ->expectsOutputToContain('snmp:maintain-partitions')
            ->assertExitCode(0);

        $events = collect(app(Schedule::class)->events());

        $dispatchEvent = $events->first(fn ($event) => ($event->description ?? null) === 'snmp-poll-dispatch-due');
        $rollupEvent = $events->first(fn ($event) => ($event->description ?? null) === 'snmp-rollup-hourly');
        $partitionsEvent = $events->first(
            fn ($event) => isset($event->command) && str_contains((string) $event->command, 'snmp:maintain-partitions')
        );

        $this->assertNotNull($dispatchEvent, 'Polling dispatch entry missing from the schedule.');
        $this->assertNotNull($rollupEvent, 'Rollup entry missing from the schedule.');
        $this->assertNotNull($partitionsEvent, 'Partition maintenance entry missing from the schedule.');

        $this->assertSame('* * * * *', $dispatchEvent->expression);
        $this->assertSame('5 * * * *', $rollupEvent->expression);
        $this->assertSame('10 0 * * *', $partitionsEvent->expression);

        foreach ([$dispatchEvent, $rollupEvent, $partitionsEvent] as $event) {
            $this->assertFalse((bool) $event->onOneServer, 'onOneServer requires redis; it must never be chained.');
            $this->assertFalse((bool) $event->runInBackground, 'runInBackground requires pcntl; it must never be chained.');
        }
    }

    // ------------------------------------------------------------------
    // Queue configuration contract: retry_after stays above both job
    // timeouts so long-running batches are never re-delivered mid-run.
    // ------------------------------------------------------------------

    public function test_queue_retry_after_exceeds_job_timeouts(): void
    {
        $this->assertSame(180, config('queue.connections.database.retry_after'));

        $batch = new PollHostBatch([1]);
        $rollup = new RollupHourlyAggregates;

        $this->assertSame('snmp-poll', $batch->queue);
        $this->assertSame(1, $batch->tries);
        $this->assertSame(120, $batch->timeout);
        $this->assertSame(300, $rollup->timeout);
        $this->assertGreaterThan($batch->timeout, config('queue.connections.database.retry_after'));
    }
}
