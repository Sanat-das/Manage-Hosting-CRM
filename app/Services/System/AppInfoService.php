<?php

declare(strict_types=1);

namespace App\Services\System;

use App\Services\Cron\ScheduleInspector;
use App\Services\Installer\InstallerService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Read-only catalogue for the About & Update page.
 *
 * Aggregates: app version/commit/build, environment, PHP/Laravel/composer,
 * server pre-flight health, scheduler heartbeat, and CHANGELOG excerpt.
 *
 * All git interaction is via Symfony Process with short timeouts and never
 * throws — missing git / non-git checkout returns graceful nulls.
 */
final class AppInfoService
{
    /**
     * Resolve the application version.
     *
     * Priority: config('app.version') if not 'dev' -> VERSION file -> git describe --tags --always -> git rev-parse --short HEAD -> 'dev'.
     */
    public function version(): string
    {
        $configured = trim((string) config('app.version', ''));

        if ($configured !== '' && strtolower($configured) !== 'dev') {
            return $configured;
        }

        $versionFile = base_path('VERSION');

        if (is_file($versionFile)) {
            try {
                $fileVersion = trim((string) file_get_contents($versionFile));

                if ($fileVersion !== '' && strtolower($fileVersion) !== 'dev') {
                    return $fileVersion;
                }
            } catch (Throwable $e) {
                Log::debug('AppInfoService: failed to read VERSION file.', ['error' => $e->getMessage()]);
            }
        }

        $describe = $this->runProcess(['git', 'describe', '--tags', '--always'], 3);

        if ($describe['success'] && trim($describe['output']) !== '') {
            return trim($describe['output']);
        }

        $short = $this->runProcess(['git', 'rev-parse', '--short', 'HEAD'], 3);

        if ($short['success'] && trim($short['output']) !== '') {
            return trim($short['output']);
        }

        return 'dev';
    }

    /**
     * Git state snapshot. Every field is nullable — no exception is thrown when git is missing.
     *
     * @return array{branch: string|null, commit: string|null, short: string|null, date: string|null, dirty: bool|null, remote: string|null, remoteUrlRaw: string|null, ahead: int|null, behind: int|null}
     */
    public function gitInfo(): array
    {
        $branch = null;
        $commit = null;
        $short = null;
        $date = null;
        $dirty = null;
        $remoteRaw = null;
        $remote = null;
        $ahead = null;
        $behind = null;

        // Branch
        $branchRes = $this->runProcess(['git', 'rev-parse', '--abbrev-ref', 'HEAD'], 3);
        if ($branchRes['success'] && trim($branchRes['output']) !== '') {
            $branch = trim($branchRes['output']);
        }

        // Full commit hash
        $commitRes = $this->runProcess(['git', 'rev-parse', 'HEAD'], 3);
        if ($commitRes['success'] && trim($commitRes['output']) !== '') {
            $commit = trim($commitRes['output']);
        }

        // Short hash
        $shortRes = $this->runProcess(['git', 'rev-parse', '--short', 'HEAD'], 3);
        if ($shortRes['success'] && trim($shortRes['output']) !== '') {
            $short = trim($shortRes['output']);
        }

        // Commit date
        $dateRes = $this->runProcess(['git', 'log', '-1', '--format=%ci'], 3);
        if ($dateRes['success'] && trim($dateRes['output']) !== '') {
            $date = trim($dateRes['output']);
        }

        // Dirty flag — ignore untracked/ignored (?? / !!) which do not block pull
        $statusRes = $this->runProcess(['git', 'status', '--porcelain'], 3);
        if ($statusRes['success']) {
            $raw = trim($statusRes['output']);
            if ($raw === '') {
                $dirty = false;
            } else {
                $dirty = false;
                foreach (explode("\n", $raw) as $line) {
                    $t = ltrim($line);
                    if ($t === '' || str_starts_with($t, '??') || str_starts_with($t, '!!')) {
                        continue;
                    }
                    $dirty = true;
                    break;
                }
            }
        }

        // Remote URL (raw + sanitized)
        $remoteRes = $this->runProcess(['git', 'remote', 'get-url', 'origin'], 3);
        if ($remoteRes['success'] && trim($remoteRes['output']) !== '') {
            $remoteRaw = trim($remoteRes['output']);
            $remote = $this->sanitizeRemote($remoteRaw);
        }

        // Ahead / behind vs origin/main — only when remote exists
        if ($remoteRaw !== null) {
            $countRes = $this->runProcess(['git', 'rev-list', '--left-right', '--count', 'HEAD...origin/main'], 3);
            if ($countRes['success'] && trim($countRes['output']) !== '') {
                $parts = preg_split('/\s+/', trim($countRes['output']));

                if (is_array($parts) && count($parts) === 2) {
                    $ahead = (int) $parts[0];
                    $behind = (int) $parts[1];
                }
            }
        }

        return [
            'branch' => $branch,
            'commit' => $commit,
            'short' => $short,
            'date' => $date,
            'dirty' => $dirty,
            'remote' => $remote,
            'remoteUrlRaw' => $remoteRaw,
            'ahead' => $ahead,
            'behind' => $behind,
        ];
    }

    /**
     * Server + scheduler health.
     *
     * @return array{preflight: array<int, array{name: string, passed: bool, detail: string}>, scheduler: array{lastTickAt: \Illuminate\Support\Carbon|null, schedulerIsHealthy: bool, staleAfter: int, paused: bool}}
     */
    public function health(): array
    {
        $preflight = [];
        try {
            $preflight = (new InstallerService())->preflightChecks();
        } catch (Throwable $e) {
            Log::warning('AppInfoService: preflightChecks failed.', ['error' => $e->getMessage()]);
        }

        $schedulerHealth = $this->schedulerHealth();

        return [
            'preflight' => $preflight,
            'scheduler' => $schedulerHealth,
        ];
    }

    /**
     * Framework & dependency snapshot.
     *
     * @return array{laravel: string, php: string, composerHash: string|null, packages: array<string, string>}
     */
    public function framework(): array
    {
        $laravel = 'unknown';
        try {
            $laravel = app()->version();
        } catch (Throwable) {
        }

        $composerHash = null;
        $lockPath = base_path('composer.lock');
        if (is_file($lockPath)) {
            try {
                $composerHash = md5((string) file_get_contents($lockPath));
            } catch (Throwable $e) {
                Log::debug('AppInfoService: failed to hash composer.lock.', ['error' => $e->getMessage()]);
            }
        }

        $packages = $this->resolveKeyPackages();

        return [
            'laravel' => $laravel,
            'php' => PHP_VERSION,
            'composerHash' => $composerHash,
            'packages' => $packages,
        ];
    }

    /**
     * Excerpt of CHANGELOG.md (first N lines).
     */
    public function changelog(int $lines = 80): string
    {
        $path = base_path('CHANGELOG.md');

        if (! is_file($path)) {
            return '';
        }

        try {
            $content = File::get($path);
        } catch (Throwable $e) {
            Log::debug('AppInfoService: failed to read CHANGELOG.md.', ['error' => $e->getMessage()]);

            return '';
        }

        if (trim($content) === '') {
            return '';
        }

        $allLines = explode("\n", $content);
        $excerpt = array_slice($allLines, 0, $lines);

        return implode("\n", $excerpt);
    }

    /**
     * Full aggregated snapshot for the About page.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $installLock = base_path('install.lock');
        $installed = is_file($installLock);
        $installedAt = null;

        if ($installed) {
            try {
                $mtime = filemtime($installLock);
                $installedAt = $mtime !== false ? date(DATE_ATOM, $mtime) : null;
            } catch (Throwable) {
            }
        }

        $maintenance = false;
        try {
            $maintenance = app()->isDownForMaintenance();
        } catch (Throwable) {
        }

        return [
            'app' => [
                'name' => config('app.name'),
                'env' => config('app.env'),
                'debug' => (bool) config('app.debug'),
                'url' => config('app.url'),
                'timezone' => config('app.timezone'),
                'locale' => config('app.locale'),
                'installed' => $installed,
                'installedAt' => $installedAt,
                'maintenance' => $maintenance,
            ],
            'version' => $this->version(),
            'git' => $this->gitInfo(),
            'health' => $this->health(),
            'framework' => $this->framework(),
            'changelog' => $this->changelog(),
        ];
    }

    /**
     * @return array{lastTickAt: \Illuminate\Support\Carbon|null, schedulerIsHealthy: bool, staleAfter: int, paused: bool}
     */
    private function schedulerHealth(): array
    {
        $lastTickAt = null;
        $healthy = false;
        $staleAfter = ScheduleInspector::STALE_AFTER_SECONDS;
        $paused = false;

        try {
            /** @var ScheduleInspector $inspector */
            $inspector = app(ScheduleInspector::class);
            $lastTickAt = $inspector->lastTickAt();
            $healthy = $inspector->schedulerIsHealthy();
            $staleAfter = ScheduleInspector::STALE_AFTER_SECONDS;
        } catch (Throwable $e) {
            Log::debug('AppInfoService: ScheduleInspector unavailable.', ['error' => $e->getMessage()]);
            // Fallback to cache heartbeat directly
            try {
                $stamp = Cache::get(ScheduleInspector::HEARTBEAT_KEY);
                $lastTickAt = $stamp !== null ? \Illuminate\Support\Carbon::parse($stamp) : null;
                $healthy = $lastTickAt !== null
                    && $lastTickAt->diffInSeconds(\Illuminate\Support\Carbon::now(), true) <= $staleAfter;
            } catch (Throwable) {
            }
        }

        try {
            $paused = (bool) Cache::get('illuminate:schedule:paused', false);
        } catch (Throwable) {
        }

        return [
            'lastTickAt' => $lastTickAt,
            'schedulerIsHealthy' => $healthy,
            'staleAfter' => $staleAfter,
            'paused' => $paused,
        ];
    }

    /**
     * Resolve versions for key packages from composer.lock.
     *
     * @return array<string, string>
     */
    private function resolveKeyPackages(): array
    {
        $keys = [
            'laravel/framework',
            'laravel/fortify',
            'laravel/sanctum',
            'spatie/laravel-settings',
            'barryvdh/laravel-dompdf',
            'joropezco/laravel-adminlte',
            'adminlte/adminlte',
        ];

        $lockPath = base_path('composer.lock');

        if (! is_file($lockPath)) {
            return [];
        }

        try {
            $raw = (string) file_get_contents($lockPath);
            $decoded = json_decode($raw, true);
        } catch (Throwable) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $packages = [];
        $all = array_merge($decoded['packages'] ?? [], $decoded['packages-dev'] ?? []);

        foreach ($all as $pkg) {
            if (! is_array($pkg) || ! isset($pkg['name'], $pkg['version'])) {
                continue;
            }

            $name = (string) $pkg['name'];

            if (in_array($name, $keys, true)) {
                $packages[$name] = (string) $pkg['version'];
            }
        }

        // Fallback: also surface any package that looks like an AdminLTE integration
        // if the exact key above did not match (e.g. colorlibhq/adminlte-laravel).
        foreach ($all as $pkg) {
            if (! is_array($pkg) || ! isset($pkg['name'], $pkg['version'])) {
                continue;
            }
            $name = (string) $pkg['name'];
            if (str_contains($name, 'adminlte') && ! isset($packages[$name])) {
                $packages[$name] = (string) $pkg['version'];
            }
        }

        return $packages;
    }

    /**
     * Run a command via Symfony Process with a hard timeout.
     *
     * @param  list<string>  $cmd
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
            Log::debug('AppInfoService: process failed.', ['cmd' => $cmd, 'error' => $e->getMessage()]);

            return [
                'output' => $e->getMessage(),
                'exit' => 1,
                'success' => false,
            ];
        }
    }

    private function sanitizeRemote(string $url): string
    {
        // Strip embedded token/userinfo: https://token@host -> https://***@host
        $sanitized = preg_replace('#https://[^@]+@#', 'https://***@', $url);

        return $sanitized === null ? $url : $sanitized;
    }
}
