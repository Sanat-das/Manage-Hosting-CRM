<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\UpdateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class FinalizeSystemUpdateCommand extends Command
{
    protected $signature = 'system:update:finalize {--actor= : Optional admin user ID, to publish progress to the web UI}';

    protected $description = 'Run the post-update steps (composer, migrate, cache clear, up) and verify nothing is left pending.';

    public function handle(UpdateService $updater): int
    {
        $actorId = $this->option('actor') !== null ? (int) $this->option('actor') : null;

        // When an actor is given, mirror progress into the cache key the system
        // page polls, so a manual repair still drives the UI.
        $emit = function (string $step, string $message, int $progress, bool $done = false, array $extra = []) use ($actorId): void {
            $this->line(sprintf('[%s] %s', $step, $message));

            if ($actorId === null) {
                return;
            }

            try {
                Cache::put('system.update_progress.' . $actorId, array_merge(
                    ['step' => $step, 'message' => $message, 'progress' => $progress, 'done' => $done],
                    $extra
                ), 600);
            } catch (Throwable) {
                // Progress reporting must never break the repair itself.
            }
        };

        try {
            $result = $updater->finalize($emit);
        } catch (Throwable $e) {
            $this->error('Post-update steps failed: ' . $e->getMessage());

            return 1;
        }

        $this->newLine();
        $this->line(trim($result['output']));
        $this->newLine();

        if ($result['status'] === 'success') {
            $this->info($result['message']);

            return 0;
        }

        $this->error($result['message']);

        return 1;
    }
}
