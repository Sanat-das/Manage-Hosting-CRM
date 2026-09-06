<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Carry the email queue worker's admin settings across its rename.
 *
 * The scheduled command gained `default` alongside `emails`:
 *
 *   queue:work --queue=emails        --sleep=3 --tries=3 ...
 *   queue:work --queue=emails,default --sleep=3 --tries=3 ...
 *
 * `CronTaskRegistry::keyFor()` keys a command task on its FULL signature,
 * arguments included, so that edit is a brand new key. Without this migration
 * an operator who had disabled the worker, or given it a custom expression,
 * would find the row orphaned and the task quietly back on its defaults.
 *
 * The run history in `cron_task_runs` is re-pointed too, so the Cron Jobs page
 * shows one continuous history rather than a task that appears to have been
 * created today.
 */
return new class extends Migration
{
    private const OLD_KEY = 'queue:work --queue=emails --sleep=3 --tries=3 --stop-when-empty --max-time=50';

    private const NEW_KEY = 'queue:work --queue=emails,default --sleep=3 --tries=3 --stop-when-empty --max-time=50';

    public function up(): void
    {
        $this->rekey(self::OLD_KEY, self::NEW_KEY);
    }

    public function down(): void
    {
        $this->rekey(self::NEW_KEY, self::OLD_KEY);
    }

    private function rekey(string $from, string $to): void
    {
        // `key` is the primary key: if the destination row somehow already
        // exists, updating into it would collide. The existing row wins and
        // the stale one is dropped.
        $destinationExists = DB::table('cron_tasks')->where('key', $to)->exists();

        if ($destinationExists) {
            DB::table('cron_tasks')->where('key', $from)->delete();
        } else {
            DB::table('cron_tasks')->where('key', $from)->update(['key' => $to]);
        }

        DB::table('cron_task_runs')->where('task_key', $from)->update(['task_key' => $to]);
    }
};
