<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\System\AppInfoService;
use App\Services\System\UpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process;
use Throwable;

class SystemController extends Controller
{
    public function __construct(
        private readonly AppInfoService $info,
        private readonly UpdateService $updater,
    ) {}

    public function index(Request $request): View
    {
        $appInfo = $this->info->all();
        $check = $this->updater->check();

        $history = collect();
        try {
            $history = DB::table('activity_log')
                ->whereIn('action', ['system.updated', 'system.rolledback'])
                ->orderByDesc('created_at')
                ->limit(20)
                ->get();
        } catch (Throwable $e) {
            Log::debug('SystemController: activity_log query failed (table may be missing).', ['error' => $e->getMessage()]);
        }

        $activeTab = $request->query('tab', 'about');
        if (! in_array($activeTab, ['about', 'updates', 'changelog'], true)) {
            $activeTab = 'about';
        }

        return view('admin.system.index', compact('appInfo', 'check', 'history', 'activeTab'));
    }

    public function check(Request $request): RedirectResponse|JsonResponse
    {
        // "Check for updates" must never answer from cache — neither the update
        // check nor the git snapshot the About card renders beside it.
        $this->updater->flushApiCache();
        AppInfoService::flushCache();

        $result = $this->updater->check();

        if ($request->expectsJson()) {
            return response()->json($result);
        }

        return redirect()
            ->route('admin.system.index', ['tab' => 'updates'])
            ->with('check_result', $result)
            ->with('activeTab', 'updates');
    }

    public function update(Request $request): RedirectResponse|JsonResponse|StreamedResponse
    {
        $cacheKey = 'system.update_progress.'.$request->user()->id;

        // Clear a finished run's result so the poller doesn't read it as this
        // run's outcome — but leave an in-flight run's progress alone, or a
        // second click blanks the progress of the update already running.
        $existing = Cache::get($cacheKey);
        if (! is_array($existing) || ($existing['done'] ?? true)) {
            Cache::forget($cacheKey);
        }

        // Background-process mode: AJAX callers get an immediate response while
        // the update runs in a fully detached process (not subject to IIS requestTimeout).
        $isAjax = $request->expectsJson() || str_contains($request->header('Accept', ''), 'text/event-stream');
        if ($isAjax) {
            try {
                $actorId = (int) $request->user()->id;

                Cache::put($cacheKey, ['step' => 'waiting', 'progress' => 2, 'message' => 'Starting update...', 'done' => false], 600);

                // Detached launch; see launchDetached() for why the .cmd wrapper exists.
                if ($this->launchDetached('system:run-update --actor='.$actorId, 'system-update-launch.cmd', storage_path('logs/update-bg.log'), storage_path('logs/update.log'))) {
                    return response()->json(['status' => 'started', 'message' => 'Update started in background.']);
                }

                // PowerShell unavailable or failed — fall through to synchronous paths below.
                Log::warning('SystemController: background update launch failed, falling back to synchronous.');
            } catch (Throwable $e) {
                Log::warning('SystemController: background update launch failed, falling back to synchronous.', ['error' => $e->getMessage()]);
            }
        }

        // Streaming mode: the JS progress UI sends Accept: text/event-stream
        if (str_contains($request->header('Accept', ''), 'text/event-stream')) {
            return response()->stream(function () use ($request, $cacheKey) {
                // Disable output buffering so each event flushes immediately
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }

                $emit = function (string $step, string $message, int $progress, bool $done = false, array $extra = []) use ($cacheKey) {
                    $data = array_merge(['step' => $step, 'message' => $message, 'progress' => $progress, 'done' => $done], $extra);
                    Cache::put($cacheKey, $data, 600);
                    echo 'data: '.json_encode($data)."\n\n";
                    flush();
                };

                try {
                    $this->updater->run($request->user(), $emit);
                } catch (Throwable $e) {
                    report($e);
                    $data = ['step' => 'error', 'message' => 'Update failed unexpectedly. Please contact support.', 'progress' => 0, 'done' => true, 'status' => 'unknown'];
                    Cache::put($cacheKey, $data, 600);
                    echo 'data: '.json_encode($data)."\n\n";
                    flush();
                }
            }, 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-store',
                'X-Accel-Buffering' => 'no',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        // JSON / form path — emit writes progress to cache for the polling endpoint
        $emit = function (string $step, string $message, int $progress, bool $done = false, array $extra = []) use ($cacheKey) {
            Cache::put($cacheKey, array_merge(['step' => $step, 'message' => $message, 'progress' => $progress, 'done' => $done], $extra), 600);
        };

        try {
            $result = $this->updater->run($request->user(), $emit);

            if ($request->expectsJson()) {
                return response()->json($result);
            }

            $successStatuses = ['success', 'up_to_date'];

            if (in_array($result['status'] ?? '', $successStatuses, true) && ($result['exit'] ?? 1) === 0) {
                return redirect()
                    ->route('admin.system.index', ['tab' => 'updates'])
                    ->with('success', $result['message'] ?? 'Update completed.')
                    ->with('update_result', $result)
                    ->with('activeTab', 'updates');
            }

            return back()
                ->withErrors(['update' => $result['message'] ?? 'Update failed.'])
                ->with('update_result', $result)
                ->with('activeTab', 'updates');
        } catch (Throwable $e) {
            report($e);

            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'unknown',
                    'message' => 'Update failed: '.$e->getMessage(),
                ], 500);
            }

            return back()
                ->withErrors(['update' => 'Update failed: '.$e->getMessage()])
                ->with('activeTab', 'updates');
        }
    }

    public function progressStatus(Request $request): JsonResponse
    {
        $data = Cache::get('system.update_progress.'.$request->user()->id);

        return response()->json($data ?? ['step' => 'waiting', 'progress' => 0, 'message' => 'Waiting...', 'done' => false]);
    }

    public function rollback(Request $request): RedirectResponse|JsonResponse
    {
        $fromHash = (string) $request->input('from_hash', '');
        $cacheKey = 'system.update_progress.'.$request->user()->id;

        $isAjax = $request->expectsJson() || str_contains($request->header('Accept', ''), 'text/event-stream');

        // Only a plain token is safe to embed in the generated .cmd launcher; anything
        // else takes the synchronous path, where the service validates the target.
        if ($isAjax && strlen($fromHash) <= 100 && preg_match('/^[A-Za-z0-9._-]+$/', $fromHash) === 1) {
            try {
                $existing = Cache::get($cacheKey);
                if (! is_array($existing) || ($existing['done'] ?? true)) {
                    Cache::forget($cacheKey);
                }

                Cache::put($cacheKey, ['step' => 'waiting', 'progress' => 2, 'message' => 'Starting rollback...', 'done' => false], 600);

                if ($this->launchDetached('system:run-rollback --actor='.(int) $request->user()->id.' --from='.$fromHash, 'system-rollback-launch.cmd', storage_path('logs/rollback-bg.log'), storage_path('logs/rollback.log'))) {
                    return response()->json(['status' => 'started', 'message' => 'Rollback started in background.']);
                }

                Log::warning('SystemController: background rollback launch failed, falling back to synchronous.');
            } catch (Throwable $e) {
                Log::warning('SystemController: background rollback launch failed, falling back to synchronous.', ['error' => $e->getMessage()]);
            }
        }

        // JSON / form path — emit writes progress to cache for the polling endpoint
        $emit = function (string $step, string $message, int $progress, bool $done = false, array $extra = []) use ($cacheKey) {
            Cache::put($cacheKey, array_merge(['step' => $step, 'message' => $message, 'progress' => $progress, 'done' => $done], $extra), 600);
        };

        try {
            $result = $this->updater->rollback($fromHash, $request->user(), $emit);

            if ($request->expectsJson()) {
                return response()->json($result);
            }

            if (($result['status'] ?? '') === 'success') {
                return redirect()
                    ->route('admin.system.index', ['tab' => 'updates'])
                    ->with('success', $result['message'])
                    ->with('update_result', $result)
                    ->with('activeTab', 'updates');
            }

            return back()
                ->withErrors(['update' => $result['message'] ?? 'Rollback failed.'])
                ->with('update_result', $result)
                ->with('activeTab', 'updates');
        } catch (Throwable $e) {
            report($e);

            if ($request->expectsJson()) {
                return response()->json(['status' => 'unknown', 'message' => 'Rollback failed: '.$e->getMessage()], 500);
            }

            return back()
                ->withErrors(['update' => 'Rollback failed: '.$e->getMessage()])
                ->with('activeTab', 'updates');
        }
    }

    /**
     * Launch an artisan command as a fully detached background process.
     *
     * Windows detaches through a generated .cmd wrapper; POSIX through
     * `nohup ... &`. See each branch for the platform-specific reasoning.
     */
    protected function launchDetached(string $artisanCommand, string $launcherName, string $bgLog, ?string $verifyLog = null): bool
    {
        $beforeSize = ($verifyLog !== null && is_file($verifyLog)) ? (int) filesize($verifyLog) : 0;

        if (DIRECTORY_SEPARATOR === '\\') {
            // Argument quoting is not survivable across the PHP -> shell -> launcher
            // layers on Windows: PowerShell's -ArgumentList joins its entries with
            // spaces without re-quoting them, and embedded double quotes are stripped
            // in transit. Either way an install path containing a space ("C:\Program
            // Files\...", "...\Local Sites\...") reaches php.exe split in two, and the
            // process dies instantly with "Could not open input file" and no trace.
            // Writing a .cmd wrapper keeps every quote inside a file this code
            // generates, leaving the shell exactly one path to handle.
            //
            // `start "" /B` detaches: cmd returns immediately and the grandchild
            // outlives the request. Its output goes to a file rather than an inherited
            // pipe, so nothing here blocks waiting for EOF.
            // Prefer php.exe over php-cgi.exe for CLI invocation
            $phpDir = dirname(PHP_BINARY);
            $phpBin = is_file($phpDir.DIRECTORY_SEPARATOR.'php.exe')
                ? $phpDir.DIRECTORY_SEPARATOR.'php.exe'
                : PHP_BINARY;

            $launcher = storage_path('app'.DIRECTORY_SEPARATOR.$launcherName);
            file_put_contents($launcher, implode("\r\n", [
                '@echo off',
                'cd /d "'.base_path().'"',
                '"'.$phpBin.'" "'.base_path('artisan').'" '.$artisanCommand
                    .' > "'.$bgLog.'" 2>&1',
                '',
            ]));

            $bgProcess = Process::fromShellCommandline(
                'start "" /B "'.$launcher.'"',
                base_path(), null, null, 15.0
            );

            $bgProcess->run();

            // Logged unconditionally: on IIS this is the only proof the launch
            // branch was reached at all, and which php binary it picked.
            Log::info('SystemController: background launch attempted.', [
                'command' => $artisanCommand,
                'php' => $phpBin,
                'exit' => $bgProcess->getExitCode(),
                'stderr' => substr(trim($bgProcess->getErrorOutput()), 0, 500),
                'launcher' => $launcher,
            ]);
        } else {
            // `start "" /B` is Windows-only; on Linux the launch used to fail and
            // long update/rollback runs fell back to executing inside the request.
            $bgProcess = Process::fromShellCommandline(
                $this->posixLaunchCommand($artisanCommand, $bgLog),
                base_path(), null, null, 15.0
            );

            $bgProcess->run();

            Log::info('SystemController: background launch attempted.', [
                'command' => $artisanCommand,
                'php' => PHP_BINARY,
                'platform' => 'posix',
                'exit' => $bgProcess->getExitCode(),
                'stderr' => substr(trim($bgProcess->getErrorOutput()), 0, 500),
            ]);
        }

        // The shell reports success the moment it backgrounds the command, even
        // when the child itself never runs (missing binary, killed at exec) — the
        // mark file is the only reliable proof the child actually started.
        if (! $bgProcess->isSuccessful()) {
            return false;
        }

        if ($verifyLog !== null && ! $this->detachedChildStarted($verifyLog, $beforeSize)) {
            Log::warning('SystemController: detached launch produced no child output — falling back to synchronous.', ['command' => $artisanCommand, 'verify' => $verifyLog]);

            return false;
        }

        return true;
    }

    /**
     * Whether a detached child actually produced output since the launch.
     *
     * The detached commands write a `booted` mark as their first instruction, so
     * a log that grows past its pre-launch size is the signal the child started.
     */
    protected function detachedChildStarted(string $logFile, int $beforeSize, float $timeoutSeconds = 3.0): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            clearstatcache(true, $logFile);

            if (is_file($logFile) && filesize($logFile) > $beforeSize) {
                return true;
            }

            if (microtime(true) >= $deadline) {
                return false;
            }

            usleep(250000);
        } while (true);
    }

    /**
     * Build the detached POSIX shell command for an artisan run.
     *
     * `nohup setsid -f` puts the child in a new session so the web server
     * tearing down the request's process group cannot reap it; nohup remains
     * as belt-and-braces. stdout and stderr are redirected to the background
     * log.
     */
    protected function posixLaunchCommand(string $artisanCommand, string $bgLog): string
    {
        $tokens = array_map(
            fn (string $token): string => $this->posixEscape($token),
            explode(' ', $artisanCommand)
        );

        return 'nohup setsid -f '.$this->posixEscape(PHP_BINARY)
            .' '.$this->posixEscape(base_path('artisan'))
            .' '.implode(' ', $tokens)
            .' > '.$this->posixEscape($bgLog).' 2>&1 &';
    }

    /**
     * Escape a single shell argument for POSIX sh.
     *
     * escapeshellarg() is platform-dependent: on Windows it emits double-quote
     * wrapping, which is wrong for the POSIX command built here. Always emit the
     * single-quote form so the command is correct on the Linux host that runs it.
     */
    protected function posixEscape(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }
}
