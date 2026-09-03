<?php

declare(strict_types=1);

namespace App\Services\System;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Git-driven update orchestration for the About & Update page.
 *
 * check() is read-only and never mutates the working tree.
 * run() executes the guarded chain: down -> pull --ff-only -> composer install -> migrate -> cache clear -> up.
 *
 * Every git/process interaction uses Symfony Process with an explicit timeout
 * and never leaks an embedded token — remote URLs are always sanitized.
 */
class UpdateService
{
    public const OUTPUT_LIMIT = 20000;

    /**
     * Read-only check: is the checkout behind origin/main?
     *
     * @return array{status: string, message: string, behind: int, commits: list<array{hash: string, short: string, message: string, author: string, date: string}>, diffStat: string|null, localHash: string|null, remoteHash: string|null, branch: string|null, remoteSanitized: string|null, remoteUrlRaw: string|null, dirty: bool|null}
     */
    public function check(): array
    {
        // Guard: is this even a git repo?
        if (! $this->isGitRepo()) {
            $api = $this->fetchGithubCommits(5);
            if ($api !== null && ! empty($api['commits'])) {
                $latest = $api['commits'][0];
                $localVersion = trim((string) (is_file(base_path('VERSION')) ? file_get_contents(base_path('VERSION')) : config('app.version', 'dev')));
                $localVersion = $localVersion !== '' ? $localVersion : 'dev';
                return [
                    'status' => 'no_git',
                    'message' => sprintf(
                        'This is a ZIP/manual install (local version %s). Latest on GitHub is %s from %s — download the latest ZIP from GitHub, replace files (keep .env, storage/, install.lock), then run composer install --no-dev --optimize-autoloader && php artisan migrate --force && php artisan optimize:clear.',
                        $localVersion,
                        $latest['short'] ?? substr($latest['hash'] ?? '', 0, 7),
                        $latest['date'] ?? 'unknown'
                    ),
                    'behind' => count($api['commits']),
                    'commits' => $api['commits'],
                    'diffStat' => null,
                    'localHash' => $localVersion,
                    'remoteHash' => $api['remoteHash'],
                    'branch' => 'main',
                    'remoteSanitized' => 'https://github.com/Sanat-das/Manage-Hosting-CRM',
                    'remoteUrlRaw' => 'https://github.com/Sanat-das/Manage-Hosting-CRM.git',
                    'dirty' => null,
                ];
            }

            return [
                'status' => 'no_git',
                'message' => 'This installation was not deployed via git. To update, download the latest ZIP from GitHub, replace files (keep .env, storage/, install.lock), then run composer install --no-dev --optimize-autoloader && php artisan migrate --force && php artisan optimize:clear.',
                'behind' => 0,
                'commits' => [],
                'diffStat' => null,
                'localHash' => null,
                'remoteHash' => null,
                'branch' => null,
                'remoteSanitized' => null,
                'remoteUrlRaw' => null,
                'dirty' => null,
            ];
        }

        // Remote URL
        $remoteRaw = $this->getRemoteRaw();
        $remoteSanitized = $remoteRaw !== null ? $this->sanitizeRemote($remoteRaw) : null;

        if ($remoteRaw === null || trim($remoteRaw) === '') {
            return [
                'status' => 'no_remote',
                'message' => 'No remote configured — add origin pointing to https://github.com/Sanat-das/Manage-Hosting-CRM.git',
                'behind' => 0,
                'commits' => [],
                'diffStat' => null,
                'localHash' => $this->resolveLocalHash(),
                'remoteHash' => null,
                'branch' => $this->resolveBranch(),
                'remoteSanitized' => null,
                'remoteUrlRaw' => null,
                'dirty' => $this->isDirty(),
            ];
        }

        // Dirty working tree
        $dirty = $this->isDirty();
        if ($dirty === true) {
            $statusExcerpt = $this->resolveDirtyExcerpt();

            return [
                'status' => 'dirty',
                'message' => 'Working tree has local changes — commit or stash them first. ' . ($statusExcerpt !== '' ? 'Excerpt: ' . $statusExcerpt : ''),
                'behind' => 0,
                'commits' => [],
                'diffStat' => null,
                'localHash' => $this->resolveLocalHash(),
                'remoteHash' => null,
                'branch' => $this->resolveBranch(),
                'remoteSanitized' => $remoteSanitized,
                'remoteUrlRaw' => $remoteRaw,
                'dirty' => true,
            ];
        }

        // Fetch from origin (15s)
        $fetch = $this->runProcess(['git', 'fetch', 'origin'], 15);

        if (! $fetch['success']) {
            return [
                'status' => 'fetch_failed',
                'message' => 'Could not reach GitHub — check outbound firewall / proxy. ' . trim(Str::limit($fetch['output'], 500)),
                'behind' => 0,
                'commits' => [],
                'diffStat' => null,
                'localHash' => $this->resolveLocalHash(),
                'remoteHash' => null,
                'branch' => $this->resolveBranch(),
                'remoteSanitized' => $remoteSanitized,
                'remoteUrlRaw' => $remoteRaw,
                'dirty' => $dirty,
            ];
        }

        // After fetch, re-check dirty in case fetch updated index
        $dirtyAfterFetch = $this->isDirty();

        // Behind count
        $behind = 0;
        $behindRes = $this->runProcess(['git', 'rev-list', 'HEAD..origin/main', '--count'], 3);
        if ($behindRes['success'] && trim($behindRes['output']) !== '') {
            $behind = (int) trim($behindRes['output']);
        } elseif (! $behindRes['success']) {
            // origin/main may not exist (e.g. different default branch) — try origin/master
            $fallback = $this->runProcess(['git', 'rev-list', 'HEAD..origin/master', '--count'], 3);
            if ($fallback['success'] && trim($fallback['output']) !== '') {
                $behind = (int) trim($fallback['output']);
            }
        }

        // Local / remote hashes
        $localHash = $this->resolveLocalHash();
        $remoteHash = $this->resolveRemoteHash();
        $branch = $this->resolveBranch();

        // No commits behind -> up to date
        if ($behind === 0) {
            return [
                'status' => 'up_to_date',
                'message' => 'Up to date.',
                'behind' => 0,
                'commits' => [],
                'diffStat' => null,
                'localHash' => $localHash,
                'remoteHash' => $remoteHash,
                'branch' => $branch,
                'remoteSanitized' => $remoteSanitized,
                'remoteUrlRaw' => $remoteRaw,
                'dirty' => $dirtyAfterFetch,
            ];
        }

        // Behind: collect commits and diff stat
        $commits = $this->resolveCommits();
        $diffStat = $this->resolveDiffStat();

        return [
            'status' => 'behind',
            'message' => sprintf('Update available — %d commit(s) behind origin/main.', $behind),
            'behind' => $behind,
            'commits' => $commits,
            'diffStat' => $diffStat,
            'localHash' => $localHash,
            'remoteHash' => $remoteHash,
            'branch' => $branch,
            'remoteSanitized' => $remoteSanitized,
            'remoteUrlRaw' => $remoteRaw,
            'dirty' => $dirtyAfterFetch,
        ];
    }

    /**
     * Perform the guarded update chain.
     *
     * @return array{status: string, message: string, behind: int, from: string|null, to: string|null, branch: string|null, remoteSanitized: string|null, exit: int, durationMs: int, output: string}
     */
    public function run(User $actor): array
    {
        $startedAt = microtime(true);
        $capturedOutput = '';
        $exitCode = 0;
        $fromHash = $this->resolveLocalHash();
        $branch = $this->resolveBranch();
        $remoteRaw = $this->getRemoteRaw();
        $remoteSanitized = $remoteRaw !== null ? $this->sanitizeRemote($remoteRaw) : null;
        $didDown = false;

        $appendOutput = function (string $label, string $output, int $exit) use (&$capturedOutput): void {
            $segment = sprintf("\n[%s] exit=%d\n%s\n", $label, $exit, trim($output));
            $capturedOutput .= $segment;
            // Keep within limit incrementally
            if (mb_strlen($capturedOutput) > self::OUTPUT_LIMIT) {
                $capturedOutput = Str::limit($capturedOutput, self::OUTPUT_LIMIT);
            }
        };

        try {
            // Guards (fail fast before any mutation)
            if (! $this->isGitRepo()) {
                return $this->buildRunResult(
                    status: 'no_git',
                    message: 'This installation was not deployed via git — cannot run update.',
                    behind: 0,
                    from: $fromHash,
                    to: null,
                    branch: $branch,
                    remoteSanitized: $remoteSanitized,
                    exit: 1,
                    startedAt: $startedAt,
                    output: 'Not a git repository.'
                );
            }

            if ($remoteRaw === null || trim($remoteRaw) === '') {
                return $this->buildRunResult(
                    status: 'no_remote',
                    message: 'No remote configured — add origin first.',
                    behind: 0,
                    from: $fromHash,
                    to: null,
                    branch: $branch,
                    remoteSanitized: null,
                    exit: 1,
                    startedAt: $startedAt,
                    output: 'No remote origin.'
                );
            }

            if ($this->isDirty() === true) {
                $excerpt = $this->resolveDirtyExcerpt();

                return $this->buildRunResult(
                    status: 'dirty',
                    message: 'Working tree has local changes — commit or stash them first.',
                    behind: 0,
                    from: $fromHash,
                    to: null,
                    branch: $branch,
                    remoteSanitized: $remoteSanitized,
                    exit: 1,
                    startedAt: $startedAt,
                    output: $excerpt !== '' ? $excerpt : 'Dirty working tree.'
                );
            }

            // Fetch to know if there is anything to do
            $fetch = $this->runProcess(['git', 'fetch', 'origin'], 15);
            $appendOutput('git fetch origin', $fetch['output'], $fetch['exit']);

            if (! $fetch['success']) {
                return $this->buildRunResult(
                    status: 'fetch_failed',
                    message: 'Could not reach GitHub — fetch failed.',
                    behind: 0,
                    from: $fromHash,
                    to: null,
                    branch: $branch,
                    remoteSanitized: $remoteSanitized,
                    exit: $fetch['exit'],
                    startedAt: $startedAt,
                    output: Str::limit($capturedOutput, self::OUTPUT_LIMIT)
                );
            }

            $behindRes = $this->runProcess(['git', 'rev-list', 'HEAD..origin/main', '--count'], 3);
            $behind = 0;
            if ($behindRes['success'] && trim($behindRes['output']) !== '') {
                $behind = (int) trim($behindRes['output']);
            }

            if ($behind === 0) {
                $result = $this->buildRunResult(
                    status: 'up_to_date',
                    message: 'Already up to date — no commits to pull.',
                    behind: 0,
                    from: $fromHash,
                    to: $fromHash,
                    branch: $branch,
                    remoteSanitized: $remoteSanitized,
                    exit: 0,
                    startedAt: $startedAt,
                    output: Str::limit($capturedOutput, self::OUTPUT_LIMIT)
                );
                $this->audit($actor, $result, $capturedOutput, $behind);

                return $result;
            }

            // Maintenance down
            $down = $this->runProcess(['php', 'artisan', 'down', '--secret=' . Str::random(16)], 15);
            $appendOutput('php artisan down', $down['output'], $down['exit']);
            $didDown = $down['success'] || str_contains(strtolower($down['output']), 'already');

            // Pull --ff-only
            $pull = $this->runProcess(['git', 'pull', '--ff-only', 'origin', 'main'], 60);
            $appendOutput('git pull --ff-only origin main', $pull['output'], $pull['exit']);

            if (! $pull['success']) {
                $message = 'Local branch diverged — resolve manually via SSH: git fetch origin && git reset --hard origin/main (warn: data loss).';
                if (str_contains($pull['output'], 'permission denied') || str_contains(strtolower($pull['output']), 'permission')) {
                    $message = 'App directory is not writable by the web-server user — fix filesystem permissions and retry.';
                }

                $result = $this->buildRunResult(
                    status: 'failed',
                    message: $message . ' ' . trim(Str::limit($pull['output'], 1500)),
                    behind: $behind,
                    from: $fromHash,
                    to: $this->resolveLocalHash(),
                    branch: $branch,
                    remoteSanitized: $remoteSanitized,
                    exit: $pull['exit'],
                    startedAt: $startedAt,
                    output: Str::limit($capturedOutput, self::OUTPUT_LIMIT)
                );
                $this->audit($actor, $result, $capturedOutput, $behind);

                return $result;
            }

            // Composer install (optional — skip if binary missing)
            if ($this->composerAvailable()) {
                $composer = $this->runProcess(['composer', 'install', '--no-dev', '--optimize-autoloader', '--no-interaction'], 120);
                $appendOutput('composer install --no-dev --optimize-autoloader --no-interaction', $composer['output'], $composer['exit']);

                if (! $composer['success']) {
                    $result = $this->buildRunResult(
                        status: 'failed',
                        message: 'Update fetched but dependencies failed — run composer install manually. ' . trim(Str::limit($composer['output'], 1500)),
                        behind: $behind,
                        from: $fromHash,
                        to: $this->resolveLocalHash(),
                        branch: $branch,
                        remoteSanitized: $remoteSanitized,
                        exit: $composer['exit'],
                        startedAt: $startedAt,
                        output: Str::limit($capturedOutput, self::OUTPUT_LIMIT)
                    );
                    $this->audit($actor, $result, $capturedOutput, $behind);

                    return $result;
                }
            } else {
                $appendOutput('composer install', 'composer not found in PATH — skipped. Run composer install via SSH.', 0);
                try {
                    Log::warning('UpdateService: composer not found — skipping install step.');
                } catch (Throwable) {
                }
            }

            // Migrate
            $migrate = $this->runProcess(['php', 'artisan', 'migrate', '--force'], 60);
            $appendOutput('php artisan migrate --force', $migrate['output'], $migrate['exit']);

            if (! $migrate['success']) {
                $result = $this->buildRunResult(
                    status: 'failed',
                    message: 'Migrations failed — restore from backup if needed; migrations are not auto-rolled back. ' . trim(Str::limit($migrate['output'], 1500)),
                    behind: $behind,
                    from: $fromHash,
                    to: $this->resolveLocalHash(),
                    branch: $branch,
                    remoteSanitized: $remoteSanitized,
                    exit: $migrate['exit'],
                    startedAt: $startedAt,
                    output: Str::limit($capturedOutput, self::OUTPUT_LIMIT)
                );
                $this->audit($actor, $result, $capturedOutput, $behind);

                return $result;
            }

            // Cache clears
            $clears = [
                ['php', 'artisan', 'optimize:clear'],
                ['php', 'artisan', 'config:clear'],
                ['php', 'artisan', 'view:clear'],
            ];

            foreach ($clears as $cmd) {
                $res = $this->runProcess($cmd, 30);
                $appendOutput(implode(' ', $cmd), $res['output'], $res['exit']);
            }

            $toHash = $this->resolveLocalHash();
            $short = $toHash !== null ? substr($toHash, 0, 7) : 'unknown';

            $result = $this->buildRunResult(
                status: 'success',
                message: sprintf('Updated to %s — %d commit(s).', $short, $behind),
                behind: $behind,
                from: $fromHash,
                to: $toHash,
                branch: $branch,
                remoteSanitized: $remoteSanitized,
                exit: 0,
                startedAt: $startedAt,
                output: Str::limit($capturedOutput, self::OUTPUT_LIMIT)
            );
            $this->audit($actor, $result, $capturedOutput, $behind);

            return $result;
        } catch (Throwable $e) {
            Log::error('UpdateService::run failed.', ['error' => $e->getMessage()]);
            $capturedOutput .= "\n[exception] " . $e->getMessage() . "\n";

            $result = $this->buildRunResult(
                status: 'unknown',
                message: 'Update failed: ' . $e->getMessage(),
                behind: 0,
                from: $fromHash,
                to: $this->resolveLocalHash(),
                branch: $branch,
                remoteSanitized: $remoteSanitized,
                exit: 1,
                startedAt: $startedAt,
                output: Str::limit($capturedOutput, self::OUTPUT_LIMIT)
            );
            try {
                $this->audit($actor, $result, $capturedOutput, 0);
            } catch (Throwable) {
            }

            return $result;
        } finally {
            // Ensure site is back up even when a step failed
            try {
                $up = $this->runProcess(['php', 'artisan', 'up'], 15);
                if (! $up['success']) {
                    // Fallback via Artisan facade — covers custom maintenance driver edge cases
                    try {
                        \Illuminate\Support\Facades\Artisan::call('up');
                    } catch (Throwable) {
                    }
                }
            } catch (Throwable) {
                try {
                    \Illuminate\Support\Facades\Artisan::call('up');
                } catch (Throwable) {
                }
            }

            // Persist full output to storage/logs/update.log (append) when available
            if (trim($capturedOutput) !== '') {
                try {
                    $logPath = storage_path('logs/update.log');
                    $entry = sprintf(
                        "[%s] actor=%s status=%s from=%s to=%s\n%s\n---\n",
                        now()->toDateTimeString(),
                        (string) ($actor->id ?? 'unknown'),
                        'run',
                        $fromHash ?? 'null',
                        $this->resolveLocalHash() ?? 'null',
                        Str::limit($capturedOutput, self::OUTPUT_LIMIT)
                    );
                    // Ensure directory exists
                    $dir = dirname($logPath);
                    if (! is_dir($dir)) {
                        @mkdir($dir, 0755, true);
                    }
                    @file_put_contents($logPath, $entry, FILE_APPEND | LOCK_EX);
                } catch (Throwable) {
                }

                try {
                    Log::info('System update attempted.', [
                        'actor' => $actor->id ?? null,
                        'from' => $fromHash,
                        'output_excerpt' => Str::limit($capturedOutput, 2000),
                    ]);
                } catch (Throwable) {
                }
            }

            // Safety: if we did put the app down and artisanal up failed, log
            if ($didDown) {
                try {
                    if (app()->isDownForMaintenance()) {
                        Log::warning('UpdateService: app still in maintenance after run — attempted recovery.');
                    }
                } catch (Throwable) {
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function isGitRepo(): bool
    {
        $res = $this->runProcess(['git', 'rev-parse', '--is-inside-work-tree'], 3);

        return $res['success'] && trim($res['output']) === 'true';
    }

    private function isDirty(): ?bool
    {
        $res = $this->runProcess(['git', 'status', '--porcelain'], 3);

        if (! $res['success']) {
            return null;
        }

        $output = trim($res['output']);
        if ($output === '') {
            return false;
        }
        // Ignore untracked (??) and ignored (!!) — they do not block pull --ff-only
        foreach (explode("\n", $output) as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '??') || str_starts_with($trimmed, '!!')) {
                continue;
            }
            return true;
        }

        return false;
    }

    private function composerAvailable(): bool
    {
        $res = $this->runProcess(['composer', '--version'], 3);

        if ($res['success']) {
            return true;
        }

        // Windows fallback: composer.phar or composer.bat
        foreach (['composer.phar', 'composer.bat'] as $alt) {
            $altRes = $this->runProcess([$alt, '--version'], 3);
            if ($altRes['success']) {
                return true;
            }
        }

        return false;
    }

    private function sanitizeRemote(string $url): string
    {
        $sanitized = preg_replace('#https://[^@]+@#', 'https://***@', $url);

        return $sanitized === null ? $url : $sanitized;
    }

    /**
     * @return array{output: string, exit: int, success: bool}
     */
    private function runProcess(array $cmd, int $timeout = 3): array
    {
        try {
            $process = new Process($cmd, base_path(), null, null, (float) $timeout);
            $process->run();

            $output = $process->getOutput() . $process->getErrorOutput();

            return [
                'output' => $output,
                'exit' => $process->getExitCode() ?? 1,
                'success' => $process->isSuccessful(),
            ];
        } catch (Throwable $e) {
            return [
                'output' => $e->getMessage(),
                'exit' => 1,
                'success' => false,
            ];
        }
    }

    private function getRemoteRaw(): ?string
    {
        $res = $this->runProcess(['git', 'remote', 'get-url', 'origin'], 3);

        if (! $res['success'] || trim($res['output']) === '') {
            return null;
        }

        return trim($res['output']);
    }

    private function resolveLocalHash(): ?string
    {
        $res = $this->runProcess(['git', 'rev-parse', 'HEAD'], 3);

        if (! $res['success'] || trim($res['output']) === '') {
            return null;
        }

        return trim($res['output']);
    }

    private function resolveRemoteHash(): ?string
    {
        $res = $this->runProcess(['git', 'rev-parse', 'origin/main'], 3);

        if ($res['success'] && trim($res['output']) !== '') {
            return trim($res['output']);
        }

        $fallback = $this->runProcess(['git', 'rev-parse', 'origin/master'], 3);

        if ($fallback['success'] && trim($fallback['output']) !== '') {
            return trim($fallback['output']);
        }

        return null;
    }

    private function resolveBranch(): ?string
    {
        $res = $this->runProcess(['git', 'rev-parse', '--abbrev-ref', 'HEAD'], 3);

        if (! $res['success'] || trim($res['output']) === '') {
            return null;
        }

        return trim($res['output']);
    }

    private function resolveDirtyExcerpt(): string
    {
        $res = $this->runProcess(['git', 'status', '--porcelain'], 3);

        if (! $res['success'] || trim($res['output']) === '') {
            return '';
        }

        $lines = explode("\n", trim($res['output']));
        $excerpt = array_slice($lines, 0, 10);

        return implode("\n", $excerpt);
    }

    /**
     * @return list<array{hash: string, short: string, message: string, author: string, date: string}>
     */
    private function resolveCommits(): array
    {
        $res = $this->runProcess(
            ['git', 'log', 'HEAD..origin/main', '--pretty=format:%H%x1f%h%x1f%s%x1f%an%x1f%ad', '--date=short', '-20'],
            3
        );

        if (! $res['success'] || trim($res['output']) === '') {
            // Try master fallback
            $res = $this->runProcess(
                ['git', 'log', 'HEAD..origin/master', '--pretty=format:%H%x1f%h%x1f%s%x1f%an%x1f%ad', '--date=short', '-20'],
                3
            );
        }

        if (! $res['success'] || trim($res['output']) === '') {
            return [];
        }

        $commits = [];
        $lines = explode("\n", trim($res['output']));

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $parts = explode("\x1f", $line);

            if (count($parts) < 5) {
                continue;
            }

            $commits[] = [
                'hash' => $parts[0],
                'short' => $parts[1],
                'message' => $parts[2],
                'author' => $parts[3],
                'date' => $parts[4],
            ];
        }

        return $commits;
    }

    private function resolveDiffStat(): ?string
    {
        $res = $this->runProcess(['git', 'diff', '--stat', 'HEAD..origin/main'], 3);

        if ($res['success'] && trim($res['output']) !== '') {
            return trim($res['output']);
        }

        $fallback = $this->runProcess(['git', 'diff', '--stat', 'HEAD..origin/master'], 3);

        if ($fallback['success'] && trim($fallback['output']) !== '') {
            return trim($fallback['output']);
        }

        return $res['success'] ? trim($res['output']) ?: null : null;
    }

    /**
     * @return array{status: string, message: string, behind: int, from: string|null, to: string|null, branch: string|null, remoteSanitized: string|null, exit: int, durationMs: int, output: string}
     */
    private function buildRunResult(
        string $status,
        string $message,
        int $behind,
        ?string $from,
        ?string $to,
        ?string $branch,
        ?string $remoteSanitized,
        int $exit,
        float $startedAt,
        string $output
    ): array {
        return [
            'status' => $status,
            'message' => $message,
            'behind' => $behind,
            'from' => $from,
            'to' => $to,
            'branch' => $branch,
            'remoteSanitized' => $remoteSanitized,
            'exit' => $exit,
            'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
            'output' => Str::limit($output, self::OUTPUT_LIMIT),
        ];
    }

    /**
     * Write activity_log row and best-effort Log::info.
     *
     * @param  array<string, mixed>  $result
     */
    private function audit(User $actor, array $result, string $fullOutput, int $behind): void
    {
        $from = $result['from'] ?? null;
        $to = $result['to'] ?? null;
        $exit = $result['exit'] ?? 1;
        $status = $result['status'] ?? 'unknown';
        $durationMs = $result['durationMs'] ?? 0;

        $shortFrom = is_string($from) ? substr($from, 0, 7) : 'unknown';
        $shortTo = is_string($to) ? substr($to, 0, 7) : 'unknown';

        $description = sprintf(
            'System updated %s %s → %s (%d commits) [%s]',
            $result['branch'] ?? 'main',
            $shortFrom,
            $shortTo,
            $behind,
            $status
        );

        $metadata = [
            'from' => $from,
            'to' => $to,
            'behind' => $behind,
            'exit' => $exit,
            'status' => $status,
            'duration_ms' => $durationMs,
            'output_excerpt' => Str::limit($fullOutput, self::OUTPUT_LIMIT),
            'triggered_by' => $actor->id ?? null,
            'branch' => $result['branch'] ?? null,
            'remote' => $result['remoteSanitized'] ?? null,
        ];

        try {
            $ip = null;
            $userAgent = null;
            try {
                $ip = request()->ip();
                $userAgent = request()->userAgent();
            } catch (Throwable) {
            }

            DB::table('activity_log')->insert([
                'user_id' => $actor->id ?? null,
                'customer_id' => null,
                'action' => 'system.updated',
                'description' => $description,
                'metadata' => json_encode($metadata),
                'properties' => json_encode($metadata),
                'event' => 'updated',
                'subject_type' => 'system',
                'subject_id' => null,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Audit must never break the update flow
            try {
                Log::warning('UpdateService: activity_log insert failed.', ['error' => $e->getMessage()]);
            } catch (Throwable) {
            }
        }

        try {
            Log::info('System update result.', [
                'status' => $status,
                'from' => $from,
                'to' => $to,
                'behind' => $behind,
                'exit' => $exit,
                'duration_ms' => $durationMs,
                'actor' => $actor->id ?? null,
            ]);
        } catch (Throwable) {
        }
    }

    /**
     * Fetch the last $limit commits from the GitHub API (no auth required for public repos).
     *
     * @return array{remoteHash: string, commits: list<array{hash: string, short: string, message: string, author: string, date: string}>}|null
     */
    private function fetchGithubCommits(int $limit = 5): ?array
    {
        // Only cache successful results — failed/rate-limited responses must not
        // block retries for 5 minutes.
        if (Cache::has('system.github_commits')) {
            $cached = Cache::get('system.github_commits');
            if (is_array($cached)) {
                return $cached;
            }
        }

        try {
            $response = Http::timeout(8)
                ->withHeaders(['Accept' => 'application/vnd.github.v3+json', 'User-Agent' => 'ManageHosting-CRM'])
                ->get('https://api.github.com/repos/Sanat-das/Manage-Hosting-CRM/commits', ['per_page' => $limit, 'sha' => 'main']);

            if (! $response->successful()) {
                Log::warning('UpdateService: GitHub API returned non-2xx.', [
                    'status' => $response->status(),
                    'body'   => substr($response->body(), 0, 300),
                ]);
                return null;
            }

            $items = $response->json();
            if (! is_array($items)) {
                return null;
            }

            $commits = [];
            foreach ($items as $item) {
                $hash = $item['sha'] ?? '';
                $commits[] = [
                    'hash'    => $hash,
                    'short'   => substr($hash, 0, 7),
                    'message' => $item['commit']['message'] ?? '',
                    'author'  => $item['commit']['author']['name'] ?? '',
                    'date'    => $item['commit']['author']['date'] ?? '',
                ];
            }

            $result = [
                'remoteHash' => $commits[0]['hash'] ?? null,
                'commits'    => $commits,
            ];

            Cache::put('system.github_commits', $result, 300);

            return $result;
        } catch (Throwable $e) {
            Log::warning('UpdateService: GitHub API call failed.', [
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);
            return null;
        }
    }

    public function flushApiCache(): void
    {
        Cache::forget('system.github_commits');
    }
}
