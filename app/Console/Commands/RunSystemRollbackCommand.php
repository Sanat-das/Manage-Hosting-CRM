<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\System\UpdateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RunSystemRollbackCommand extends Command
{
    protected $signature = 'system:run-rollback {--actor=1 : ID of the admin user triggering the rollback} {--from= : Version or commit hash to roll back to}';

    protected $description = 'Run a system rollback as a detached background process (launched by the web UI).';

    public function handle(UpdateService $updater): int
    {
        $actorId = (int) $this->option('actor');

        // Written before anything else can fail: when the web UI reports no
        // progress, the presence or absence of this line separates "the
        // detached process never started" from "it started and then died".
        $this->mark(sprintf('booted pid=%d actor=%d', getmypid(), $actorId));

        $actor = User::find($actorId);

        if (! $actor) {
            $this->mark('aborted — actor not found: '.$actorId);
            $this->error('system:run-rollback — actor not found: '.$actorId);

            return 1;
        }

        $cacheKey = 'system.update_progress.'.$actorId;

        $emit = function (string $step, string $message, int $progress, bool $done = false, array $extra = []) use ($cacheKey): void {
            // A run the update lock refused must not overwrite the progress of
            // the run that is actually holding it: both processes publish to the
            // same per-actor key, so a double-click would otherwise replace a
            // healthy in-flight run with "an update is already running".
            if (($extra['status'] ?? null) === 'busy') {
                $this->mark('refused — another update holds the lock');

                return;
            }

            Cache::put($cacheKey, array_merge(
                ['step' => $step, 'message' => $message, 'progress' => $progress, 'done' => $done],
                $extra
            ), 600);
        };

        try {
            // The service emits the terminal done/error event itself — emitting
            // one here would overwrite the result it just published.
            $result = $updater->rollback((string) $this->option('from'), $actor, $emit);
            $this->mark('finished — status='.($result['status'] ?? 'unknown'));
        } catch (Throwable $e) {
            // Nothing is watching this process's exit code, so an uncaught
            // throwable would otherwise leave the UI polling forever.
            $this->mark('fatal — '.$e->getMessage());
            $emit('error', 'Rollback failed unexpectedly: '.$e->getMessage(), 0, true, ['status' => 'unknown']);

            return 1;
        }

        return 0;
    }

    private function mark(string $line): void
    {
        try {
            @file_put_contents(
                storage_path('logs/rollback.log'),
                sprintf('[%s] system:run-rollback %s%s', now()->toDateTimeString(), $line, PHP_EOL),
                FILE_APPEND
            );
        } catch (Throwable) {
            // Logging must never break the rollback itself.
        }
    }
}
