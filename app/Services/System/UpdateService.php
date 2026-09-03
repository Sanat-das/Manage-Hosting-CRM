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
     * @param  callable(string,string,int,bool,array):void|null  $emit  Optional progress emitter: ($step, $message, $progress, $done, $extra)
     * @return array{status: string, message: string, behind: int, from: string|null, to: string|null, branch: string|null, remoteSanitized: string|null, exit: int, durationMs: int, output: string}
     */
    public function run(User $actor, ?callable $emit = null): array
    {
        $emit ??= static function (string $step, string $message, int $progress, bool $done = false, array $extra = []): void {};
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
                return $this->runZip($actor, $emit);
            }

            if ($remoteRaw === null || trim($remoteRaw) === '') {
                $result = $this->buildRunResult(
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
                $emit('error', $result['message'], 0, true, $result);
                return $result;
            }

            if ($this->isDirty() === true) {
                $excerpt = $this->resolveDirtyExcerpt();

                $result = $this->buildRunResult(
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
                $emit('error', $result['message'], 0, true, $result);
                return $result;
            }

            // Step: Fetch
            $emit('fetch', 'Fetching latest changes...', 15);
            $fetch = $this->runProcess(['git', 'fetch', 'origin'], 15);
            $appendOutput('git fetch origin', $fetch['output'], $fetch['exit']);

            if (! $fetch['success']) {
                $result = $this->buildRunResult(
                    status: 'fetch_failed',
                    message: 'Could not reach update server — check network connection.',
                    behind: 0,
                    from: $fromHash,
                    to: null,
                    branch: $branch,
                    remoteSanitized: $remoteSanitized,
                    exit: $fetch['exit'],
                    startedAt: $startedAt,
                    output: Str::limit($capturedOutput, self::OUTPUT_LIMIT)
                );
                $emit('error', $result['message'], 15, true, $result);
                return $result;
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
                $emit('done', $result['message'], 100, true, $result);
                return $result;
            }

            // Step: Maintenance mode
            $emit('maintenance', 'Enabling maintenance mode...', 30);
            $down = $this->runProcess(['php', 'artisan', 'down', '--secret=' . Str::random(16)], 15);
            $appendOutput('php artisan down', $down['output'], $down['exit']);
            $didDown = $down['success'] || str_contains(strtolower($down['output']), 'already');

            // Step: Pull
            $emit('pull', 'Downloading and applying update...', 45);
            $pull = $this->runProcess(['git', 'pull', '--ff-only', 'origin', 'main'], 60);
            $appendOutput('git pull --ff-only origin main', $pull['output'], $pull['exit']);

            if (! $pull['success']) {
                $message = 'Update could not be applied — the working directory has uncommitted changes. Please contact support or resolve via SSH.';
                if (str_contains($pull['output'], 'permission denied') || str_contains(strtolower($pull['output']), 'permission')) {
                    $message = 'App directory is not writable — fix filesystem permissions and retry.';
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
                $emit('error', $result['message'], 45, true, $result);
                return $result;
            }

            // Step: Composer
            $emit('composer', 'Installing dependencies...', 60);
            if ($this->composerAvailable()) {
                $composer = $this->runProcess(['composer', 'install', '--no-dev', '--optimize-autoloader', '--no-interaction'], 120);
                $appendOutput('composer install --no-dev --optimize-autoloader --no-interaction', $composer['output'], $composer['exit']);

                if (! $composer['success']) {
                    $result = $this->buildRunResult(
                        status: 'failed',
                        message: 'Update downloaded but dependencies failed — run composer install manually. ' . trim(Str::limit($composer['output'], 1500)),
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
                    $emit('error', $result['message'], 60, true, $result);
                    return $result;
                }
            } else {
                $appendOutput('composer install', 'composer not found in PATH — skipped. Run composer install via SSH.', 0);
                try {
                    Log::warning('UpdateService: composer not found — skipping install step.');
                } catch (Throwable) {
                }
            }

            // Step: Migrate (additive only — existing data is preserved)
            $emit('migrate', 'Updating database schema (your data is preserved)...', 75);
            $migrate = $this->runProcess(['php', 'artisan', 'migrate', '--force'], 60);
            $appendOutput('php artisan migrate --force', $migrate['output'], $migrate['exit']);

            if (! $migrate['success']) {
                $result = $this->buildRunResult(
                    status: 'failed',
                    message: 'Database update failed — your existing data is intact. Rollback the code or contact support. ' . trim(Str::limit($migrate['output'], 1500)),
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
                $emit('error', $result['message'], 75, true, $result);
                return $result;
            }

            // Step: Cache clears
            $emit('cache', 'Clearing application cache...', 88);
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
                message: sprintf('Successfully updated to version %s (%d commit(s) applied).', $short, $behind),
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
            $emit('done', $result['message'], 100, true, $result);
            return $result;
        } catch (Throwable $e) {
            Log::error('UpdateService::run failed.', ['error' => $e->getMessage()]);
            $capturedOutput .= "\n[exception] " . $e->getMessage() . "\n";

            $result = $this->buildRunResult(
                status: 'unknown',
                message: 'Update failed unexpectedly. Please contact support.',
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
            $emit('error', $result['message'], 0, true, $result);
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

    /**
     * ZIP-based update for installations without git.
     * Downloads the GitHub zipball, extracts it, deploys files (preserving .env / storage / install.lock),
     * then runs composer install, migrate, and cache clear — the same chain as run().
     *
     * @return array{status: string, message: string, behind: int, from: string|null, to: string|null, branch: string|null, remoteSanitized: string|null, exit: int, durationMs: int, output: string}
     */
    public function runZip(User $actor, ?callable $emit = null): array
    {
        $emit ??= static function (string $step, string $message, int $progress, bool $done = false, array $extra = []): void {};
        $startedAt   = microtime(true);
        $capturedOutput = '';
        $appRoot     = base_path();
        $remoteSanitized = 'https://github.com/Sanat-das/Manage-Hosting-CRM';
        $fromVersion = $this->resolveLocalVersion();
        $rand        = Str::random(8);
        $tmpDir      = storage_path('tmp' . DIRECTORY_SEPARATOR . 'mh_update_' . $rand);
        $zipPath     = storage_path('tmp' . DIRECTORY_SEPARATOR . 'mh_update_' . $rand . '.zip');
        $didDown     = false;

        $appendOutput = function (string $label, string $output, int $exit) use (&$capturedOutput): void {
            $capturedOutput .= sprintf("\n[%s] exit=%d\n%s\n", $label, $exit, trim($output));
        };

        // Allow long-running download+extract+composer on IIS/FastCGI where the default
        // PHP max_execution_time (30s) would otherwise kill the process mid-download.
        @set_time_limit(0);

        // Shared log path used by sentinel, step checkpoints, and finally block.
        $logPath = storage_path('logs/update.log');
        $logDir  = dirname($logPath);
        if (! is_dir($logDir)) { @mkdir($logDir, 0755, true); }

        // Checkpoint helper — writes a timestamped line immediately to update.log so every
        // step is traceable even if the process is killed before finally runs.
        $checkpoint = static function (string $entry) use ($logPath): void {
            @file_put_contents($logPath, '[' . date('Y-m-d H:i:s') . '] ' . $entry . "\n", FILE_APPEND | LOCK_EX);
        };

        // Sentinel — always written first so we know runZip() was invoked.
        $curlDiag    = $this->findCurlBin() ?? 'not found';
        $tmpSys      = sys_get_temp_dir();
        $tmpWritable = is_writable($tmpSys) ? 'yes' : 'no';
        $checkpoint(sprintf(
            'actor=%s method=zip status=started from=%s curl=%s tmpdir=%s writable=%s zippath=%s',
            $actor->id ?? 'unknown', $fromVersion, $curlDiag, $tmpSys, $tmpWritable, $zipPath
        ));

        try {
            if (! class_exists(\ZipArchive::class)) {
                $result = $this->buildRunResult('failed', 'PHP ZipArchive extension is not available — enable the zip extension or update via SSH.', 0, $fromVersion, null, 'main', $remoteSanitized, 1, $startedAt, 'ZipArchive not available.');
                $emit('error', $result['message'], 0, true, $result);
                return $result;
            }

            @mkdir(storage_path('tmp'), 0755, true);
        @mkdir($tmpDir, 0755, true);

            // Step: Download — emit heartbeats every 5s so IIS FastCGI activityTimeout doesn't fire
            $checkpoint('step=download status=starting');
            $emit('download', 'Downloading latest update from GitHub...', 10);
            $zipUrl    = 'https://api.github.com/repos/Sanat-das/Manage-Hosting-CRM/zipball/main';
            $heartbeat = function () use ($emit): void {
                $emit('download', 'Downloading latest update from GitHub...', 10);
            };
            $downloaded = $this->downloadZip($zipUrl, $zipPath, $heartbeat);
            $sizeMb = is_file($zipPath) ? number_format((float) (filesize($zipPath) / 1024 / 1024), 1) : '0';
            $checkpoint('step=download status=' . ($downloaded ? 'done size=' . $sizeMb . 'MB' : 'failed'));
            $appendOutput('download zip', $downloaded ? 'Downloaded ' . $sizeMb . ' MB' : 'Download failed', $downloaded ? 0 : 1);

            if (! $downloaded) {
                $result = $this->buildRunResult('fetch_failed', 'Could not download update from GitHub — check network connection and try again.', 0, $fromVersion, null, 'main', $remoteSanitized, 1, $startedAt, $capturedOutput);
                $emit('error', $result['message'], 10, true, $result);
                return $result;
            }

            // Step: Extract — non-blocking so heartbeats keep IIS activityTimeout from firing
            $checkpoint('step=extract status=starting method=auto');
            $emit('extract', 'Unpacking update files...', 30);
            $extractTick = 0;
            $extractHeartbeat = function () use ($emit, $checkpoint, &$extractTick): void {
                $extractTick++;
                $checkpoint('step=extract status=running tick=' . $extractTick . ' elapsed=' . ($extractTick * 5) . 's');
                $emit('extract', 'Unpacking update files...', 30);
            };
            $extractedRoot = $this->extractZip($zipPath, $tmpDir, $extractHeartbeat);
            $checkpoint('step=extract status=' . ($extractedRoot !== null ? 'done root=' . basename($extractedRoot) : 'failed'));
            $appendOutput('extract zip', $extractedRoot !== null ? 'Extracted to ' . basename($extractedRoot) : 'Extraction failed', $extractedRoot !== null ? 0 : 1);

            if ($extractedRoot === null) {
                $result = $this->buildRunResult('failed', 'Could not extract update archive. The disk may be full or the download was corrupted.', 0, $fromVersion, null, 'main', $remoteSanitized, 1, $startedAt, $capturedOutput);
                $emit('error', $result['message'], 30, true, $result);
                return $result;
            }

            // Step: Maintenance mode
            $checkpoint('step=maintenance status=starting');
            $emit('maintenance', 'Enabling maintenance mode...', 42);
            $down   = $this->runProcess(['php', 'artisan', 'down', '--secret=' . Str::random(16)], 15);
            $appendOutput('php artisan down', $down['output'], $down['exit']);
            $didDown = $down['success'] || str_contains(strtolower($down['output']), 'already');
            $checkpoint('step=maintenance status=' . ($didDown ? 'done' : 'warn exit=' . $down['exit']));

            // Step: Deploy files (preserve .env, storage/, install.lock)
            $checkpoint('step=deploy status=starting');
            $emit('deploy', 'Installing update files...', 55);
            $preserve = ['.env', 'storage', 'install.lock', 'public' . DIRECTORY_SEPARATOR . 'storage'];
            try {
                $this->syncDeploy($extractedRoot, $appRoot, $preserve);
                $checkpoint('step=deploy status=done');
                $appendOutput('sync deploy', 'Files deployed successfully.', 0);
            } catch (Throwable $e) {
                $checkpoint('step=deploy status=failed err=' . $e->getMessage());
                $appendOutput('sync deploy', $e->getMessage(), 1);
                $result = $this->buildRunResult('failed', 'File deployment failed: ' . $e->getMessage(), 0, $fromVersion, null, 'main', $remoteSanitized, 1, $startedAt, $capturedOutput);
                $emit('error', $result['message'], 55, true, $result);
                return $result;
            }

            // Step: Composer
            $checkpoint('step=composer status=starting');
            $emit('composer', 'Installing dependencies...', 65);
            if ($this->composerAvailable()) {
                $composer = $this->runProcess(['composer', 'install', '--no-dev', '--optimize-autoloader', '--no-interaction'], 180);
                $appendOutput('composer install --no-dev --optimize-autoloader --no-interaction', $composer['output'], $composer['exit']);
                $checkpoint('step=composer status=' . ($composer['success'] ? 'done' : 'failed exit=' . $composer['exit']));
                if (! $composer['success']) {
                    $result = $this->buildRunResult('failed', 'Files updated but dependencies failed — run composer install via SSH. ' . Str::limit($composer['output'], 500), 0, $fromVersion, null, 'main', $remoteSanitized, $composer['exit'], $startedAt, $capturedOutput);
                    $this->audit($actor, $result, $capturedOutput, 0);
                    $emit('error', $result['message'], 65, true, $result);
                    return $result;
                }
            } else {
                $checkpoint('step=composer status=skipped (not in PATH)');
                $appendOutput('composer install', 'composer not found in PATH — skipped. Run composer install via SSH.', 0);
                try { Log::warning('UpdateService: composer not found during ZIP update.'); } catch (Throwable) {}
            }

            // Step: Migrate
            $checkpoint('step=migrate status=starting');
            $emit('migrate', 'Updating database schema (your data is preserved)...', 78);
            $migrate = $this->runProcess(['php', 'artisan', 'migrate', '--force'], 60);
            $appendOutput('php artisan migrate --force', $migrate['output'], $migrate['exit']);
            $checkpoint('step=migrate status=' . ($migrate['success'] ? 'done' : 'failed exit=' . $migrate['exit']));
            if (! $migrate['success']) {
                $result = $this->buildRunResult('failed', 'Database update failed — your existing data is intact. Contact support. ' . Str::limit($migrate['output'], 500), 0, $fromVersion, null, 'main', $remoteSanitized, $migrate['exit'], $startedAt, $capturedOutput);
                $this->audit($actor, $result, $capturedOutput, 0);
                $emit('error', $result['message'], 78, true, $result);
                return $result;
            }

            // Step: Cache clears
            $checkpoint('step=cache status=starting');
            $emit('cache', 'Clearing application cache...', 88);
            foreach ([['php', 'artisan', 'optimize:clear'], ['php', 'artisan', 'config:clear'], ['php', 'artisan', 'view:clear']] as $cmd) {
                $res = $this->runProcess($cmd, 30);
                $appendOutput(implode(' ', $cmd), $res['output'], $res['exit']);
            }
            $checkpoint('step=cache status=done');

            // Write VERSION with the remote commit hash so next check knows the installed version
            $toVersion = null;
            try {
                $api = $this->fetchGithubCommits(1);
                $toVersion = $api['remoteHash'] ?? null;
                if ($toVersion !== null) {
                    file_put_contents(base_path('VERSION'), substr($toVersion, 0, 7));
                }
            } catch (Throwable) {}

            $short  = $toVersion ? substr($toVersion, 0, 7) : 'latest';
            $checkpoint('step=done version=' . $short);
            $result = $this->buildRunResult('success', sprintf('Successfully updated to version %s.', $short), 0, $fromVersion, $toVersion, 'main', $remoteSanitized, 0, $startedAt, $capturedOutput);
            $this->audit($actor, $result, $capturedOutput, 0);
            $emit('done', $result['message'], 100, true, $result);
            return $result;

        } catch (Throwable $e) {
            Log::error('UpdateService::runZip failed.', ['error' => $e->getMessage()]);
            $capturedOutput .= "\n[exception] " . $e->getMessage() . "\n";
            $result = $this->buildRunResult('unknown', 'Update failed unexpectedly. Please contact support.', 0, $fromVersion, null, 'main', $remoteSanitized, 1, $startedAt, Str::limit($capturedOutput, self::OUTPUT_LIMIT));
            try { $this->audit($actor, $result, $capturedOutput, 0); } catch (Throwable) {}
            $emit('error', $result['message'], 0, true, $result);
            return $result;
        } finally {
            // Bring site back up even on failure
            try {
                $up = $this->runProcess(['php', 'artisan', 'up'], 15);
                if (! $up['success']) {
                    try { \Illuminate\Support\Facades\Artisan::call('up'); } catch (Throwable) {}
                }
            } catch (Throwable) {
                try { \Illuminate\Support\Facades\Artisan::call('up'); } catch (Throwable) {}
            }

            // Log full captured output to update.log (always — records killed/timed-out runs)
            try {
                $body = trim($capturedOutput) !== '' ? Str::limit($capturedOutput, self::OUTPUT_LIMIT) : '(no output — process may have been killed mid-step)';
                @file_put_contents($logPath, sprintf("[%s] actor=%s method=zip from=%s\n%s\n---\n", now()->toDateTimeString(), (string) ($actor->id ?? 'unknown'), $fromVersion, $body), FILE_APPEND | LOCK_EX);
            } catch (Throwable) {}

            // Clean up temp files
            try {
                if (is_file($zipPath)) { @unlink($zipPath); }
                if (is_dir($tmpDir))   { $this->rrmdir($tmpDir); }
            } catch (Throwable) {}
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

    private function resolveLocalVersion(): string
    {
        $ver = trim((string) (is_file(base_path('VERSION')) ? file_get_contents(base_path('VERSION')) : config('app.version', 'dev')));

        return $ver !== '' ? $ver : 'dev';
    }

    /**
     * Download a ZIP from $url into $destPath.
     *
     * Prefers a non-blocking curl child process so the caller can emit SSE heartbeats
     * every 5 s while waiting — this keeps the IIS FastCGI activityTimeout from firing
     * during long downloads.  Falls back to Laravel's blocking Http::sink() when curl
     * is not available.
     */
    private function downloadZip(string $url, string $destPath, ?callable $heartbeat = null): bool
    {
        $heartbeat ??= static function (): void {};
        $caBundle   = storage_path('cacert.pem');

        // ── Non-blocking curl process (preferred on IIS / Windows Server) ──────
        $curlBin = $this->findCurlBin();
        if ($curlBin !== null) {
            try {
                $cmd = [
                    $curlBin, '-L', '--silent', '--show-error',
                    '-H', 'Accept: application/vnd.github.v3+json',
                    '-H', 'User-Agent: ManageHosting-CRM',
                    '-o', $destPath,
                ];
                if (is_file($caBundle)) {
                    array_push($cmd, '--cacert', $caBundle);
                }
                $cmd[] = $url;

                $process = new Process($cmd, base_path(), null, null, 300.0);
                $process->start();

                while ($process->isRunning()) {
                    $heartbeat();
                    sleep(5);
                }
                $process->wait();

                $curlExit  = $process->getExitCode();
                $curlSize  = is_file($destPath) ? filesize($destPath) : 0;
                $curlError = substr($process->getErrorOutput(), 0, 300);
                @file_put_contents(storage_path('logs/update.log'), sprintf("[%s] curl-done: exit=%d size=%d err=%s\n", now()->toDateTimeString(), $curlExit ?? -1, $curlSize, $curlError ?: 'none'), FILE_APPEND | LOCK_EX);

                if ($process->isSuccessful() && $curlSize > 0) {
                    return true;
                }

                Log::warning('UpdateService: curl ZIP download failed.', [
                    'exit'  => $curlExit,
                    'error' => $curlError,
                ]);
            } catch (Throwable $e) {
                Log::warning('UpdateService: curl process exception.', ['error' => $e->getMessage()]);
            }
        }

        // ── Fallback: blocking Laravel HTTP client ────────────────────────────
        try {
            $client = Http::timeout(300)
                ->withHeaders(['Accept' => 'application/vnd.github.v3+json', 'User-Agent' => 'ManageHosting-CRM']);
            if (is_file($caBundle)) {
                $client = $client->withOptions(['verify' => $caBundle]);
            }
            $response = $client->sink($destPath)->get($url);

            return $response->successful() && is_file($destPath) && filesize($destPath) > 0;
        } catch (Throwable $e) {
            Log::warning('UpdateService: ZIP download failed.', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function findCurlBin(): ?string
    {
        foreach (['curl', 'curl.exe'] as $bin) {
            $res = $this->runProcess([$bin, '--version'], 3);
            if ($res['success']) {
                return $bin;
            }
        }

        return null;
    }

    /**
     * Extract a ZIP archive and return the path to the single root directory inside it
     * (GitHub zipballs always wrap everything in one top-level directory).
     *
     * Prefers a non-blocking PowerShell Expand-Archive child process so the caller can
     * emit SSE heartbeats every 5 s — prevents IIS FastCGI activityTimeout during the
     * (potentially long) extraction of a large ZIP.  Falls back to ZipArchive.
     */
    private function extractZip(string $zipPath, string $destDir, ?callable $heartbeat = null): ?string
    {
        @mkdir($destDir, 0755, true);
        $heartbeat ??= static function (): void {};

        $logCtx = ['zip' => basename($zipPath), 'dest' => $destDir];

        // ── 1. tar (ships with Windows Server 2022) — fast but only used when it
        //        produces no stderr, which means no files were skipped/corrupted.
        $tarCheck = $this->runProcess(['tar', '--version'], 3);
        if ($tarCheck['success']) {
            Log::info('UpdateService: extracting via tar.', $logCtx);
            try {
                $process = new Process(
                    ['tar', '-xf', $zipPath, '-C', $destDir],
                    base_path(), null, null, 300.0
                );
                $process->start();
                while ($process->isRunning()) {
                    $heartbeat();
                    sleep(5);
                }
                $process->wait();

                $tarErr = trim($process->getErrorOutput());
                if ($process->isSuccessful() && $tarErr === '') {
                    $entries = glob($destDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
                    if (! empty($entries)) {
                        Log::info('UpdateService: tar extraction succeeded.', $logCtx);
                        return $entries[0];
                    }
                }

                Log::warning('UpdateService: tar extraction incomplete/failed — falling through to PowerShell.', array_merge($logCtx, [
                    'exit'   => $process->getExitCode(),
                    'stderr' => substr($tarErr, 0, 500),
                ]));

                // Clean up partially extracted files before retrying
                if (is_dir($destDir)) {
                    $this->rrmdir($destDir);
                    @mkdir($destDir, 0755, true);
                }
            } catch (Throwable $e) {
                Log::warning('UpdateService: tar extract exception.', array_merge($logCtx, ['error' => $e->getMessage()]));
            }
        } else {
            Log::info('UpdateService: tar not available, trying PowerShell.', $logCtx);
        }

        // ── 2. PowerShell Expand-Archive ─────────────────────────────────────
        $psCheck = $this->runProcess(['powershell', '-Command', 'echo ok'], 5);
        if ($psCheck['success']) {
            Log::info('UpdateService: extracting via PowerShell Expand-Archive.', $logCtx);
            try {
                $safeZip  = str_replace("'", "''", $zipPath);
                $safeDest = str_replace("'", "''", $destDir);
                $process  = new Process(
                    ['powershell', '-NoProfile', '-NonInteractive', '-Command',
                     "Expand-Archive -LiteralPath '" . $safeZip . "' -DestinationPath '" . $safeDest . "' -Force"],
                    base_path(), null, null, 300.0
                );
                $process->start();
                while ($process->isRunning()) {
                    $heartbeat();
                    sleep(5);
                }
                $process->wait();

                if ($process->isSuccessful()) {
                    $entries = glob($destDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
                    if (! empty($entries)) {
                        Log::info('UpdateService: PowerShell extraction succeeded.', $logCtx);
                        return $entries[0];
                    }
                }

                Log::warning('UpdateService: PowerShell Expand-Archive failed.', array_merge($logCtx, [
                    'exit'  => $process->getExitCode(),
                    'error' => substr($process->getErrorOutput(), 0, 500),
                ]));
            } catch (Throwable $e) {
                Log::warning('UpdateService: PowerShell extract exception.', array_merge($logCtx, ['error' => $e->getMessage()]));
            }
        }

        // ── 3. Last resort: blocking ZipArchive ──────────────────────────────
        if (! class_exists(\ZipArchive::class)) {
            Log::warning('UpdateService: ZipArchive not available.', $logCtx);
            return null;
        }
        Log::info('UpdateService: extracting via ZipArchive (blocking).', $logCtx);
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            Log::warning('UpdateService: ZipArchive::open failed.', $logCtx);
            return null;
        }
        $zip->extractTo($destDir);
        $zip->close();

        $entries = glob($destDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
        return ! empty($entries) ? $entries[0] : null;
    }

    /**
     * Recursively copy files from $srcDir into $destDir, skipping paths listed in $preserve.
     * $preserve entries are relative to $destDir (e.g. '.env', 'storage', 'install.lock').
     */
    private function syncDeploy(string $srcDir, string $destDir, array $preserve): void
    {
        $sep = DIRECTORY_SEPARATOR;
        $normalizedPreserve = array_map(
            static fn ($p) => rtrim(str_replace(['/', '\\'], $sep, $p), $sep),
            $preserve
        );

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($srcDir) + 1);

            // Skip any path that matches or is under a preserved prefix
            foreach ($normalizedPreserve as $p) {
                if ($relativePath === $p || str_starts_with($relativePath, $p . $sep)) {
                    continue 2;
                }
            }

            $destPath = $destDir . $sep . $relativePath;

            if ($item->isDir()) {
                if (! is_dir($destPath)) {
                    @mkdir($destPath, 0755, true);
                }
            } else {
                $destParent = dirname($destPath);
                if (! is_dir($destParent)) {
                    @mkdir($destParent, 0755, true);
                }
                copy($item->getPathname(), $destPath);
            }
        }
    }

    /** Recursively delete a directory. */
    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
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
            $caBundle = storage_path('cacert.pem');
            $client = Http::timeout(8)
                ->withHeaders(['Accept' => 'application/vnd.github.v3+json', 'User-Agent' => 'ManageHosting-CRM']);
            if (is_file($caBundle)) {
                $client = $client->withOptions(['verify' => $caBundle]);
            }
            $response = $client->get('https://api.github.com/repos/Sanat-das/Manage-Hosting-CRM/commits', ['per_page' => $limit, 'sha' => 'main']);

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

    /**
     * Roll back code to a previous commit hash. Database schema changes are NOT reversed.
     *
     * @return array{status: string, message: string, behind: int, from: string|null, to: string|null, branch: string|null, remoteSanitized: string|null, exit: int, durationMs: int, output: string}
     */
    public function rollback(string $fromHash, User $actor): array
    {
        $startedAt = microtime(true);
        $capturedOutput = '';
        $currentHash = $this->resolveLocalHash();
        $branch = $this->resolveBranch();
        $remoteRaw = $this->getRemoteRaw();
        $remoteSanitized = $remoteRaw !== null ? $this->sanitizeRemote($remoteRaw) : null;

        $appendOutput = function (string $label, string $output, int $exit) use (&$capturedOutput): void {
            $capturedOutput .= sprintf("\n[%s] exit=%d\n%s\n", $label, $exit, trim($output));
        };

        try {
            if (! $this->isGitRepo()) {
                return $this->buildRunResult('no_git', 'Not a git repository — cannot rollback.', 0, $currentHash, null, $branch, $remoteSanitized, 1, $startedAt, 'Not a git repository.');
            }

            if (! preg_match('/^[0-9a-f]{7,40}$/i', $fromHash)) {
                return $this->buildRunResult('failed', 'Invalid rollback target hash.', 0, $currentHash, null, $branch, $remoteSanitized, 1, $startedAt, 'Invalid hash.');
            }

            $down = $this->runProcess(['php', 'artisan', 'down', '--secret=' . Str::random(16)], 15);
            $appendOutput('php artisan down', $down['output'], $down['exit']);

            $reset = $this->runProcess(['git', 'reset', '--hard', $fromHash], 30);
            $appendOutput('git reset --hard ' . $fromHash, $reset['output'], $reset['exit']);

            if (! $reset['success']) {
                return $this->buildRunResult('failed', 'Rollback failed — could not reset to the previous version. Check logs.', 0, $currentHash, null, $branch, $remoteSanitized, $reset['exit'], $startedAt, Str::limit($capturedOutput, self::OUTPUT_LIMIT));
            }

            if ($this->composerAvailable()) {
                $composer = $this->runProcess(['composer', 'install', '--no-dev', '--optimize-autoloader', '--no-interaction'], 120);
                $appendOutput('composer install', $composer['output'], $composer['exit']);
            }

            foreach ([['php', 'artisan', 'optimize:clear'], ['php', 'artisan', 'config:clear'], ['php', 'artisan', 'view:clear']] as $cmd) {
                $res = $this->runProcess($cmd, 30);
                $appendOutput(implode(' ', $cmd), $res['output'], $res['exit']);
            }

            $restoredHash = $this->resolveLocalHash();
            $short = $restoredHash !== null ? substr($restoredHash, 0, 7) : substr($fromHash, 0, 7);

            $result = $this->buildRunResult('success', sprintf('Rolled back to version %s. Note: database schema changes were not reversed.', $short), 0, $currentHash, $restoredHash, $branch, $remoteSanitized, 0, $startedAt, Str::limit($capturedOutput, self::OUTPUT_LIMIT));

            try {
                DB::table('activity_log')->insert([
                    'user_id' => $actor->id ?? null,
                    'customer_id' => null,
                    'action' => 'system.rolledback',
                    'description' => sprintf('System rolled back from %s to %s', substr((string) $currentHash, 0, 7), $short),
                    'metadata' => json_encode(['from' => $currentHash, 'to' => $restoredHash, 'status' => 'success', 'duration_ms' => $result['durationMs']]),
                    'properties' => json_encode(['from' => $currentHash, 'to' => $restoredHash, 'status' => 'success', 'duration_ms' => $result['durationMs']]),
                    'event' => 'rolledback',
                    'subject_type' => 'system',
                    'subject_id' => null,
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                    'created_at' => now(),
                ]);
            } catch (Throwable) {
            }

            return $result;

        } catch (Throwable $e) {
            return $this->buildRunResult('unknown', 'Rollback failed unexpectedly: ' . $e->getMessage(), 0, $currentHash, null, $branch, $remoteSanitized, 1, $startedAt, Str::limit($capturedOutput . "\n" . $e->getMessage(), self::OUTPUT_LIMIT));
        } finally {
            try {
                $up = $this->runProcess(['php', 'artisan', 'up'], 15);
                if (! $up['success']) {
                    try { \Illuminate\Support\Facades\Artisan::call('up'); } catch (Throwable) {}
                }
            } catch (Throwable) {
                try { \Illuminate\Support\Facades\Artisan::call('up'); } catch (Throwable) {}
            }
        }
    }

    public function flushApiCache(): void
    {
        Cache::forget('system.github_commits');
    }
}
