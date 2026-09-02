<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SNMP time-series tables on the dedicated `monitoring` connection.
 *
 * Deliberately schema-different from ordinary app tables:
 *  - NO AUTO_INCREMENT column anywhere. The natural composite keys are
 *    (host_id, ..., collected_at); MySQL refuses to partition a table whose
 *    auto-increment column is not the leftmost column of the key containing
 *    the partitioning column.
 *  - Every primary key contains collected_at — the RANGE (TO_DAYS) partition
 *    column — which is a MySQL partitioning requirement, not a style choice,
 *    and therefore no separate UNIQUE index is needed for upserts.
 *  - Under MySQL the two *_samples tables are partitioned by month
 *    (p202609 seed + catch-all p_future) so old months are DROPPED as whole
 *    partitions instead of DELETEd row-by-row. snmp:maintain-partitions
 *    grows and prunes them on a rolling horizon.
 *  - When the default driver is sqlite (local development / tests) the
 *    monitoring connection mirrors sqlite and the entire partition layer is
 *    skipped without failing.
 */
return new class extends Migration
{
    /** Tables that carry monthly RANGE partitions (maintained by the artisan command). */
    private const PARTITIONED_TABLES = ['snmp_host_samples', 'snmp_if_samples'];

    public function up(): void
    {
        $this->ensureMonitoringDatabaseExists();

        if (! Schema::connection('monitoring')->hasTable('snmp_host_samples')) {
            Schema::connection('monitoring')->create('snmp_host_samples', function (Blueprint $table): void {
                $table->unsignedInteger('host_id');
                $table->dateTime('collected_at', 3);
                $table->bigInteger('uptime_secs')->nullable();
                $table->float('cpu_load1')->nullable();
                $table->float('cpu_load5')->nullable();
                $table->float('cpu_load15')->nullable();
                $table->float('cpu_pct')->nullable();
                $table->string('cpu_source', 32)->nullable();
                $table->integer('mem_total_mb')->nullable();
                $table->integer('mem_used_mb')->nullable();
                $table->float('storage_pct')->nullable();
                $table->smallInteger('proc_count')->nullable();
                $table->smallInteger('response_ms')->nullable();

                // Composite PK includes the partition column (MySQL rule).
                $table->primary(['host_id', 'collected_at']);
                $table->index(['collected_at', 'host_id'], 'idx_time_host');
            });
        }

        if (! Schema::connection('monitoring')->hasTable('snmp_if_samples')) {
            Schema::connection('monitoring')->create('snmp_if_samples', function (Blueprint $table): void {
                $table->unsignedInteger('host_id');
                $table->unsignedSmallInteger('if_index');
                $table->dateTime('collected_at', 3);

                $table->primary(['host_id', 'if_index', 'collected_at']);
            });
        }

        if (! Schema::connection('monitoring')->hasTable('snmp_latest')) {
            Schema::connection('monitoring')->create('snmp_latest', function (Blueprint $table): void {
                $table->unsignedInteger('host_id')->primary();
                $table->dateTime('collected_at', 3);
                $table->json('payload');
                $table->string('status', 16);
            });
        }

        if (! Schema::connection('monitoring')->hasTable('snmp_metric_hourly')) {
            Schema::connection('monitoring')->create('snmp_metric_hourly', function (Blueprint $table): void {
                $table->unsignedInteger('host_id');
                $table->string('series', 64);
                $table->dateTime('hour_start');
                $table->float('v_avg')->nullable();
                $table->float('v_min')->nullable();
                $table->float('v_max')->nullable();
                $table->smallInteger('samples')->nullable();

                $table->primary(['host_id', 'series', 'hour_start']);
            });
        }

        $this->createMonthlyPartitions();
    }

    public function down(): void
    {
        foreach (['snmp_metric_hourly', 'snmp_latest', 'snmp_if_samples', 'snmp_host_samples'] as $table) {
            Schema::connection('monitoring')->dropIfExists($table);
        }
    }

    /**
     * Create the dedicated monitoring database on the DEFAULT connection.
     * Only meaningful on MySQL/MariaDB — never attempt CREATE DATABASE
     * against sqlite (tests / local development).
     */
    private function ensureMonitoringDatabaseExists(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $database = str_replace('`', '', (string) config('database.connections.monitoring.database'));

        DB::statement(
            "CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
    }

    /**
     * Apply monthly RANGE(TO_DAYS(collected_at)) partitioning with a seed
     * partition (p202609) plus a catch-all p_future. Guarded to the mysql
     * driver; failures are logged, never fatal — an unpartitioned table
     * still collects samples and the maintenance command heals it later.
     */
    private function createMonthlyPartitions(): void
    {
        if (DB::connection('monitoring')->getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::PARTITIONED_TABLES as $table) {
            try {
                DB::connection('monitoring')->statement(sprintf(
                    'ALTER TABLE `%s` PARTITION BY RANGE (TO_DAYS(`collected_at`)) ('
                    ."PARTITION p202609 VALUES LESS THAN (TO_DAYS('2026-10-01')), "
                    .'PARTITION p_future VALUES LESS THAN MAXVALUE)',
                    $table
                ));
            } catch (Throwable $e) {
                Log::error('SNMP monitoring partition DDL failed.', [
                    'table' => $table,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
};
