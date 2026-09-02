<?php

declare(strict_types=1);

namespace Modules\SshConsole\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\SshConsole\Models\SshConsoleSession;
use Throwable;

/**
 * Prune stale web-SSH sessions left 'opened' when the browser crashes
 * before /close is reached.
 *
 * - Finds rows with status='opened' and started_at (or updated_at) older
 *   than the threshold (default 35 min).
 * - Finalizes each via ->finalize('closed','Pruned: stale') for idempotent
 *   transition (racing streamer / close endpoint stay safe).
 * - Purges the three per-token cache keys so the input/control/activity
 *   queues cannot be reused after the row is closed.
 *
 * Idempotent: re-running on already-closed rows does nothing; finalize()
 * guards the status check and cache forget is unconditional.
 *
 * Scheduling (see routes/console.php):
 *   Schedule::command('ssh:prune')->everyFifteenMinutes()->withoutOverlapping();
 */
final class PruneSshSessions extends Command
{
    protected $signature = 'ssh:prune
                            {--minutes=35 : Age in minutes after which an opened session is considered stale}
                            {--dry-run : List stale sessions without modifying rows or cache}';

    protected $description = 'Close stale opened Linux VPS SSH sessions and purge their cache keys';

    private const ERROR_MESSAGE = 'Pruned: stale';

    private const CACHE_PREFIXES = [
        'ssh-console.in.',
        'ssh-console.ctrl.',
        'ssh-console.act.',
    ];

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = Carbon::now()->subMinutes($minutes);

        $this->info(sprintf(
            'Pruning SSH sessions opened before %s (%d minute threshold)%s',
            $cutoff->toDateTimeString(),
            $minutes,
            $dryRun ? ' — dry run' : ''
        ));

        try {
            // Gracefully handle missing table (fresh install before migrations).
            if (! $this->tableExists()) {
                $this->warn('Table ssh_console_sessions does not exist — nothing to prune.');
                return self::SUCCESS;
            }

            $stale = SshConsoleSession::query()
                ->where('status', 'opened')
                ->where(function ($q) use ($cutoff): void {
                    // started_at is the canonical open timestamp; updated_at
                    // is the fallback for rows where started_at was not set
                    // or for future schema variations.
                    $q->where('started_at', '<', $cutoff)
                        ->orWhere('updated_at', '<', $cutoff);
                })
                ->orderBy('id')
                ->get(['id', 'token', 'started_at', 'updated_at']);
        } catch (QueryException $e) {
            $this->warn('Could not query ssh_console_sessions (table missing or unavailable): '.$e->getMessage());
            return self::SUCCESS;
        } catch (Throwable $e) {
            report($e);
            $this->error('Failed to query stale SSH sessions: '.$e->getMessage());
            return self::FAILURE;
        }

        if ($stale->isEmpty()) {
            $this->info('No stale SSH sessions found.');
            return self::SUCCESS;
        }

        $this->line(sprintf('Found %d stale opened session(s).', $stale->count()));

        if ($dryRun) {
            foreach ($stale as $session) {
                $this->line(sprintf(
                    '  #%-6d token=%s started_at=%s updated_at=%s',
                    $session->id,
                    substr((string) $session->token, 0, 8).'…',
                    $session->started_at?->toDateTimeString() ?? 'null',
                    $session->updated_at?->toDateTimeString() ?? 'null'
                ));
            }

            $this->info(sprintf('Dry run: %d session(s) would be pruned.', $stale->count()));

            return self::SUCCESS;
        }

        $pruned = 0;

        foreach ($stale as $session) {
            try {
                // Refresh to avoid racing a concurrent close/streamer finalize.
                $fresh = SshConsoleSession::query()->find($session->id);

                if ($fresh === null || $fresh->status !== 'opened') {
                    // Already finalized by a concurrent request — still purge
                    // cache keys for hygiene.
                    $this->purgeCache((string) $session->token);
                    continue;
                }

                // Finalize is idempotent (WHERE status='opened' guard).
                $fresh->finalize('closed', self::ERROR_MESSAGE);
                $this->purgeCache((string) $fresh->token);
                $pruned++;

                $this->line(sprintf(
                    '  Pruned #%-6d token=%s',
                    $fresh->id,
                    substr((string) $fresh->token, 0, 8).'…'
                ));
            } catch (Throwable $e) {
                report($e);
                $this->error(sprintf('  Failed to prune #%d: %s', $session->id, $e->getMessage()));
                // Still purge cache even when finalize failed, to avoid
                // indefinite queue retention.
                try {
                    $this->purgeCache((string) $session->token);
                } catch (Throwable) {
                    // Cache purge must never break the loop.
                }
            }
        }

        $this->info(sprintf('Pruned %d stale SSH session(s).', $pruned));

        return self::SUCCESS;
    }

    /**
     * Purge all three cache keys for a token. Unconditional — Cache::forget
     * is a no-op for missing keys.
     */
    private function purgeCache(string $token): void
    {
        if ($token === '') {
            return;
        }

        foreach (self::CACHE_PREFIXES as $prefix) {
            try {
                Cache::forget($prefix.$token);
            } catch (Throwable) {
                // Cache backend failure must not abort pruning.
            }
        }
    }

    /**
     * Check table existence without throwing when the connection is not
     * yet migrated (mirrors controller guard pattern).
     */
    private function tableExists(): bool
    {
        try {
            return DB::connection()->getSchemaBuilder()->hasTable('ssh_console_sessions');
        } catch (Throwable) {
            return false;
        }
    }
}
