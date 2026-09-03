<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\System\UpdateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RunSystemUpdateCommand extends Command
{
    protected $signature = 'system:run-update {--actor=1 : ID of the admin user triggering the update}';

    protected $description = 'Run the system update as a detached background process (launched by the web UI on IIS).';

    public function handle(UpdateService $updater): int
    {
        $actorId = (int) $this->option('actor');

        // Written before anything else can fail: when the web UI reports no
        // progress, the presence or absence of this line separates "the
        // detached process never started" from "it started and then died".
        $this->mark(sprintf('booted pid=%d actor=%d', getmypid(), $actorId));

        $actor = User::find($actorId);

        if (! $actor) {
            $this->mark('aborted — actor not found: ' . $actorId);
            $this->error('system:run-update — actor not found: ' . $actorId);
            return 1;
        }

        $cacheKey = 'system.update_progress.' . $actorId;

        $emit = function (string $step, string $message, int $progress, bool $done = false, array $extra = []) use ($cacheKey): void {
            Cache::put($cacheKey, array_merge(
                ['step' => $step, 'message' => $message, 'progress' => $progress, 'done' => $done],
                $extra
            ), 600);
        };

        try {
            $updater->run($actor, $emit);
        } catch (Throwable $e) {
            // Nothing is watching this process's exit code, so an uncaught
            // throwable would otherwise leave the UI polling forever.
            $this->mark('fatal — ' . $e->getMessage());
            $emit('error', 'Update failed unexpectedly: ' . $e->getMessage(), 0, true, ['status' => 'unknown']);

            return 1;
        }

        return 0;
    }

    private function mark(string $line): void
    {
        try {
            @file_put_contents(
                storage_path('logs/update.log'),
                sprintf('[%s] system:run-update %s%s', now()->toDateTimeString(), $line, PHP_EOL),
                FILE_APPEND
            );
        } catch (Throwable) {
            // Logging must never break the update itself.
        }
    }
}
