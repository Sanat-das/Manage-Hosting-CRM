<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Settings\LogRetentionSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Prune every DB-backed audit/log stream according to its configured window.
 *
 * The windows are resolved per table by LogRetentionSettings::daysFor(), which
 * reads the admin Settings → Log Retention values and falls back to
 * config/audit.php `retention` defaults. A `null` window keeps the stream
 * forever (compliance records). Each table is pruned independently: a failure
 * on one stream reports a warning and the rest still run, so a single broken
 * table never stalls retention.
 */
class PruneLogsCommand extends Command
{
    protected $signature = 'logs:prune';

    protected $description = 'Prune audit/log streams per Settings → Log Retention / config/audit.php defaults';

    public function handle(): int
    {
        foreach ((array) config('audit.retention', []) as $table => $days) {
            // Admin Settings → Log Retention wins; config/audit.php is the default.
            $days = LogRetentionSettings::daysFor($table);

            if ($days === null) {
                $this->line("{$table}: kept forever");

                continue;
            }

            try {
                $deleted = DB::table($table)
                    ->where('created_at', '<', now()->subDays($days))
                    ->delete();

                $this->line("{$table}: {$deleted} deleted (older than {$days} days)");
            } catch (Throwable $e) {
                $this->warn("{$table}: pruning failed — {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
