<?php

declare(strict_types=1);

namespace Modules\SnmpMonitor\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Rolling maintenance for the monthly RANGE partitions on snmp_host_samples
 * and snmp_if_samples (monitoring connection):
 *
 *  - Pre-creates the next monthly partition by REORGANIZING the catch-all
 *    p_future once its start falls within REORGANIZE_LEAD_DAYS of today,
 *    guaranteeing the rolling horizon never runs out mid-month;
 *  - DROPs whole monthly partitions whose rows are entirely older than
 *    MONITORING_RETENTION_DAYS (default 35), keeping at least one monthly
 *    partition so a RANGE table always retains >= 1 partition.
 *
 * Idempotent: repeated runs do no work once the horizon is satisfied.
 * On any non-mysql monitoring driver (e.g. sqlite under tests / local dev)
 * this exits 0 as an explicit no-op.
 */
final class MaintainSnmpPartitions extends Command
{
    /** @var string */
    protected $signature = 'snmp:maintain-partitions';

    /** @var string */
    protected $description = 'Reorganize and prune SNMP monitoring monthly partitions';

    /** Pre-create the next monthly partition once it starts within this many days. */
    private const REORGANIZE_LEAD_DAYS = 45;

    private const PARTITIONED_TABLES = ['snmp_host_samples', 'snmp_if_samples'];

    private const RETENTION_ENV_KEY = 'MONITORING_RETENTION_DAYS';

    private const DEFAULT_RETENTION_DAYS = 35;

    /**
     * Run the maintenance pass. Always returns SUCCESS unless something
     * catastrophic escapes — per-table failures are reported inline.
     */
    public function handle(): int
    {
        $connection = DB::connection('monitoring');

        if ($connection->getDriverName() !== 'mysql') {
            $this->info(sprintf(
                'Monitoring driver is "%s"; partition maintenance is MySQL-only. Nothing to do.',
                $connection->getDriverName()
            ));

            return self::SUCCESS;
        }

        $retentionDays = max(1, (int) env(self::RETENTION_ENV_KEY, self::DEFAULT_RETENTION_DAYS));
        $today = Carbon::today();
        $cutoffDays = $this->toDays($connection, $today->copy()->subDays($retentionDays));
        $created = 0;
        $dropped = 0;

        foreach (self::PARTITIONED_TABLES as $table) {
            try {
                [$tableCreated, $tableDropped] = $this->maintainTable($connection, $table, $today, $cutoffDays);
                $created += $tableCreated;
                $dropped += $tableDropped;
            } catch (Throwable $e) {
                report($e);
                $this->error(sprintf('Partition maintenance failed for %s: %s', $table, $e->getMessage()));
            }
        }

        $this->info(sprintf(
            'SNMP partition maintenance finished: %d partition(s) created, %d dropped.',
            $created,
            $dropped
        ));

        return self::SUCCESS;
    }

    /**
     * Grow + prune one table's partitions.
     *
     * @return array{0: int, 1: int} [created, dropped]
     */
    private function maintainTable(Connection $connection, string $table, Carbon $today, int $cutoffDays): array
    {
        $partitions = $this->partitionsFor($connection, $table);

        if ($partitions === []) {
            return [0, 0];
        }

        $created = $this->growHorizon($connection, $table, $today, $partitions);
        $dropped = $this->pruneExpired($connection, $table, $partitions, $cutoffDays);

        return [$created, $dropped];
    }

    /**
     * Ensure p_future exists, then split off every monthly partition whose
     * start lies within the lead window.
     *
     * @param  array<string, ?int>  $partitions  name => upper-bound TO_DAYS (null = MAXVALUE); updated in place
     */
    private function growHorizon(Connection $connection, string $table, Carbon $today, array &$partitions): int
    {
        $created = 0;

        if (! array_key_exists('p_future', $partitions)) {
            $this->recreateFuturePartition($connection, $table);
            $partitions['p_future'] = null;
        }

        $latestMonth = $this->latestMonthlyMonth(array_keys($partitions));

        while (true) {
            $nextStart = $latestMonth === null
                ? $today->copy()->startOfMonth()
                : $latestMonth->copy()->addMonthNoOverflow()->startOfDay();

            if ($nextStart->greaterThan($today->copy()->addDays(self::REORGANIZE_LEAD_DAYS))) {
                break;
            }

            $name = 'p'.$nextStart->format('Ym');
            $upperBound = $nextStart->copy()->addMonthNoOverflow();

            $connection->statement(sprintf(
                'ALTER TABLE `%s` REORGANIZE PARTITION p_future INTO ('
                ."PARTITION {$name} VALUES LESS THAN (TO_DAYS('%s')), "
                .'PARTITION p_future VALUES LESS THAN MAXVALUE)',
                $table,
                $upperBound->toDateString()
            ));

            $partitions[$name] = $this->toDays($connection, $upperBound);
            $latestMonth = $nextStart;
            $created++;

            $this->line(sprintf('  %s: created partition %s', $table, $name));
        }

        return $created;
    }

    /**
     * Drop whole monthly partitions whose data is entirely beyond the
     * retention cutoff, never removing the final remaining monthly
     * partition (a RANGE table must keep at least one non-MAXVALUE
     * partition).
     *
     * @param  array<string, ?int>  $partitions  name => upper-bound TO_DAYS (null = MAXVALUE)
     */
    private function pruneExpired(Connection $connection, string $table, array $partitions, int $cutoffDays): int
    {
        $isMonthly = static fn (string $name): bool => preg_match('/^p\d{6}$/', $name) === 1;

        $expired = array_filter(
            $partitions,
            static fn (?int $upperDays, string $name): bool => $upperDays !== null
                && $upperDays <= $cutoffDays
                && $isMonthly($name),
            ARRAY_FILTER_USE_BOTH
        );

        if ($expired === []) {
            return 0;
        }

        $monthlyCount = count(array_filter(array_keys($partitions), $isMonthly));

        // Never drop away the last monthly partition.
        if (count($expired) >= $monthlyCount) {
            unset($expired[array_search(max($expired), $expired, true)]);
        }

        ksort($expired);

        foreach (array_keys($expired) as $name) {
            $connection->statement(
                sprintf('ALTER TABLE `%s` DROP PARTITION %s', $table, $name)
            );

            $this->line(sprintf('  %s: dropped partition %s', $table, $name));
        }

        return count($expired);
    }

    /**
     * Recreate a lost catch-all partition (QA scenario: p_future dropped
     * manually, command must heal the table).
     */
    private function recreateFuturePartition(Connection $connection, string $table): void
    {
        $connection->statement(sprintf(
            'ALTER TABLE `%s` ADD PARTITION (PARTITION p_future VALUES LESS THAN MAXVALUE)',
            $table
        ));

        $this->line(sprintf('  %s: recreated catch-all p_future', $table));
    }

    /**
     * @return array<string, ?int> partition name => upper-bound TO_DAYS (null = MAXVALUE/p_future)
     */
    private function partitionsFor(Connection $connection, string $table): array
    {
        $rows = $connection->select(
            'SELECT PARTITION_NAME AS name, PARTITION_DESCRIPTION AS description '
            .'FROM INFORMATION_SCHEMA.PARTITIONS '
            .'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? '
            .'ORDER BY PARTITION_ORDINAL_POSITION',
            [$connection->getConfig('database'), $table]
        );

        $partitions = [];

        foreach ($rows as $row) {
            $description = (string) $row->description;

            $partitions[(string) $row->name] = ctype_digit($description) ? (int) $description : null;
        }

        return $partitions;
    }

    /**
     * Newest pYYYYMM among the given partition names, as a Carbon date at
     * that month's first day — or null when no monthly partition exists.
     *
     * @param  list<string>  $names
     */
    private function latestMonthlyMonth(array $names): ?Carbon
    {
        $months = array_map(
            static fn (string $name): int => (int) substr($name, 1),
            array_filter($names, static fn (string $name): bool => preg_match('/^p\d{6}$/', $name) === 1)
        );

        if ($months === []) {
            return null;
        }

        return Carbon::createFromFormat('!Ym', (string) max($months));
    }

    /** MySQL-side TO_DAYS so boundary math matches INFORMATION_SCHEMA exactly. */
    private function toDays(Connection $connection, Carbon $date): int
    {
        return (int) $connection->selectOne('SELECT TO_DAYS(?) AS d', [$date->toDateString()])->d;
    }
}
