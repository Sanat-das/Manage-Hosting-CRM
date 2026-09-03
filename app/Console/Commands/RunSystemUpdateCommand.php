<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\System\UpdateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RunSystemUpdateCommand extends Command
{
    protected $signature = 'system:run-update {--actor=1 : ID of the admin user triggering the update}';

    protected $description = 'Run the system update as a detached background process (launched by the web UI on IIS).';

    public function handle(UpdateService $updater): int
    {
        $actorId = (int) $this->option('actor');
        $actor   = User::find($actorId);

        if (! $actor) {
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

        $updater->run($actor, $emit);

        return 0;
    }
}
