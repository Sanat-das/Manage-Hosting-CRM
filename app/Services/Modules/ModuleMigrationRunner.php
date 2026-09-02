<?php

declare(strict_types=1);

namespace App\Services\Modules;

use App\Models\Module;
use App\Models\ModuleLog;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs and rolls back the migrations inside a module folder, tracked in the
 * module_migrations table so uninstall can reverse them in batch order.
 *
 * Migration files are standard Laravel anonymous-class migrations
 * (`return new class extends Migration { ... }`), required per file so each
 * runs in its own scope.
 */
class ModuleMigrationRunner
{
    /**
     * Apply every not-yet-run migration in the module's database/migrations
     * folder. Returns silently when the folder does not exist.
     *
     * @throws Throwable on the first failing migration (after logging).
     */
    public function migrate(Module $module): void
    {
        $path = config('modules.path').'/'.$module->slug.'/database/migrations';

        if (! is_dir($path)) {
            return;
        }

        $alreadyRun = DB::table('module_migrations')
            ->where('module_id', $module->id)
            ->pluck('migration')
            ->all();

        $files = glob($path.'/*.php') ?: [];
        sort($files);

        foreach ($files as $file) {
            $basename = basename($file);

            if (in_array($basename, $alreadyRun, true)) {
                continue;
            }

            try {
                $migration = require $file;
                $migration->up();

                DB::table('module_migrations')->insert([
                    'module_id' => $module->id,
                    'migration' => $basename,
                    'batch' => $this->highestBatch($module) + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (Throwable $e) {
                $this->log($module, 'migrate', 'failed', $e->getMessage());

                throw $e;
            }
        }
    }

    /**
     * Roll back the module's highest migration batch, newest first. Best
     * effort: failures are logged but never rethrown (uninstall must not be
     * blocked by a broken down()).
     */
    public function rollback(Module $module): void
    {
        $path = config('modules.path').'/'.$module->slug.'/database/migrations';

        if (! is_dir($path)) {
            return;
        }

        $batch = $this->highestBatch($module);

        if ($batch === 0) {
            return;
        }

        $records = DB::table('module_migrations')
            ->where('module_id', $module->id)
            ->where('batch', $batch)
            ->orderByDesc('id')
            ->get();

        foreach ($records as $record) {
            $file = $path.'/'.$record->migration;

            if (! is_file($file)) {
                continue;
            }

            try {
                $migration = require $file;
                $migration->down();

                DB::table('module_migrations')->where('id', $record->id)->delete();
            } catch (Throwable $e) {
                $this->log($module, 'rollback', 'failed', $e->getMessage());
            }
        }
    }

    private function highestBatch(Module $module): int
    {
        return (int) DB::table('module_migrations')
            ->where('module_id', $module->id)
            ->max('batch');
    }

    private function log(Module $module, string $event, string $status, ?string $error = null): void
    {
        try {
            ModuleLog::create([
                'module_id' => $module->id,
                'event' => $event,
                'status' => $status,
                'error' => $error,
            ]);
        } catch (Throwable) {
            // Logging must never break the migration runner.
        }
    }
}