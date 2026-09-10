<?php

declare(strict_types=1);

namespace App\Services\System;

use App\Models\User;
use App\Support\SecretRedactor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Git-driven update orchestration for the About & Update page.
 *
 * check() is read-only and never mutates the working tree.
 * run() executes the guarded chain: down -> pull --ff-only -> composer install -> migrate -> cache clear -> up.
 * runZip() is the same chain for installs without git, sourced from the GitHub zipball.
 *
 * Every mutating entry point (run, runZip, rollback) is serialised by a single
 * cross-process lock — see withUpdateLock(). ZIP deploys snapshot each file
 * before overwriting it, which is what rollback() restores from on installs
 * with no git history to reset to.
 *
 * Every git/process interaction uses Symfony Process with an explicit timeout
 * and never leaks an embedded token — remote URLs are always sanitized.
 */
class UpdateService
{
    public const OUTPUT_LIMIT = 20000;

    /**
     * How many recent commits to pull from the GitHub API.
     *
     * Doubles as the lookback for ZIP installs: the installed commit has to be
     * findable in this window for "commits behind" to be computable at all.
     */
    public const COMMIT_WINDOW = 30;

    /** Cache key for the cross-process lock that serialises every mutating run. */
    public const LOCK_KEY = 'system.update.lock';

    /** Lock TTL. Long enough for a slow ZIP update, short enough to self-clear after a killed process. */
    public const LOCK_SECONDS = 1800;

    /** How many ZIP restore points to keep on disk. */
    public const RESTORE_POINTS_KEPT = 3;

    /** Marks a recent `git fetch origin`, so page renders do not repeat it. */
    public const FETCH_THROTTLE_KEY = 'system.git_fetch_throttle';

    /** How long a successful fetch stays fresh. Matches the GitHub API cache. */
    public const FETCH_THROTTLE_SECONDS = 300;

    /** Cool-off after a failed fetch — shorter, so the page recovers by itself. */
    public const FETCH_FAILURE_COOLOFF_SECONDS = 60;

    /**
     * Floor for "this download is a real archive".
     *
     * A GitHub error body is a few hundred bytes; the zipball is tens of MB.
     * Belt to the magic-byte braces in isZipArchive().
     */
    public const MIN_ZIP_BYTES = 4096;

    /** Re-entrancy flag for withUpdateLock() — run() delegates to runZip() on the same instance. */
    private bool $holdsLock = false;

    /**
     * Read-only check: is the checkout behind origin/main?
     *
     * `remoteUrlRaw` is raw only in the sense of "as git reported it" — runRaw()
     * redacts credentials out of every command's output, so a remote configured
     * with an embedded token never reaches this payload, the session flash, or
     * the JSON response in the clear.
     *
     * @return array{status: string, message: string, behind: int, commits: list<array{hash: string, short: string, message: string, author: string, date: string}>, diffStat: string|null, localHash: string|null, remoteHash: string|null, branch: string|null, remoteSanitized: string|null, remoteUrlRaw: string|null, dirty: bool|null}
     */
    public function check(): array
    {
        // Guard: is this even a git repo?
        if (! $this->isGitRepo()) {
            $api = $this->fetchGithubCommits(self::COMMIT_WINDOW);
            if ($api !== null && ! empty($api['commits'])) {
                $latest        = $api['commits'][0];
                $latestShort   = $latest['short'] ?? substr($latest['hash'] ?? '', 0, 7);
                $localVersion  = $this->resolveLocalVersion();

                // Locate the installed commit in the window. Without a git
                // checkout this is the only available signal, and "behind" used
                // to be simply the number of commits fetched — so a ZIP install
                // advertised an update forever, including straight after one.
                $behind  = null;
                $commits = $api['commits'];

                foreach ($api['commits'] as $i => $commit) {
                    if ($this->isInstalledCommit($localVersion, $commit)) {
                        $behind  = $i;
                        $commits = array_slice($api['commits'], 0, $i);
                        break;
                    }
                }

                if ($behind === 0) {
                    return [
                        'status' => 'up_to_date',
                        'message' => sprintf('Up to date (version %s).', $localVersion),
                        'behind' => 0,
                        'commits' => [],
                        'diffStat' => null,
                        'localHash' => $localVersion,
                        'remoteHash' => $api['remoteHash'],
                        'branch' => 'main',
                        'remoteSanitized' => 'https://github.com/Sanat-das/Manage-Hosting-CRM',
                        'remoteUrlRaw' => 'https://github.com/Sanat-das/Manage-Hosting-CRM.git',
                        'dirty' => null,
                    ];
                }

                // $behind === null: the installed version is not in the window —
                // a placeholder like 1.0.0, or an install older than the lookback.
                // Offer the update, but don't state a commit count we can't back up.
                $message = $behind === null
                    ? sprintf('ZIP install (version %s) — update available. Latest is %s from %s.', $localVersion, $latestShort, $latest['date'] ?? 'unknown')
                    : sprintf('Update available — %d improvement(s) since %s.', $behind, $localVersion);

                return [
                    'status' => 'no_git',
                    'message' => $message,
                    'behind' => $behind ?? count($api['commits']),
                    'commits' => $commits,
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
                'message' => 'This installation was not deployed via git. To update, download the latest ZIP from GitHub, replace files (keep .env, storage/, install.lock), then run composer install --optimize-autoloader && php artisan migrate --force && php artisan optimize:clear.',
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

        // Fetch from origin, at most once per throttle window.
        //
        // check() runs on every render of the About page, and only the fetch
        // needs the network — every other step here is local git state and is
        // left live. Measured on this host: a warm fetch is ~850 ms, but when
        // GitHub is unreachable it blocks for the full 15 s timeout, and it did
        // that on every single page load. The throttle also bounds the repeat
        // cost of the ~29 git subprocesses these two services spawn per render.
        //
        // flushApiCache() clears this, so the explicit "Check for updates"
        // button always talks to the network.
        $fetchState = Cache::get(self::FETCH_THROTTLE_KEY);

        if (! is_array($fetchState)) {
            $fetch = $this->runProcess(['git', 'fetch', 'origin'], 15);

            $fetchState = [
                'ok' => $fetch['success'],
                'error' => $fetch['success'] ? '' : trim(Str::limit($fetch['output'], 500)),
            ];

            // A failed fetch gets a much shorter cool-off: long enough to stop
            // every page load paying the 15 s timeout, short enough that the
            // page recovers on its own once the network does.
            Cache::put(
                self::FETCH_THROTTLE_KEY,
                $fetchState,
                $fetch['success'] ? self::FETCH_THROTTLE_SECONDS : self::FETCH_FAILURE_COOLOFF_SECONDS
            );
        }

        if (! $fetchState['ok']) {
            return [
                'status' => 'fetch_failed',
                'message' => 'Could not reach GitHub — check outbound firewall / proxy. ' . $fetchState['error'],
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

        return $this->withUpdateLock(
            fn (): array => $this->runLocked($actor, $emit),
            fn (): array => $this->busyResult($emit)
        );
    }

    /**
     * Run $callback with the update lock held.
     *
     * run(), runZip() and rollback() all mutate the same working directory and
     * nothing used to stop them overlapping: a double-click, an impatient retry
     * while the detached launcher was still starting, or a second admin was
     * enough to have two `system:run-update` processes pulling and deploying
     * into one tree at the same time.
     *
     * Re-entrant within an instance, because run() delegates to runZip().
     *
     * @template TResult
     * @param  callable(): TResult  $callback
     * @param  callable(): TResult  $onBusy
     * @return TResult
     */
    private function withUpdateLock(callable $callback, callable $onBusy): mixed
    {
        if ($this->holdsLock) {
            return $callback();
        }

        try {
            $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);
        } catch (Throwable $e) {
            // A cache store without lock support must not make the application
            // un-updatable. Log it and run unserialised, as it always did.
            try {
                Log::warning('UpdateService: cache store does not support locks — running without one.', ['error' => $e->getMessage()]);
            } catch (Throwable) {
            }

            return $callback();
        }

        if (! $lock->get()) {
            return $onBusy();
        }

        $this->holdsLock = true;

        try {
            return $callback();
        } finally {
            $this->holdsLock = false;

            // Every mutating entry point funnels through here, so this is the
            // one place that has to invalidate the About card's git snapshot:
            // an update, a rollback or a failed half-deploy all change the
            // commit, the version or the dirty flag it caches. Flushed on
            // failure too — a half-finished run is exactly when a stale card
            // misleads most.
            try {
                AppInfoService::flushCache();
            } catch (Throwable) {
            }

            try {
                $lock->release();
            } catch (Throwable) {
            }
        }
    }

    /**
     * @param  callable(string,string,int,bool,array):void  $emit
     * @return array{status: string, message: string, behind: int, from: string|null, to: string|null, branch: string|null, remoteSanitized: string|null, exit: int, durationMs: int, output: string}
     */
    private function busyResult(callable $emit): array
    {
        $result = $this->buildRunResult(
            'busy',
            'An update is already running — wait for it to finish before starting another.',
            0,
            $this->resolveLocalHash() ?? $this->resolveLocalVersion(),
            null,
            $this->resolveBranch(),
            null,
            1,
            microtime(true),
            'Another update holds the update lock.'
        );

        $emit('error', $result['message'], 0, true, $result);

        return $result;
    }

    /**
     * The guarded update chain itself. Always entered through run(), which holds the lock.
     *
     * @param  callable(string,string,int,bool,array):void  $emit
     * @return array{status: string, message: string, behind: int, from: string|null, to: string|null, branch: string|null, remoteSanitized: string|null, exit: int, durationMs: int, output: string}
     */
    private function runLocked(User $actor, callable $emit): array
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

            // Repair before judging: an earlier release pruned dev packages out
            // of the committed vendor/ tree, and those deletions would otherwise
            // make `git pull --ff-only` refuse to touch the same paths.
            if ($this->restorePrunedVendor()) {
                $appendOutput('git checkout HEAD -- vendor', 'Restored committed dependencies pruned by a previous --no-dev install.', 0);
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

            // Resolved after the fetch, and used for both the behind count and
            // the pull below, so this run can never compare against one branch
            // and pull from another.
            $upstream = $this->resolveUpstreamRef();

            if ($upstream === null) {
                $result = $this->buildRunResult(
                    status: 'failed',
                    message: 'Could not find origin/main or origin/master after fetching — check the default branch on the remote.',
                    behind: 0,
                    from: $fromHash,
                    to: $fromHash,
                    branch: $branch,
                    remoteSanitized: $remoteSanitized,
                    exit: 1,
                    startedAt: $startedAt,
                    output: Str::limit($capturedOutput, self::OUTPUT_LIMIT)
                );
                $this->audit($actor, $result, $capturedOutput, 0);
                $emit('error', $result['message'], 15, true, $result);
                return $result;
            }

            $upstreamBranch = $this->upstreamBranchName($upstream);

            $behindRes = $this->runProcess(['git', 'rev-list', 'HEAD..' . $upstream, '--count'], 3);
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
            $pull = $this->runProcess(['git', 'pull', '--ff-only', 'origin', $upstreamBranch], 60);
            $appendOutput('git pull --ff-only origin ' . $upstreamBranch, $pull['output'], $pull['exit']);

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

            // Step: Composer — vendor/ ships in the repo (IIS/shared-host), so a HOME-related
            // failure is non-fatal when vendor/autoload.php already exists. The runRaw() patch
            // above injects HOME/COMPOSER_HOME, but keep this fallback for pre-patch runs.
            $emit('composer', 'Installing dependencies...', 60);
            if ($this->composerAvailable()) {
                $composerCmd = $this->composerInstall();
                $composer = $this->runProcess($composerCmd, 120);
                $appendOutput(implode(' ', $composerCmd), $composer['output'], $composer['exit']);

                if (! $composer['success']) {
                    $isHomeError = str_contains(strtolower($composer['output']), 'home or composer_home')
                        || str_contains(strtolower($composer['output']), 'the home or composer_home');
                    $isPhpVersionError = str_contains(strtolower($composer['output']), 'your php version')
                        && str_contains(strtolower($composer['output']), 'does not satisfy');
                    $vendorExists = is_file(base_path('vendor/autoload.php'));
                    if ($isHomeError && $vendorExists) {
                        $appendOutput('composer install', 'composer HOME error — vendor/ ships with the update, continuing (migrate will run next).', 0);
                        try { Log::warning('UpdateService: composer HOME error ignored — vendor/ present, continuing update.'); } catch (Throwable) {}
                    } elseif ($isPhpVersionError) {
                        // Shared hosts often have web PHP 8.5.10 but CLI `php`/`composer` still on 8.3.33.
                        // The lock requires >=8.4.1 (symfony/clock 8.1). Vendor ships pre-built, so
                        // retry with --ignore-platform-reqs; if vendor exists we can continue anyway.
                        $appendOutput('composer install', 'PHP version mismatch detected (web PHP ' . PHP_VERSION . ' vs Composer PHP 8.3.33) — retrying with --ignore-platform-reqs...', 0);
                        $composerRetry = $this->runProcess($this->composerInstall(ignorePlatformReqs: true), 180);
                        $appendOutput('composer install --ignore-platform-reqs', $composerRetry['output'], $composerRetry['exit']);
                        if ($composerRetry['success']) {
                            try { Log::warning('UpdateService: composer retry with --ignore-platform-reqs succeeded (PHP mismatch ignored, vendor shipped).'); } catch (Throwable) {}
                        } elseif ($vendorExists) {
                            $appendOutput('composer install', 'Composer still failed but vendor/autoload.php exists — continuing (vendor ships with update, PHP 8.5.10 will run it).', 0);
                            try { Log::warning('UpdateService: composer failed even with --ignore-platform-reqs but vendor exists — continuing.'); } catch (Throwable) {}
                        } else {
                            $result = $this->buildRunResult(
                                status: 'failed',
                                message: 'Dependencies failed: Composer PHP (8.3.33 from PATH) does not match web PHP (' . PHP_VERSION . ') and lock requires >=8.4.1. Fix: cPanel → MultiPHP Manager → set BOTH web and CLI to ea-php84/ea-php85 (or set CLI via cPanel → Terminal → `ln -s /opt/alt/php85/usr/bin/php ~/bin/php`), then retry. Raw: ' . trim(Str::limit($composer['output'], 1500)),
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
                }
            } else {
                $appendOutput('composer install', 'composer not found in PATH — skipped (vendor/ ships with the update).', 0);
                try {
                    Log::warning('UpdateService: composer not found — skipping install step (vendor/ ships with update).');
                } catch (Throwable) {
                }
            }

            // Step: Migrate (additive only — existing data is preserved)
            $emit('migrate', 'Updating database schema (your data is preserved)...', 75);
            $migrate = $this->runProcess(['php', 'artisan', 'migrate', '--force'], 60);
            $appendOutput('php artisan migrate --force', $migrate['output'], $migrate['exit']);

            if (! $migrate['success']) {
                // As in runZip(): the pull above replaced this service on disk,
                // so retry through a fresh child running the new code before
                // treating the failure as real.
                $emit('migrate', 'Retrying with the updated code...', 78);
                $retry = $this->finalizeWithNewCode();
                $appendOutput('php artisan system:update:finalize', $retry['output'], $retry['exit']);

                if (! $retry['success']) {
                    $result = $this->buildRunResult(
                        status: 'failed',
                        message: 'Database update failed — your existing data is intact. Code is updated; finish with: php artisan system:update:finalize. ' . trim(Str::limit($migrate['output'], 1500)),
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

                $migrate = $retry;
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

            $pending = $this->pendingMigrations();

            if ($pending !== null && $pending !== []) {
                $result = $this->buildRunResult(
                    status: 'failed',
                    message: sprintf('Updated to %s but %d migration(s) are still pending (%s) — finish with: php artisan system:update:finalize', $short, count($pending), $this->describePending($pending)),
                    behind: $behind,
                    from: $fromHash,
                    to: $toHash,
                    branch: $branch,
                    remoteSanitized: $remoteSanitized,
                    exit: 1,
                    startedAt: $startedAt,
                    output: Str::limit($capturedOutput, self::OUTPUT_LIMIT)
                );
                $this->audit($actor, $result, $capturedOutput, $behind);
                $emit('error', $result['message'], 95, true, $result);
                return $result;
            }

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

        return $this->withUpdateLock(
            fn (): array => $this->runZipLocked($actor, $emit),
            fn (): array => $this->busyResult($emit)
        );
    }

    /**
     * The ZIP chain itself. Entered through runZip() or run(), both of which hold the lock.
     *
     * @param  callable(string,string,int,bool,array):void  $emit
     * @return array{status: string, message: string, behind: int, from: string|null, to: string|null, branch: string|null, remoteSanitized: string|null, exit: int, durationMs: int, output: string}
     */
    private function runZipLocked(User $actor, callable $emit): array
    {
        $startedAt   = microtime(true);
        $capturedOutput = '';
        $appRoot     = $this->appRoot();
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

            // Last point at which nothing has been mutated: refuse an archive
            // that is not this application before it is copied over the install.
            if (! $this->looksLikeAppRoot($extractedRoot)) {
                $checkpoint('step=verify status=failed reason=archive-not-an-app-root');
                $appendOutput('verify archive', 'Extracted archive is missing artisan/composer.json/app/bootstrap.', 1);
                $result = $this->buildRunResult('failed', 'The downloaded archive does not look like a Manage Hosting release — nothing was changed. Try again, or update via SSH.', 0, $fromVersion, null, 'main', $remoteSanitized, 1, $startedAt, $capturedOutput);
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
            $deployHeartbeat = function () use ($emit, $checkpoint): void {
                $checkpoint('step=deploy status=running');
                $emit('deploy', 'Installing update files...', 55);
            };

            // Snapshot every file before it is overwritten. Only genuinely
            // changed files are copied, so this stays small even though the
            // archive carries a full vendor/ tree.
            $restoreId  = date('Ymd-His') . '-' . strtolower(Str::random(6));
            $restoreDir = $this->restorePointRoot() . DIRECTORY_SEPARATOR . $restoreId;
            $backupDir  = $restoreDir . DIRECTORY_SEPARATOR . 'files';
            $manifest   = ['changed' => [], 'added' => [], 'skipped' => 0];

            // Bound by reference, not an arrow function: syncDeploy() rewrites
            // $manifest as it runs, and an arrow fn would have frozen the empty
            // value captured here at definition time.
            $restorePoint = function (bool $complete, ?string $to = null) use (&$manifest, $restoreId, $fromVersion, $actor): array {
                return [
                    'id'         => $restoreId,
                    'method'     => 'zip',
                    'complete'   => $complete,
                    'from'       => $fromVersion,
                    'to'         => $to,
                    'created_at' => now()->toIso8601String(),
                    'actor'      => $actor->id ?? null,
                    'changed'    => $manifest['changed'],
                    'added'      => $manifest['added'],
                    'skipped'    => $manifest['skipped'],
                ];
            };

            try {
                $this->syncDeploy($extractedRoot, $appRoot, $preserve, $backupDir, $manifest, $deployHeartbeat);
                $this->writeRestorePoint($restoreDir, $restorePoint(true));
                $checkpoint(sprintf(
                    'step=deploy status=done changed=%d added=%d unchanged=%d restore=%s',
                    count($manifest['changed']), count($manifest['added']), $manifest['skipped'], $restoreId
                ));
                $appendOutput('sync deploy', sprintf(
                    '%d file(s) updated, %d added, %d unchanged. Restore point: %s',
                    count($manifest['changed']), count($manifest['added']), $manifest['skipped'], $restoreId
                ), 0);
            } catch (Throwable $e) {
                // Record what was already touched — a half-deploy is exactly the
                // state that needs a rollback, so the restore point matters most here.
                $this->writeRestorePoint($restoreDir, $restorePoint(false));
                $checkpoint('step=deploy status=failed err=' . $e->getMessage());
                $appendOutput('sync deploy', $e->getMessage(), 1);
                $result = $this->buildRunResult(
                    'failed',
                    sprintf(
                        'File deployment failed after updating %d file(s): %s. The previous files were saved — use Rollback to restore them.',
                        count($manifest['changed']) + count($manifest['added']),
                        $e->getMessage()
                    ),
                    0, $fromVersion, null, 'main', $remoteSanitized, 1, $startedAt, $capturedOutput
                );
                $this->audit($actor, $result, $capturedOutput, 0);
                $emit('error', $result['message'], 55, true, $result);
                return $result;
            }

            // Step: Composer — ZIP ships vendor/, so HOME errors are non-fatal when vendor exists
            $checkpoint('step=composer status=starting');
            $emit('composer', 'Installing dependencies...', 65);
            if ($this->composerAvailable()) {
                $composerCmd = $this->composerInstall();
                $composer = $this->runProcess($composerCmd, 180);
                $appendOutput(implode(' ', $composerCmd), $composer['output'], $composer['exit']);
                $checkpoint('step=composer status=' . ($composer['success'] ? 'done' : 'failed exit=' . $composer['exit']));
                if (! $composer['success']) {
                    $isHomeError = str_contains(strtolower($composer['output']), 'home or composer_home')
                        || str_contains(strtolower($composer['output']), 'the home or composer_home');
                    $isPhpVersionError = str_contains(strtolower($composer['output']), 'your php version')
                        && str_contains(strtolower($composer['output']), 'does not satisfy');
                    $vendorExists = is_file(base_path('vendor/autoload.php'));
                    if ($isHomeError && $vendorExists) {
                        $checkpoint('step=composer status=skipped (HOME error but vendor/ present)');
                        $appendOutput('composer install', 'composer HOME error — vendor/ ships in ZIP, continuing.', 0);
                        try { Log::warning('UpdateService: composer HOME error ignored during ZIP update — vendor/ present.'); } catch (Throwable) {}
                    } elseif ($isPhpVersionError) {
                        $checkpoint('step=composer status=retrying with --ignore-platform-reqs (PHP mismatch)');
                        $appendOutput('composer install', 'PHP version mismatch (web PHP ' . PHP_VERSION . ' vs Composer PHP) — retrying with --ignore-platform-reqs...', 0);
                        $composerRetry = $this->runProcess($this->composerInstall(ignorePlatformReqs: true), 300);
                        $appendOutput('composer install --ignore-platform-reqs', $composerRetry['output'], $composerRetry['exit']);
                        $checkpoint('step=composer status=' . ($composerRetry['success'] ? 'done (retry)' : 'failed retry exit=' . $composerRetry['exit']));
                        if ($composerRetry['success']) {
                            try { Log::warning('UpdateService: composer ZIP retry with --ignore-platform-reqs succeeded.'); } catch (Throwable) {}
                        } elseif ($vendorExists) {
                            $checkpoint('step=composer status=skipped (retry failed but vendor/ present)');
                            $appendOutput('composer install', 'Composer retry failed but vendor/autoload.php exists — continuing (vendor ships in ZIP).', 0);
                            try { Log::warning('UpdateService: composer ZIP retry failed but vendor exists — continuing.'); } catch (Throwable) {}
                        } else {
                            $result = $this->buildRunResult('failed', 'Dependencies failed: Composer PHP does not satisfy lock (requires >=8.4.1) but your web PHP is ' . PHP_VERSION . '. Fix: cPanel → MultiPHP Manager → set to ea-php85 (8.5) for BOTH web and CLI, or SSH: `composer install --ignore-platform-reqs`. Raw: ' . Str::limit($composer['output'], 500), 0, $fromVersion, null, 'main', $remoteSanitized, $composer['exit'], $startedAt, $capturedOutput);
                            $this->audit($actor, $result, $capturedOutput, 0);
                            $emit('error', $result['message'], 65, true, $result);
                            return $result;
                        }
                    } else {
                        $result = $this->buildRunResult('failed', 'Files updated but dependencies failed — run composer install via SSH. ' . Str::limit($composer['output'], 500), 0, $fromVersion, null, 'main', $remoteSanitized, $composer['exit'], $startedAt, $capturedOutput);
                        $this->audit($actor, $result, $capturedOutput, 0);
                        $emit('error', $result['message'], 65, true, $result);
                        return $result;
                    }
                }
            } else {
                $checkpoint('step=composer status=skipped (not in PATH)');
                $appendOutput('composer install', 'composer not found in PATH — skipped (vendor/ ships in ZIP).', 0);
                try { Log::warning('UpdateService: composer not found during ZIP update — vendor/ ships in archive.'); } catch (Throwable) {}
            }

            // Step: Migrate
            $checkpoint('step=migrate status=starting');
            $emit('migrate', 'Updating database schema (your data is preserved)...', 78);
            $migrate = $this->runProcess(['php', 'artisan', 'migrate', '--force'], 60);
            $appendOutput('php artisan migrate --force', $migrate['output'], $migrate['exit']);
            $checkpoint('step=migrate status=' . ($migrate['success'] ? 'done' : 'failed exit=' . $migrate['exit']));
            if (! $migrate['success']) {
                // This process is still running the previous release's logic
                // (it was loaded before the files above replaced it), so the
                // failure may be stale logic rather than a real schema problem.
                // Retry through a fresh child that picks up the deployed code.
                $checkpoint('step=migrate status=retrying with deployed code');
                $emit('migrate', 'Retrying with the updated code...', 80);
                $retry = $this->finalizeWithNewCode();
                $appendOutput('php artisan system:update:finalize', $retry['output'], $retry['exit']);
                $checkpoint('step=finalize status=' . ($retry['success'] ? 'done' : 'failed exit=' . $retry['exit']));

                if (! $retry['success']) {
                    $result = $this->buildRunResult('failed', 'Database update failed — your existing data is intact. Files are updated; finish with: php artisan system:update:finalize. ' . Str::limit($migrate['output'], 500), 0, $fromVersion, null, 'main', $remoteSanitized, $migrate['exit'], $startedAt, $capturedOutput);
                    $this->audit($actor, $result, $capturedOutput, 0);
                    $emit('error', $result['message'], 78, true, $result);
                    return $result;
                }

                // The retry ran composer, migrate, caches and up already.
                $migrate = $retry;
            }

            // Step: Cache clears
            $checkpoint('step=cache status=starting');
            $emit('cache', 'Clearing application cache...', 88);
            foreach ([['php', 'artisan', 'optimize:clear'], ['php', 'artisan', 'config:clear'], ['php', 'artisan', 'view:clear']] as $cmd) {
                $res = $this->runProcess($cmd, 30);
                $appendOutput(implode(' ', $cmd), $res['output'], $res['exit']);
            }
            $checkpoint('step=cache status=done');

            // Resolve the version this deploy is heading for and record it on the
            // restore point — but do not stamp VERSION yet, because the schema
            // check below still has to pass. Recording the target here is what
            // lets a later `system:update:finalize` finish the job.
            $toVersion = null;
            try {
                $api = $this->fetchGithubCommits(1);
                $toVersion = $api['remoteHash'] ?? null;
            } catch (Throwable) {}

            $short = $toVersion ? substr($toVersion, 0, 7) : 'latest';

            $this->writeRestorePoint($restoreDir, $restorePoint(true, $toVersion !== null ? $short : null));

            // Don't claim success on a half-updated install: new code against an
            // old schema is the state that is expensive to discover later.
            $pending = $this->pendingMigrations();
            $checkpoint('step=verify pending_migrations=' . ($pending === null ? 'unknown' : (string) count($pending)));

            if ($pending !== null && $pending !== []) {
                $appendOutput('verify', 'Migrations are still pending after the update: ' . $this->describePending($pending), 1);
                // VERSION is deliberately left at the old value: the code is new
                // but the schema is not, so check() must keep offering this
                // update until the repair completes. Restore points are kept for
                // the same reason — this install is not in a finished state.
                $checkpoint('step=verify version_stamp=deferred');
                $result = $this->buildRunResult('failed', sprintf('Updated to %s but %d migration(s) are still pending (%s) — finish with: php artisan system:update:finalize', $short, count($pending), $this->describePending($pending)), 0, $fromVersion, $toVersion, 'main', $remoteSanitized, 1, $startedAt, $capturedOutput);
                $this->audit($actor, $result, $capturedOutput, 0);
                $emit('error', $result['message'], 95, true, $result);
                return $result;
            }

            // Code and schema are both current — only now is the new version true.
            if ($toVersion !== null) {
                $this->writeVersionMarker($toVersion);
            }

            $this->pruneRestorePoints();

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
            // A vendor/ file this codebase pruned itself is not operator work in
            // progress, and reporting it as such is what used to wedge the
            // updater shut — check() would report "dirty", which hides the
            // update button, so run() could never be reached to repair it.
            // run() restores these before pulling; see restorePrunedVendor().
            if ($this->isPrunedVendorDeletion($line)) {
                continue;
            }
            return true;
        }

        return false;
    }

    /**
     * Is this `git status --porcelain` line a deletion of a committed vendor/ file?
     *
     * Porcelain v1 format is two status columns, a space, then the path.
     */
    private function isPrunedVendorDeletion(string $line): bool
    {
        $line = rtrim($line, "\r");

        if (strlen($line) < 4) {
            return false;
        }

        if (! in_array(substr($line, 0, 2), [' D', 'D ', 'DD'], true)) {
            return false;
        }

        $path = str_replace('\\', '/', trim(substr($line, 3), " \"'"));

        return str_starts_with($path, 'vendor/');
    }

    /**
     * Restore vendor/ files that an earlier `composer install --no-dev` deleted.
     *
     * Safe precisely because it is scoped to vendor/ and only ever runs
     * `git checkout HEAD -- vendor`: it recreates tracked files from the commit
     * that is already checked out, and touches nothing the operator wrote.
     * Without it, installs bricked by a pre-fix release stay bricked — the new
     * composerInstall() only prevents the next occurrence.
     *
     * @return bool Whether anything was restored.
     */
    private function restorePrunedVendor(): bool
    {
        $res = $this->runProcess(['git', 'status', '--porcelain'], 15);

        if (! $res['success'] || trim($res['output']) === '') {
            return false;
        }

        $pruned = 0;

        foreach (explode("\n", trim($res['output'])) as $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '??') || str_starts_with($trimmed, '!!')) {
                continue;
            }

            if ($this->isPrunedVendorDeletion($line)) {
                $pruned++;
                continue;
            }

            // Something outside vendor/ changed too. Restoring would be guessing
            // about work we did not do, so leave the tree alone and let the
            // dirty guard report it.
            return false;
        }

        if ($pruned === 0) {
            return false;
        }

        $restore = $this->runProcess(['git', 'checkout', 'HEAD', '--', 'vendor'], 180);

        try {
            Log::warning('UpdateService: restored committed vendor/ files pruned by a previous --no-dev install.', [
                'files' => $pruned,
                'restored' => $restore['success'],
            ]);
        } catch (Throwable) {
        }

        return $restore['success'];
    }

    protected function composerAvailable(): bool
    {
        return $this->composerCommand() !== null;
    }

    /**
     * The dependency-install command.
     *
     * Deliberately without --no-dev. vendor/ is committed and ships inside both
     * the git checkout and the ZIP archive, dev packages included — roughly
     * 2,000 tracked files across phpunit, faker, dusk, mockery, pint and
     * collision. Pruning them left `git status --porcelain` reporting deletions
     * forever, so isDirty() blocked every subsequent check() and run() with
     * "commit or stash them first" for changes the operator never made: the
     * updater disabled itself after exactly one successful run. Installing what
     * composer.lock actually pins is the only variant that leaves the tree clean.
     *
     * @return list<string>
     */
    private function composerInstall(bool $ignorePlatformReqs = false): array
    {
        $cmd = ['composer', 'install', '--optimize-autoloader', '--no-interaction'];

        if ($ignorePlatformReqs) {
            $cmd[] = '--ignore-platform-reqs';
        }

        return $cmd;
    }

    /**
     * The post-deploy half of an update: dependencies, schema, caches, up.
     *
     * Split out so it can be (a) re-run in a fresh process once new files are
     * on disk — see finalizeWithNewCode() — and (b) invoked by hand via
     * `php artisan system:update:finalize` when a run half-completes. Every
     * step is idempotent, so running it twice is harmless.
     *
     * @param  callable(string,string,int,bool,array):void|null  $emit
     * @return array{status: string, message: string, output: string, exit: int, pending: bool|null}
     */
    public function finalize(?callable $emit = null): array
    {
        $emit ??= static function (string $step, string $message, int $progress, bool $done = false, array $extra = []): void {};
        $output = '';

        $append = function (string $label, string $out, int $exit) use (&$output): void {
            $output .= sprintf("\n[%s] exit=%d\n%s\n", $label, $exit, trim($out));
            if (mb_strlen($output) > self::OUTPUT_LIMIT) {
                $output = Str::limit($output, self::OUTPUT_LIMIT);
            }
        };

        $fail = function (string $message, int $exit) use (&$output, $emit): array {
            $result = ['status' => 'failed', 'message' => $message, 'output' => Str::limit($output, self::OUTPUT_LIMIT), 'exit' => $exit, 'pending' => null];
            $emit('error', $message, 0, true, $result);

            return $result;
        };

        // Dependencies. vendor/ ships inside the update archive, so a missing
        // composer or a HOME/COMPOSER_HOME error is a note rather than a failure.
        $emit('composer', 'Installing dependencies...', 20);
        if ($this->composerAvailable()) {
            $composerCmd = $this->composerInstall();
            $composer = $this->runProcess($composerCmd, 300);
            $append(implode(' ', $composerCmd), $composer['output'], $composer['exit']);

            if (! $composer['success']) {
                $isHomeError = str_contains(strtolower($composer['output']), 'home or composer_home')
                    || str_contains(strtolower($composer['output']), 'the home or composer_home');
                $isPhpVersionError = str_contains(strtolower($composer['output']), 'your php version')
                    && str_contains(strtolower($composer['output']), 'does not satisfy');
                $vendorExists = is_file(base_path('vendor/autoload.php'));
                if ($isHomeError && $vendorExists) {
                    $append('composer install', 'composer HOME error — vendor/ ships in archive, continuing.', 0);
                    try { Log::warning('UpdateService: composer HOME error ignored in finalize — vendor/ present.'); } catch (Throwable) {}
                } elseif ($isPhpVersionError) {
                    $append('composer install', 'PHP version mismatch — retrying with --ignore-platform-reqs (web PHP ' . PHP_VERSION . ')...', 0);
                    $composerRetry = $this->runProcess($this->composerInstall(ignorePlatformReqs: true), 300);
                    $append('composer install --ignore-platform-reqs', $composerRetry['output'], $composerRetry['exit']);
                    if ($composerRetry['success']) {
                        try { Log::warning('UpdateService: composer finalize retry with --ignore-platform-reqs succeeded.'); } catch (Throwable) {}
                    } elseif ($vendorExists) {
                        $append('composer install', 'Composer retry failed but vendor exists — continuing (vendor ships).', 0);
                        try { Log::warning('UpdateService: composer finalize retry failed but vendor exists — continuing.'); } catch (Throwable) {}
                    } else {
                        return $fail('Dependencies failed: your server PHP (' . PHP_VERSION . ') does not satisfy the update (requires PHP >=8.4.1). Fix: cPanel → MultiPHP Manager → set to ea-php85 (8.5) → retry. ' . trim(Str::limit($composer['output'], 1500)), $composer['exit']);
                    }
                } else {
                    return $fail('Dependencies failed to install. ' . trim(Str::limit($composer['output'], 500)), $composer['exit']);
                }
            }
        } else {
            $append('composer install', 'composer not found — skipped (vendor/ ships inside the update archive).', 0);
        }

        // Schema.
        $emit('migrate', 'Updating database schema (your data is preserved)...', 55);
        $migrate = $this->runProcess(['php', 'artisan', 'migrate', '--force'], 300);
        $append('php artisan migrate --force', $migrate['output'], $migrate['exit']);

        if (! $migrate['success']) {
            return $fail('Database update failed — your existing data is intact. ' . trim(Str::limit($migrate['output'], 500)), $migrate['exit']);
        }

        // Caches.
        $emit('cache', 'Clearing application cache...', 80);
        foreach ([['php', 'artisan', 'optimize:clear'], ['php', 'artisan', 'config:clear'], ['php', 'artisan', 'view:clear']] as $cmd) {
            $res = $this->runProcess($cmd, 60);
            $append(implode(' ', $cmd), $res['output'], $res['exit']);
        }

        // Make sure the site is serving again.
        $up = $this->runProcess(['php', 'artisan', 'up'], 15);
        $append('php artisan up', $up['output'], $up['exit']);

        // finalize() runs outside withUpdateLock() — by hand from the CLI, or in
        // the fresh child finalizeWithNewCode() spawns — so it has to invalidate
        // the About card's git snapshot itself.
        try {
            AppInfoService::flushCache();
        } catch (Throwable) {
        }

        // Verify rather than assume: a migrate step that "succeeded" but left
        // migrations pending is exactly the half-updated state we want to catch.
        $pendingList = $this->pendingMigrations();
        $pending     = $pendingList === null ? null : $pendingList !== [];

        if ($pending === true) {
            $message = sprintf('Update finished but %d migration(s) are still pending (%s) — run: php artisan migrate --force', count($pendingList), $this->describePending($pendingList));
            $result  = ['status' => 'incomplete', 'message' => $message, 'output' => Str::limit($output, self::OUTPUT_LIMIT), 'exit' => 1, 'pending' => true];
            $emit('error', $message, 90, true, $result);

            return $result;
        }

        // Dependencies and schema are both current, so the deploy that stopped
        // short is now genuinely complete. Stamp the version it was heading for:
        // without this, a run repaired by hand left VERSION at the old value and
        // check() re-offered the same update forever, re-downloading and
        // re-deploying byte-identical code every time.
        $stamped = null;
        $target  = $this->pendingVersionTarget();

        if ($target !== null && $target !== $this->resolveLocalVersion() && $this->writeVersionMarker($target)) {
            $stamped = $target;
            $append('version', 'Stamped VERSION as ' . $target . '.', 0);
        }

        $message = $stamped !== null
            ? sprintf('Post-update steps completed — now on version %s.', $stamped)
            : 'Post-update steps completed.';
        $result  = ['status' => 'success', 'message' => $message, 'output' => Str::limit($output, self::OUTPUT_LIMIT), 'exit' => 0, 'pending' => $pending];
        $emit('done', $message, 100, true, $result);

        return $result;
    }

    /**
     * Migration names on disk that are absent from the `migrations` table.
     *
     * Compares the two directly rather than shelling out to
     * `migrate --force --pretend` and searching its output for the literal
     * "Nothing to migrate". That string match failed open in the expensive
     * direction: empty output, a reworded or localised message, or any future
     * change to the command's formatting all read as "pending", which turned an
     * update that had just succeeded into a reported failure telling the
     * operator to run a repair command they did not need.
     *
     * Reading files and rows is also more accurate here than asking the old
     * in-memory code: both are re-read from disk and database, so a migration
     * the update just deployed is counted.
     *
     * Protected as a test seam, like runProcess(): the "only stamp VERSION once
     * the schema is current" rule is only worth having if both branches are
     * exercised.
     *
     * @return list<string>|null  null when the answer cannot be determined.
     */
    protected function pendingMigrations(): ?array
    {
        try {
            /** @var \Illuminate\Database\Migrations\Migrator $migrator */
            $migrator = app('migrator');

            // No repository at all means the app was never migrated, which is a
            // broken install rather than a half-finished update. Report unknown
            // instead of failing a run that otherwise completed.
            if (! $migrator->repositoryExists()) {
                return null;
            }

            $ran   = $migrator->getRepository()->getRan();
            $files = $migrator->getMigrationFiles(
                array_merge($migrator->paths(), [database_path('migrations')])
            );

            return array_values(array_diff(array_keys($files), $ran));
        } catch (Throwable $e) {
            try {
                Log::warning('UpdateService: could not determine pending migrations.', ['error' => $e->getMessage()]);
            } catch (Throwable) {
            }

            return null;
        }
    }

    /** Human-readable tail for a "migrations still pending" message. */
    private function describePending(array $pending): string
    {
        $shown = array_slice($pending, 0, 3);
        $more  = count($pending) - count($shown);

        return implode(', ', $shown) . ($more > 0 ? sprintf(' and %d more', $more) : '');
    }

    /**
     * Re-run the post-deploy steps in a fresh process.
     *
     * This process loaded UpdateService at boot, before the update replaced it
     * on disk, so it is still executing the previous release's logic. A fresh
     * `artisan` child picks up the code that was just deployed — which is how a
     * fix to this chain takes effect on the run that delivers it rather than
     * the one after.
     *
     * @return array{output: string, exit: int, success: bool}
     */
    private function finalizeWithNewCode(int $timeout = 900): array
    {
        return $this->runProcess(['php', 'artisan', 'system:update:finalize'], $timeout);
    }

    private function sanitizeRemote(string $url): string
    {
        $sanitized = preg_replace('#https://[^@]+@#', 'https://***@', $url);

        return $sanitized === null ? $url : $sanitized;
    }

    /**
     * Protected, like isGitRepo() and resolveLocalVersion(), so tests can drive
     * the update and rollback chains without spawning git/composer/artisan.
     *
     * @return array{output: string, exit: int, success: bool}
     */
    protected function runProcess(array $cmd, int $timeout = 3): array
    {
        return $this->runRaw($this->resolveCommand($cmd), $timeout);
    }

    /**
     * The directory an update deploys into.
     *
     * A seam rather than a bare base_path() call: it is the one value that
     * decides which tree gets overwritten, and tests must never point it at the
     * real installation.
     */
    protected function appRoot(): string
    {
        return base_path();
    }

    /**
     * Rewrite bare "php" / "composer" into absolute, resolved commands.
     *
     * Neither is on PATH for the account IIS/FastCGI runs as, so every artisan
     * step failed with "'php' is not recognized as an internal or external
     * command" — including `artisan up`, which is what would have left a site
     * stuck in maintenance mode. Resolving here rather than at the ~13 call
     * sites keeps the chains readable and stops the bug from coming back.
     *
     * @param  list<string>  $cmd
     * @return list<string>
     */
    private function resolveCommand(array $cmd): array
    {
        if ($cmd === []) {
            return $cmd;
        }

        if ($cmd[0] === 'php') {
            $cmd[0] = $this->phpBinary();
        } elseif ($cmd[0] === 'composer') {
            $composer = $this->composerCommand();
            if ($composer !== null) {
                array_splice($cmd, 0, 1, $composer);
            }
        }

        return $cmd;
    }

    /**
     * Absolute path to the PHP CLI binary.
     *
     * Under FastCGI, PHP_BINARY is php-cgi.exe — which does not behave as a CLI
     * runner — so the php.exe sitting beside it is preferred.
     */
    private function phpBinary(): string
    {
        static $resolved = null;

        if ($resolved !== null) {
            return $resolved;
        }

        $sibling = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR
            . (DIRECTORY_SEPARATOR === '\\' ? 'php.exe' : 'php');

        if (is_file($sibling)) {
            return $resolved = $sibling;
        }

        $found = (new PhpExecutableFinder())->find(false);

        if (is_string($found) && $found !== '' && is_file($found)) {
            return $resolved = $found;
        }

        return $resolved = (PHP_BINARY !== '' ? PHP_BINARY : 'php');
    }

    /**
     * Resolved argv prefix for composer, or null when it cannot be found.
     *
     * @return list<string>|null
     */
    private function composerCommand(): ?array
    {
        static $resolved = false;

        if ($resolved !== false) {
            return $resolved;
        }

        // A composer.phar shipped with the app needs no PATH at all.
        $phar = base_path('composer.phar');
        if (is_file($phar)) {
            return $resolved = [$this->phpBinary(), $phar];
        }

        // Probed via runRaw: going through runProcess would recurse back here.
        foreach (['composer', 'composer.bat', 'composer.phar'] as $candidate) {
            if ($this->runRaw([$candidate, '--version'], 5)['success']) {
                return $resolved = [$candidate];
            }
        }

        foreach ([
            'C:\\ProgramData\\ComposerSetup\\bin\\composer.bat',
            'C:\\ProgramData\\ComposerSetup\\bin\\composer.phar',
        ] as $path) {
            if (is_file($path)) {
                return $resolved = str_ends_with($path, '.phar')
                    ? [$this->phpBinary(), $path]
                    : [$path];
            }
        }

        return $resolved = null;
    }

    /**
     * @param  list<string>  $cmd
     * @return array{output: string, exit: int, success: bool}
     */
    private function runRaw(array $cmd, int $timeout = 3): array
    {
        try {
            // Composer (and git via credential helpers) requires HOME/COMPOSER_HOME even when
            // PHP-FPM/IIS runs as a service account with no HOME (shared hosting). Without it
            // Composer dies at Factory.php:727 with "The HOME or COMPOSER_HOME environment
            // variable must be set". Inject a sane HOME so vendor-shipped installs never need
            // the user to run "composer install via SSH".
            $env = null;
            $isComposer = isset($cmd[0]) && str_contains(strtolower((string) $cmd[0]), 'composer');
            $needsHome = getenv('HOME') === false && getenv('COMPOSER_HOME') === false
                && empty($_SERVER['HOME'] ?? null) && empty($_ENV['HOME'] ?? null);
            // Also cover Windows service accounts where only USERPROFILE exists
            if ($needsHome || $isComposer) {
                $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? null) ?: ($_ENV['HOME'] ?? null) ?: null;
                if ($home === null || $home === '') {
                    $home = getenv('USERPROFILE') ?: ($_SERVER['USERPROFILE'] ?? null) ?: ($_ENV['USERPROFILE'] ?? null) ?: null;
                }
                if ($home === null || $home === '') {
                    $home = sys_get_temp_dir();
                }
                $composerHome = getenv('COMPOSER_HOME') ?: ($_SERVER['COMPOSER_HOME'] ?? null) ?: ($_ENV['COMPOSER_HOME'] ?? null) ?: ($home . DIRECTORY_SEPARATOR . '.composer');
                // Build env for the child process — merge current env so PATH etc. are preserved
                $env = array_merge(
                    array_filter($_ENV ?? [], static fn ($v) => is_string($v) || is_numeric($v)),
                    array_filter($_SERVER ?? [], static fn ($v) => is_string($v) || is_numeric($v)),
                    [
                        'HOME' => $home,
                        'COMPOSER_HOME' => $composerHome,
                        'USERPROFILE' => $home,
                    ]
                );
                // Ensure Composer can write its cache/config
                if (! is_dir($composerHome)) {
                    @mkdir($composerHome, 0755, true);
                }
            }

            $process = new Process($cmd, base_path(), $env, null, (float) $timeout);
            $process->run();

            // Redacted here, at the one place process output enters this class,
            // so every downstream consumer — result messages, update.log,
            // activity_log.metadata, the SSE stream — is clean by construction
            // rather than by remembering to sanitise at each site.
            $output = SecretRedactor::redact($process->getOutput().$process->getErrorOutput());

            return [
                'output' => $output,
                'exit' => $process->getExitCode() ?? 1,
                'success' => $process->isSuccessful(),
            ];
        } catch (Throwable $e) {
            return [
                'output' => SecretRedactor::redact($e->getMessage()),
                'exit' => 1,
                'success' => false,
            ];
        }
    }

    /**
     * Does $localVersion identify $commit?
     *
     * The VERSION file holds the short hash written by the last successful
     * update, so match it as a prefix of the full SHA. Placeholders such as
     * "1.0.0" or "dev" identify nothing.
     *
     * @param  array{hash?: string, short?: string}  $commit
     */
    private function isInstalledCommit(string $localVersion, array $commit): bool
    {
        $local = strtolower(trim($localVersion));
        $hash  = strtolower((string) ($commit['hash'] ?? ''));

        if ($local === '' || $hash === '' || strlen($local) < 7 || ! ctype_xdigit($local)) {
            return false;
        }

        return $local === $hash || str_starts_with($hash, $local);
    }

    /**
     * Stamp the VERSION marker that check() and the About card read.
     *
     * Never writes inside a git checkout. VERSION is a tracked file, so writing
     * it there would leave `git status --porcelain` permanently dirty — the
     * exact state that wedges the updater shut, for the same reason
     * composerInstall() must not prune vendor/. A git install reads its version
     * from the checkout itself.
     *
     * @return bool Whether the marker was written.
     */
    private function writeVersionMarker(string $version): bool
    {
        $version = trim($version);

        if ($version === '') {
            return false;
        }

        if ($this->isGitRepo()) {
            try {
                Log::info('UpdateService: VERSION stamp skipped — a git checkout reports its version from the repository.');
            } catch (Throwable) {
            }

            return false;
        }

        // Full SHAs are stored short; a tag or a placeholder like "1.0.0" verbatim.
        $marker = strlen($version) === 40 && ctype_xdigit($version)
            ? substr($version, 0, 7)
            : $version;

        return @file_put_contents($this->appRoot() . DIRECTORY_SEPARATOR . 'VERSION', $marker) !== false;
    }

    /**
     * The version an interrupted ZIP deploy was heading for, if any.
     *
     * Read from the restore point manifest, which records the target at deploy
     * time. That is deliberately narrower than asking GitHub what is newest: a
     * `system:update:finalize` run by hand at some arbitrary later date would
     * otherwise stamp a version whose code was never deployed to this machine.
     */
    private function pendingVersionTarget(): ?string
    {
        // Only the newest point can describe a deploy waiting to be finished,
        // and a point that has been rolled back describes a version the operator
        // deliberately backed out of — stamping either would claim a version
        // that is not what is on disk.
        $newest = $this->restorePoints()[0] ?? null;

        if ($newest === null || ! empty($newest['restored_at']) || empty($newest['to'])) {
            return null;
        }

        return (string) $newest['to'];
    }

    protected function resolveLocalVersion(): string
    {
        // appRoot(), not base_path(), so this reads back exactly what
        // writeVersionMarker() wrote — identical in production, and it keeps the
        // pair honest under tests that redirect the install root.
        $marker = $this->appRoot() . DIRECTORY_SEPARATOR . 'VERSION';
        $ver = trim((string) (is_file($marker) ? file_get_contents($marker) : config('app.version', 'dev')));

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
                    // --fail is load-bearing: without it curl writes a 404 /
                    // rate-limit body to $destPath and still exits 0, which the
                    // size check below then accepted as a downloaded update.
                    $curlBin, '-L', '--fail', '--silent', '--show-error',
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

                if ($process->isSuccessful() && $this->isZipArchive($destPath)) {
                    return true;
                }

                Log::warning('UpdateService: curl ZIP download failed.', [
                    'exit'  => $curlExit,
                    'size'  => $curlSize,
                    'zip'   => $this->isZipArchive($destPath),
                    'error' => $curlError,
                ]);

                // Leave nothing behind for the fallback to mistake for a download.
                if (is_file($destPath)) {
                    @unlink($destPath);
                }
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

            if ($response->successful() && $this->isZipArchive($destPath)) {
                return true;
            }

            Log::warning('UpdateService: HTTP ZIP download did not yield an archive.', [
                'status' => $response->status(),
                'size'   => is_file($destPath) ? filesize($destPath) : 0,
            ]);

            if (is_file($destPath)) {
                @unlink($destPath);
            }

            return false;
        } catch (Throwable $e) {
            Log::warning('UpdateService: ZIP download failed.', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Is $path a real ZIP archive rather than an error page?
     *
     * Both download paths can produce a file that exists and is non-empty while
     * containing a GitHub JSON error or an HTML proxy page. That used to be
     * reported as a successful download and only surfaced one step later as
     * "the disk may be full or the download was corrupted" — the wrong
     * diagnosis, pointing the operator at the wrong fix.
     */
    private function isZipArchive(string $path): bool
    {
        if (! is_file($path) || (int) filesize($path) < self::MIN_ZIP_BYTES) {
            return false;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $magic = (string) fread($handle, 4);
        fclose($handle);

        return str_starts_with($magic, "PK\x03\x04");
    }

    /**
     * Does $root hold this application rather than some arbitrary archive?
     *
     * Cheap insurance, checked before syncDeploy() starts overwriting a live
     * install: an archive that is missing these is not something to deploy.
     */
    private function looksLikeAppRoot(string $root): bool
    {
        foreach (['artisan', 'composer.json', 'app', 'bootstrap'] as $marker) {
            if (! file_exists($root . DIRECTORY_SEPARATOR . $marker)) {
                return false;
            }
        }

        return true;
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
     *
     * $preserve entries are relative to $destDir (e.g. '.env', 'storage', 'install.lock').
     * $heartbeat is called at most every 5 s to keep IIS FastCGI activityTimeout from firing.
     *
     * Every write is checked and every overwrite is snapshotted first, so a
     * deploy either completes or throws — it can no longer half-finish and be
     * reported as "Files deployed successfully". $manifest is passed by
     * reference precisely so a throw still leaves the caller holding the list of
     * files already touched, which is what makes the restore point usable after
     * a mid-deploy failure.
     *
     * @param  list<string>  $preserve
     * @param  array{changed: list<string>, added: list<string>, skipped: int}  $manifest
     *
     * @throws RuntimeException on any failed directory create, snapshot or copy.
     */
    private function syncDeploy(
        string $srcDir,
        string $destDir,
        array $preserve,
        ?string $backupDir,
        array &$manifest,
        ?callable $heartbeat = null
    ): void {
        $heartbeat     ??= static function (): void {};
        $lastHeartbeat = time();

        $sep = DIRECTORY_SEPARATOR;
        $normalizedPreserve = array_map(
            static fn ($p) => rtrim(str_replace(['/', '\\'], $sep, $p), $sep),
            $preserve
        );

        $manifest = ['changed' => [], 'added' => [], 'skipped' => 0];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            // Heartbeat every 5 s so IIS FastCGI activityTimeout doesn't fire
            if (time() - $lastHeartbeat >= 5) {
                $heartbeat();
                $lastHeartbeat = time();
            }

            $relativePath = substr($item->getPathname(), strlen($srcDir) + 1);

            // Skip any path that matches or is under a preserved prefix
            foreach ($normalizedPreserve as $p) {
                if ($relativePath === $p || str_starts_with($relativePath, $p . $sep)) {
                    continue 2;
                }
            }

            $destPath = $destDir . $sep . $relativePath;

            if ($item->isDir()) {
                $this->ensureDirectory($destPath);

                continue;
            }

            // Identical files are the overwhelming majority of a release —
            // vendor/ barely moves between versions. Skipping them speeds the
            // deploy up and, more to the point, keeps the restore point down to
            // what actually changed instead of a second copy of the whole tree.
            if (is_file($destPath) && $this->sameFile($item->getPathname(), $destPath)) {
                $manifest['skipped']++;

                continue;
            }

            $this->ensureDirectory(dirname($destPath));

            if (is_file($destPath)) {
                if ($backupDir !== null) {
                    $this->snapshotFile($destPath, $backupDir . $sep . $relativePath);
                }

                $manifest['changed'][] = str_replace('\\', '/', $relativePath);
            } else {
                $manifest['added'][] = str_replace('\\', '/', $relativePath);
            }

            // copy()'s return value used to be discarded, so a file locked by
            // another process or blocked by ACLs produced a half-updated tree
            // that still reported success.
            if (! @copy($item->getPathname(), $destPath)) {
                throw new RuntimeException(sprintf(
                    'Could not write %s (%s)',
                    $relativePath,
                    error_get_last()['message'] ?? 'unknown error'
                ));
            }
        }
    }

    /** @throws RuntimeException */
    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        // Re-check after mkdir: a concurrent iteration branch may have won the race.
        if (! @mkdir($path, 0755, true) && ! is_dir($path)) {
            throw new RuntimeException(sprintf(
                'Could not create directory %s (%s)',
                $path,
                error_get_last()['message'] ?? 'unknown error'
            ));
        }
    }

    /** Copy a file that is about to be overwritten into the restore point. @throws RuntimeException */
    private function snapshotFile(string $source, string $target): void
    {
        $this->ensureDirectory(dirname($target));

        if (! @copy($source, $target)) {
            throw new RuntimeException(sprintf(
                'Could not snapshot %s for rollback (%s)',
                $source,
                error_get_last()['message'] ?? 'unknown error'
            ));
        }
    }

    /** Same size and same digest — cheap enough at ~13k vendor files, far cheaper than copying them. */
    private function sameFile(string $a, string $b): bool
    {
        $sizeA = @filesize($a);
        $sizeB = @filesize($b);

        if ($sizeA === false || $sizeB === false || $sizeA !== $sizeB) {
            return false;
        }

        return @md5_file($a) === @md5_file($b);
    }

    // ------------------------------------------------------------------
    // Restore points (ZIP installs)
    // ------------------------------------------------------------------

    /**
     * Where per-update file snapshots live.
     *
     * Under storage/, which syncDeploy() preserves, so a restore point survives
     * the update that created it and every update after.
     */
    protected function restorePointRoot(): string
    {
        return storage_path('app' . DIRECTORY_SEPARATOR . 'update-restore-points');
    }

    /**
     * Available ZIP restore points, newest first.
     *
     * IDs are `Ymd-His-xxxxxx`, so a lexical sort is a chronological one.
     *
     * @return list<array<string, mixed>>
     */
    public function restorePoints(): array
    {
        $root = $this->restorePointRoot();

        if (! is_dir($root)) {
            return [];
        }

        $points = [];

        foreach ((array) glob($root . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) as $dir) {
            $manifestPath = $dir . DIRECTORY_SEPARATOR . 'manifest.json';

            if (! is_file($manifestPath)) {
                continue;
            }

            $manifest = json_decode((string) @file_get_contents($manifestPath), true);

            if (! is_array($manifest)) {
                continue;
            }

            $manifest['id']  = (string) ($manifest['id'] ?? basename($dir));
            $manifest['dir'] = $dir;
            $points[] = $manifest;
        }

        usort($points, static fn (array $a, array $b) => strcmp((string) $b['id'], (string) $a['id']));

        return $points;
    }

    /**
     * Persist (or update) a restore point manifest.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function writeRestorePoint(string $dir, array $manifest): void
    {
        try {
            $this->ensureDirectory($dir);

            @file_put_contents(
                $dir . DIRECTORY_SEPARATOR . 'manifest.json',
                (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
        } catch (Throwable $e) {
            try {
                Log::warning('UpdateService: could not write restore point manifest.', ['error' => $e->getMessage()]);
            } catch (Throwable) {
            }
        }
    }

    /** Keep the newest RESTORE_POINTS_KEPT snapshots; delete the rest. */
    private function pruneRestorePoints(): void
    {
        try {
            foreach (array_slice($this->restorePoints(), self::RESTORE_POINTS_KEPT) as $point) {
                if (isset($point['dir']) && is_dir($point['dir'])) {
                    $this->rrmdir($point['dir']);
                }
            }
        } catch (Throwable $e) {
            try {
                Log::warning('UpdateService: restore point pruning failed.', ['error' => $e->getMessage()]);
            } catch (Throwable) {
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

    /**
     * The upstream ref this checkout should be compared against and pulled from.
     *
     * check() has always fallen back to origin/master when origin/main does not
     * exist, but run() queried origin/main only and then hard-coded
     * `git pull --ff-only origin main`. On a master-default checkout that meant
     * check() reported "3 commit(s) behind" while clicking Update answered
     * "Already up to date — no commits to pull" and exited 0: a silent no-op
     * the operator had no way to distinguish from a real update.
     *
     * origin/main is tried first, matching check()'s preference, so a repository
     * carrying both branches resolves the same way in both places.
     *
     * Call after fetching — a ref that has never been fetched does not exist locally.
     *
     * @return string|null  null when neither ref is present.
     */
    private function resolveUpstreamRef(): ?string
    {
        foreach (['origin/main', 'origin/master'] as $ref) {
            $res = $this->runProcess(['git', 'rev-parse', '--verify', '--quiet', $ref], 3);

            if ($res['success'] && trim($res['output']) !== '') {
                return $ref;
            }
        }

        return null;
    }

    /** "origin/master" -> "master", for the branch argument `git pull` expects. */
    private function upstreamBranchName(string $ref): string
    {
        $slash = strrpos($ref, '/');

        return $slash === false ? $ref : substr($ref, $slash + 1);
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
        // Always fetch one fixed window and slice per caller: a single cache key
        // then serves every limit, and the window has to be wide enough for
        // check() to locate the installed commit inside it.
        $slice = static fn (array $result): array => [
            'remoteHash' => $result['remoteHash'] ?? null,
            'commits'    => array_slice($result['commits'] ?? [], 0, max(1, $limit)),
        ];

        // Only cache successful results — failed/rate-limited responses must not
        // block retries for 5 minutes.
        if (Cache::has('system.github_commits')) {
            $cached = Cache::get('system.github_commits');
            if (is_array($cached)) {
                return $slice($cached);
            }
        }

        try {
            $caBundle = storage_path('cacert.pem');
            $client = Http::timeout(8)
                ->withHeaders(['Accept' => 'application/vnd.github.v3+json', 'User-Agent' => 'ManageHosting-CRM']);
            if (is_file($caBundle)) {
                $client = $client->withOptions(['verify' => $caBundle]);
            }
            $response = $client->get('https://api.github.com/repos/Sanat-das/Manage-Hosting-CRM/commits', ['per_page' => self::COMMIT_WINDOW, 'sha' => 'main']);

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

            return $slice($result);
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
        $noop = static function (string $step, string $message, int $progress, bool $done = false, array $extra = []): void {};

        return $this->withUpdateLock(
            fn (): array => $this->isGitRepo()
                ? $this->rollbackGit($fromHash, $actor)
                : $this->rollbackZip($fromHash, $actor),
            fn (): array => $this->busyResult($noop)
        );
    }

    /**
     * Restore a ZIP install from the snapshot taken before an update deployed.
     *
     * Files that the update overwrote are copied back from the restore point and
     * files it added are removed, which is the whole of what syncDeploy() did —
     * it never deletes, so nothing else needs undoing. As with the git path,
     * database schema changes are not reversed.
     *
     * @return array{status: string, message: string, behind: int, from: string|null, to: string|null, branch: string|null, remoteSanitized: string|null, exit: int, durationMs: int, output: string}
     */
    private function rollbackZip(string $target, User $actor): array
    {
        $startedAt       = microtime(true);
        $capturedOutput  = '';
        $remoteSanitized = 'https://github.com/Sanat-das/Manage-Hosting-CRM';
        $currentVersion  = $this->resolveLocalVersion();
        $appRoot         = $this->appRoot();
        $sep             = DIRECTORY_SEPARATOR;

        $appendOutput = function (string $label, string $output, int $exit) use (&$capturedOutput): void {
            $capturedOutput .= sprintf("\n[%s] exit=%d\n%s\n", $label, $exit, trim($output));
        };

        $points = $this->restorePoints();

        if ($points === []) {
            return $this->buildRunResult('failed', 'No restore point is available — rollback only covers updates applied by this installer.', 0, $currentVersion, null, 'main', $remoteSanitized, 1, $startedAt, 'No restore points on disk.');
        }

        // The history table posts the `from` version of the update to undo; the
        // id is also accepted so a specific snapshot can be named directly.
        $target = trim($target);
        $point  = null;

        foreach ($points as $candidate) {
            if ($target !== '' && (string) $candidate['id'] === $target) {
                $point = $candidate;
                break;
            }

            if ($point === null && $target !== '' && (string) ($candidate['from'] ?? '') === $target) {
                $point = $candidate;
            }
        }

        if ($point === null) {
            $available = implode(', ', array_map(
                static fn (array $p): string => sprintf('%s (%s)', (string) $p['id'], (string) ($p['from'] ?? 'unknown')),
                array_slice($points, 0, self::RESTORE_POINTS_KEPT)
            ));

            return $this->buildRunResult('failed', 'No restore point matches version ' . ($target !== '' ? $target : '(none given)') . '. Available: ' . $available, 0, $currentVersion, null, 'main', $remoteSanitized, 1, $startedAt, 'Restore point not found.');
        }

        $backupDir = $point['dir'] . $sep . 'files';
        $restored  = 0;
        $removed   = 0;
        $didDown   = false;

        try {
            $down = $this->runProcess(['php', 'artisan', 'down', '--secret=' . Str::random(16)], 15);
            $appendOutput('php artisan down', $down['output'], $down['exit']);
            $didDown = $down['success'] || str_contains(strtolower($down['output']), 'already');

            foreach ((array) ($point['changed'] ?? []) as $relative) {
                $source = $backupDir . $sep . str_replace('/', $sep, (string) $relative);
                $dest   = $appRoot . $sep . str_replace('/', $sep, (string) $relative);

                if (! is_file($source)) {
                    throw new RuntimeException('Restore point is missing ' . $relative);
                }

                $this->ensureDirectory(dirname($dest));

                if (! @copy($source, $dest)) {
                    throw new RuntimeException(sprintf(
                        'Could not restore %s (%s)',
                        $relative,
                        error_get_last()['message'] ?? 'unknown error'
                    ));
                }

                $restored++;
            }

            foreach ((array) ($point['added'] ?? []) as $relative) {
                $dest = $appRoot . $sep . str_replace('/', $sep, (string) $relative);

                if (is_file($dest) && @unlink($dest)) {
                    $removed++;
                }
            }

            $appendOutput('restore files', sprintf('%d file(s) restored, %d added file(s) removed.', $restored, $removed), 0);

            // Put the version marker back so check() stops advertising the
            // update that was just undone.
            if (! empty($point['from'])) {
                $this->writeVersionMarker((string) $point['from']);
            }

            if ($this->composerAvailable()) {
                $composer = $this->runProcess($this->composerInstall(), 300);
                $appendOutput('composer install', $composer['output'], $composer['exit']);
            }

            foreach ([['php', 'artisan', 'optimize:clear'], ['php', 'artisan', 'config:clear'], ['php', 'artisan', 'view:clear']] as $cmd) {
                $res = $this->runProcess($cmd, 60);
                $appendOutput(implode(' ', $cmd), $res['output'], $res['exit']);
            }

            $this->writeRestorePoint($point['dir'], array_merge(
                array_diff_key($point, ['dir' => null]),
                ['restored_at' => now()->toIso8601String()]
            ));

            $result = $this->buildRunResult(
                'success',
                sprintf('Rolled back to version %s (%d file(s) restored). Note: database schema changes were not reversed.', (string) ($point['from'] ?? 'previous'), $restored),
                0,
                $currentVersion,
                (string) ($point['from'] ?? ''),
                'main',
                $remoteSanitized,
                0,
                $startedAt,
                Str::limit($capturedOutput, self::OUTPUT_LIMIT)
            );

            $this->auditRollback($actor, $currentVersion, (string) ($point['from'] ?? ''), $result['durationMs']);

            return $result;
        } catch (Throwable $e) {
            $appendOutput('restore files', $e->getMessage(), 1);

            return $this->buildRunResult(
                'failed',
                sprintf('Rollback failed after restoring %d file(s): %s. The restore point is intact — retry, or restore from %s via SSH.', $restored, $e->getMessage(), $backupDir),
                0,
                $currentVersion,
                null,
                'main',
                $remoteSanitized,
                1,
                $startedAt,
                Str::limit($capturedOutput, self::OUTPUT_LIMIT)
            );
        } finally {
            if ($didDown) {
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
    }

    /**
     * @return array{status: string, message: string, behind: int, from: string|null, to: string|null, branch: string|null, remoteSanitized: string|null, exit: int, durationMs: int, output: string}
     */
    private function rollbackGit(string $fromHash, User $actor): array
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
                $composer = $this->runProcess($this->composerInstall(), 120);
                $appendOutput('composer install', $composer['output'], $composer['exit']);
            }

            foreach ([['php', 'artisan', 'optimize:clear'], ['php', 'artisan', 'config:clear'], ['php', 'artisan', 'view:clear']] as $cmd) {
                $res = $this->runProcess($cmd, 30);
                $appendOutput(implode(' ', $cmd), $res['output'], $res['exit']);
            }

            $restoredHash = $this->resolveLocalHash();
            $short = $restoredHash !== null ? substr($restoredHash, 0, 7) : substr($fromHash, 0, 7);

            $result = $this->buildRunResult('success', sprintf('Rolled back to version %s. Note: database schema changes were not reversed.', $short), 0, $currentHash, $restoredHash, $branch, $remoteSanitized, 0, $startedAt, Str::limit($capturedOutput, self::OUTPUT_LIMIT));

            $this->auditRollback($actor, (string) $currentHash, (string) $restoredHash, $result['durationMs']);

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

    /** Write the activity_log row for a rollback. Shared by the git and ZIP paths; never throws. */
    private function auditRollback(User $actor, string $from, string $to, int $durationMs): void
    {
        $metadata = ['from' => $from, 'to' => $to, 'status' => 'success', 'duration_ms' => $durationMs];

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
                'action' => 'system.rolledback',
                'description' => sprintf('System rolled back from %s to %s', substr($from, 0, 7), substr($to, 0, 7)),
                'metadata' => json_encode($metadata),
                'properties' => json_encode($metadata),
                'event' => 'rolledback',
                'subject_type' => 'system',
                'subject_id' => null,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            try {
                Log::warning('UpdateService: rollback activity_log insert failed.', ['error' => $e->getMessage()]);
            } catch (Throwable) {
            }
        }
    }

    /**
     * Drop everything check() is allowed to serve from cache.
     *
     * Called by the explicit "Check for updates" action, which must always
     * reach the network — both the GitHub API and `git fetch`.
     */
    public function flushApiCache(): void
    {
        Cache::forget('system.github_commits');
        Cache::forget(self::FETCH_THROTTLE_KEY);
    }
}
