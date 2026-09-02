<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storage behind the admin Cron Jobs page.
 *
 * `cron_tasks` holds ONLY the operator-settable state for a scheduled task —
 * the schedule itself stays declared in routes/console.php. A task with no
 * row here is enabled: the table records deviations from the code, never the
 * schedule, so adding a task to console.php needs no data change.
 *
 * `cron_task_runs` is the append-only run history written by
 * App\Listeners\RecordScheduledTaskRun from Laravel's ScheduledTask* events.
 * triggered_by is a plain unsigned big integer rather than a foreign key:
 * deleting an admin must not cascade away the audit trail of what ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cron_tasks', function (Blueprint $table) {
            // 191 keeps the unique index inside the utf8mb4 key length limit
            // on older MySQL/MariaDB defaults.
            $table->string('key', 191)->primary();
            $table->boolean('enabled')->default(true);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('cron_task_runs', function (Blueprint $table) {
            $table->id();
            $table->string('task_key', 191);
            // running | success | failed | skipped
            $table->string('status', 20)->default('running');
            // schedule | manual
            $table->string('trigger', 20)->default('schedule');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('runtime_ms')->nullable();
            $table->integer('exit_code')->nullable();
            $table->text('message')->nullable();
            $table->unsignedBigInteger('triggered_by')->nullable();
            $table->timestamps();

            // Drives both the per-task "last run" lookup and the page's
            // reverse-chronological history list.
            $table->index(['task_key', 'started_at']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cron_task_runs');
        Schema::dropIfExists('cron_tasks');
    }
};
