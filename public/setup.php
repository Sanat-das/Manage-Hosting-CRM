<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Fresh-clone dependency installer (framework-free)
|--------------------------------------------------------------------------
|
| vendor/ is gitignored, so the first request a fresh clone answers cannot
| reach Laravel: no autoloader, no Blade, no routes, no session, no CSRF, no
| helpers. This file is deliberately plain PHP with inline styles and no
| dependencies so that it runs before the framework can be loaded.
|
| WHY THIS PAGE IS SAFE TO LEAVE UNAUTHENTICATED
|
| 1. It only ever does anything while vendor/autoload.php is absent. The
|    moment the install completes the page stops working: it redirects to the
|    application, which then owns the request and the /install wizard.
| 2. It runs exactly three hard-coded commands - `composer install
|    --no-interaction`, `npm ci` (falling back to `npm install
|    --ignore-scripts`) and `npm run build`. No string from the URL, the
|    request, the environment or a config file is ever interpolated into a
|    command line, and a one-flight lock in bootstrap/cache/ keeps a single
|    install running at a time.
| 3. Before the install there is no database, no settings and no user to
|    read, so there is nothing here for an unauthenticated caller to reach.
|
| A machine that cannot run the installer (no PHP process spawning, a
| read-only application directory, or no composer/npm on PATH) gets the same
| printed instructions as before - this page is never a dead end.
|
*/

const SETUP_LOCK_TTL = 900;
const SETUP_LOG_FILE = '/bootstrap/cache/fresh-install.log';
const SETUP_LOCK_FILE = '/bootstrap/cache/fresh-install.lock';

/**
 * The install steps, in order. Every command is a fixed literal; the second
 * command of a step is the fallback tried when the first one fails.
 */
const SETUP_STEPS = [
    [
        'step' => '1/3',
        'label' => 'Install the PHP dependencies',
        'commands' => ['composer install --no-interaction'],
    ],
    [
        'step' => '2/3',
        'label' => 'Install the Node packages',
        'commands' => ['npm ci', 'npm install --ignore-scripts'],
    ],
    [
        'step' => '3/3',
        'label' => 'Build the front-end assets',
        'commands' => ['npm run build'],
    ],
];

/**
 * Build an environment for the background install (and its children) so
 * Composer has a usable COMPOSER_HOME even when the web process that starts
 * the install has none. php-cgi workers often lack APPDATA/COMPOSER_HOME, so
 * Composer then refuses with "APPDATA or COMPOSER_HOME must be set" even
 * though the same `composer install` works from a shell.
 *
 * On Windows an interactive user's cache is reused when it exists, kept warm
 * on the first run thereafter; every location falls back to a directory under
 * bootstrap/cache, which is gitignored and writable by the server.
 */
function setup_install_environment(array $paths): array
{
    $env = $_ENV;

    foreach (['PATH', 'ComSpec', 'SystemRoot', 'PATHEXT', 'APPDATA', 'LOCALAPPDATA', 'HOME', 'USERNAME', 'USER', 'TEMP', 'TMP', 'TERM'] as $key) {
        if (! array_key_exists($key, $env) || $env[$key] === '') {
            $value = getenv($key);

            if ($value !== false && $value !== '') {
                $env[$key] = $value;
            }
        }
    }

    if (PHP_OS_FAMILY === 'Windows') {
        if (! isset($env['APPDATA']) || $env['APPDATA'] === '') {
            $env['APPDATA'] = sys_get_temp_dir();
        }

        $composerHome = $env['APPDATA'].DIRECTORY_SEPARATOR.'Composer';
    } else {
        if (! isset($env['HOME']) || $env['HOME'] === '') {
            $env['HOME'] = sys_get_temp_dir();
        }

        $composerHome = $env['HOME'].DIRECTORY_SEPARATOR.'.composer';
    }

    // Reuse an existing interactive composer home as the cache source when it
    // is present, so the install does not re-download 170 packages.
    $existingHome = PHP_OS_FAMILY === 'Windows'
        ? [($env['APPDATA'] ?? ''), 'Composer']
        : [($env['HOME'] ?? ''), '.composer'];

    $candidate = implode(DIRECTORY_SEPARATOR, array_filter($existingHome, 'strlen')) ?? '';

    if ($candidate !== '' && is_dir($candidate)) {
        $composerHome = $candidate;
    }

    foreach (['COMPOSER_HOME', 'COMPOSER_CACHE_DIR'] as $key) {
        if (! isset($env[$key]) || $env[$key] === '') {
            $env[$key] = $key === 'COMPOSER_HOME'
                ? $composerHome
                : $composerHome.DIRECTORY_SEPARATOR.'cache';
        }
    }

    foreach ([$env['COMPOSER_HOME'], $env['COMPOSER_CACHE_DIR']] as $directory) {
        @mkdir($directory, 0775, true);
    }

    return $env;
}

/**
 * @return array{root: string, autoload: string, cache: string, log: string, lock: string, env: array}
 */
function setup_paths(): array
{
    $root = dirname(__DIR__);

    $paths = [
        'root' => $root,
        'autoload' => $root.'/vendor/autoload.php',
        'cache' => $root.'/bootstrap/cache',
        'log' => $root.SETUP_LOG_FILE,
        'lock' => $root.SETUP_LOCK_FILE,
    ];

    // Computed after the rest so the helper (which only needs $root) can also
    // fill $paths with the Composer home dir it chose.
    $paths['env'] = setup_install_environment($paths);

    return $paths;
}

function setup_esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function setup_is_windows(): bool
{
    $family = defined('PHP_OS_FAMILY') ? PHP_OS_FAMILY : null;

    if ($family === null) {
        $family = DIRECTORY_SEPARATOR === '\\' ? 'Windows' : 'Linux';
    }

    return $family === 'Windows';
}

/**
 * @return list<string> Lower-cased names of the functions disabled on this build.
 */
function setup_disabled_functions(): array
{
    $disabled = (string) ini_get('disable_functions');

    if ($disabled === '') {
        return [];
    }

    $names = array_map(static fn (string $name): string => strtolower(trim($name)), explode(',', $disabled));

    return array_values(array_filter($names, static fn (string $name): bool => $name !== ''));
}

function setup_can_spawn_processes(): bool
{
    return function_exists('proc_open')
        && function_exists('proc_get_status')
        && ! in_array('proc_open', setup_disabled_functions(), true);
}

/**
 * Run a fixed probe command and return the version line, or null when the
 * tool cannot be started. The command is always a literal written in this
 * file, never anything derived from the request. A banner carrying a path is
 * trimmed away: only a version-looking line is ever returned.
 */
function setup_resolve_tool(string $command, int $timeout = 60): ?string
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = @proc_open($command, $descriptors, $pipes);
    if (! is_resource($process)) {
        return null;
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $output = '';
    $open = [1 => $pipes[1], 2 => $pipes[2]];
    $deadline = microtime(true) + $timeout;

    while ($open !== [] && microtime(true) < $deadline) {
        $read = array_values($open);
        $write = null;
        $except = null;

        if (stream_select($read, $write, $except, 2) === false) {
            break;
        }

        foreach ($read as $stream) {
            $chunk = fread($stream, 8192);
            if ($chunk !== '' && $chunk !== false) {
                $output .= $chunk;
            }

            if (! feof($stream)) {
                continue;
            }

            foreach ($open as $descriptor => $candidate) {
                if ($candidate === $stream) {
                    fclose($candidate);
                    unset($open[$descriptor]);
                }
            }
        }

        usleep(50000);
    }

    foreach ($open as $stream) {
        fclose($stream);
    }

    if (proc_get_status($process)['running']) {
        proc_terminate($process);
    }

    if ((int) proc_close($process) !== 0) {
        return null;
    }

    $normalised = trim(str_replace("\r\n", "\n", trim($output)));
    $firstLine = $normalised === '' ? '' : (explode("\n", $normalised)[0] ?? '');

    return preg_match('/\d+\.\d+/', $firstLine) === 1 ? mb_substr(trim($firstLine), 0, 60) : 'available';
}

/**
 * Probe this machine once and report what is missing. Every result is cached
 * in a local variable: no probe runs twice in a request.
 *
 * @return array{can: bool, missing: list<string>, composer: ?string, npm: ?string}
 */
function setup_machine_probe(array $paths): array
{
    $missing = [];
    $canSpawn = setup_can_spawn_processes();
    $cacheWritable = is_dir($paths['cache'])
        ? is_writable($paths['cache'])
        : (@mkdir($paths['cache'], 0775, true) && is_writable($paths['cache']));

    if (! $canSpawn) {
        $missing[] = 'This server cannot start external programs, so it cannot run the installer itself.';
    }

    if (! is_writable($paths['root'])) {
        $missing[] = 'The application directory is not writable, so dependencies cannot be downloaded into vendor/.';
    }

    if (! $cacheWritable) {
        $missing[] = 'The bootstrap/cache directory is not writable, so the install could not log its progress.';
    }

    $composer = $canSpawn ? setup_resolve_tool('composer --version') : null;
    $npm = $canSpawn ? setup_resolve_tool('npm --version') : null;

    if ($composer === null) {
        $missing[] = 'Composer is not installed or is not on the PATH this server runs commands with.';
    }

    if ($npm === null) {
        $missing[] = 'Node.js / npm is not installed or is not on the PATH this server runs commands with.';
    }

    return [
        'can' => $missing === [],
        'missing' => $missing,
        'composer' => $composer,
        'npm' => $npm,
    ];
}

function setup_log_write(string $log, string $line): void
{
    @file_put_contents($log, $line."\n", FILE_APPEND | LOCK_EX);
}

function setup_log_output(string $log, string $chunk): void
{
    if ($chunk === '') {
        return;
    }

    $chunk = str_replace("\r\n", "\n", $chunk);

    if (substr($chunk, -1) !== "\n") {
        $chunk .= "\n";
    }

    @file_put_contents($log, $chunk, FILE_APPEND | LOCK_EX);
}

function setup_release_lock(string $lock): void
{
    @unlink($lock);
}

/**
 * Claim a lock the web process already took, with the PID that actually has to
 * stay alive. The request that starts the install ends immediately, so the PID
 * it recorded is worthless as proof of life by the time anyone looks.
 */
function setup_claim_lock(string $lock): void
{
    @file_put_contents($lock, (string) json_encode(['started' => gmdate('c'), 'pid' => getmypid()]));
}

/**
 * Whether the install recorded in $lock is still running. A confirmed-dead PID
 * means the install died with it, so the lock is a leftover and may be taken
 * over. Anything unverifiable is treated as live: stealing a lock we cannot
 * disprove is worse than waiting out the TTL.
 */
function setup_lock_is_live(string $lock): bool
{
    $record = @json_decode((string) @file_get_contents($lock), true);
    $pid = is_array($record) ? (int) ($record['pid'] ?? 0) : 0;

    if ($pid <= 0) {
        $age = time() - (int) @filemtime($lock);

        return $age >= 0 && $age < SETUP_LOCK_TTL;
    }

    return setup_pid_alive($pid) !== false;
}

/**
 * Whether a process id still exists: true when it does, false when it is gone,
 * and null when this server cannot tell. proc_open only, and only ever with a
 * literal command plus an integer PID, so no caller-supplied string is ever
 * interpolated.
 */
function setup_pid_alive(int $pid): ?bool
{
    if (PHP_OS_FAMILY === 'Windows') {
        $command = 'tasklist /FI "PID eq '.$pid.'" /NH';
        $alive = static fn (string $output): bool => preg_match('/\b'.$pid.'\b/', $output) === 1;
    } else {
        $command = 'ps -p '.$pid.' -o pid=';
        $alive = static fn (string $output): bool => trim($output) !== '';
    }

    $process = @proc_open($command, [1 => ['pipe', 'w']], $pipes);

    if (! is_resource($process)) {
        return null;
    }

    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($process);

    return $alive($output);
}

/**
 * The one-flight lock: O_EXCL so two callers can never both win it.
 *
 * @return array{acquired: bool, reason: string}
 */
function setup_acquire_lock(array $paths): array
{
    if (! is_dir($paths['cache']) && ! @mkdir($paths['cache'], 0775, true) && ! is_dir($paths['cache'])) {
        return [
            'acquired' => false,
            'reason' => 'The bootstrap/cache directory is missing and could not be created, so the install cannot be logged.',
        ];
    }

    $handle = @fopen($paths['lock'], 'x');

    if ($handle === false) {
        // A lock whose runner is gone is a leftover, not an install: take it
        // over at once instead of stranding the cloner until the TTL expires.
        if (setup_lock_is_live($paths['lock'])) {
            return [
                'acquired' => false,
                'reason' => 'An install is already running on this server. This page follows its progress.',
            ];
        }

        @unlink($paths['lock']);
        $handle = @fopen($paths['lock'], 'x');
    }

    if ($handle === false) {
        return [
            'acquired' => false,
            'reason' => 'The install lock file could not be created in bootstrap/cache.',
        ];
    }

    fwrite($handle, (string) json_encode(['started' => gmdate('c'), 'pid' => getmypid()]));
    fclose($handle);

    return ['acquired' => true, 'reason' => ''];
}

/**
 * The PHP binary to run the background install with. A cgi/fpm binary cannot
 * execute a script, so only a real CLI binary is used and PATH is the
 * fallback.
 */
function setup_php_binary(): string
{
    $binary = defined('PHP_BINARY') ? PHP_BINARY : '';

    if ($binary !== ''
        && @is_file($binary)
        && preg_match('/^php(?:-win)?(?:\.exe)?$/i', basename($binary)) === 1
    ) {
        return escapeshellarg($binary);
    }

    return 'php';
}

/**
 * Start the background install: this page's own CLI mode (`php setup.php
 * run`) does the work, so the HTTP request returns immediately while the
 * steps stream into the log.
 *
 * The detached process redirects its own stdout/stderr to a companion
 * (`fresh-install.out`) rather than to the log itself: on Windows, cmd's
 * `>>` open mode keeps the log file locked, so every `file_put_contents()`
 * the worker performs against it silently fails and the log stays empty. The
 * worker writes the log itself, and the out-file only catches noise from the
 * detached process. The command line is assembled from literals and this
 * file's own paths only.
 */
function setup_spawn_background(array $paths): bool
{
    $binary = setup_php_binary();
    $script = escapeshellarg($paths['root'].'/public/setup.php');
    $console = escapeshellarg($paths['cache'].'/fresh-install.out');

    $command = setup_is_windows()
        ? 'cmd /c start /B "" '.$binary.' '.$script.' run > '.$console.' 2>&1 < NUL'
        : 'nohup '.$binary.' '.$script.' run > '.$console.' 2>&1 < /dev/null &';

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = @proc_open($command, $descriptors, $pipes, $paths['root'], $paths['env']);
    if (! is_resource($process)) {
        return false;
    }

    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return true;
}

/**
 * Run one command from the application root and append its output to the
 * log. Returns the exit code.
 */
function setup_run_command(string $command, string $root, string $log, array $env = []): int
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = @proc_open($command, $descriptors, $pipes, $root, $env !== [] ? $env : null);
    if (! is_resource($process)) {
        setup_log_write($log, 'STEP FAILED (exit code 127) - the command could not be started');

        return 127;
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $open = [1 => $pipes[1], 2 => $pipes[2]];

    while ($open !== []) {
        $read = array_values($open);
        $write = null;
        $except = null;

        if (stream_select($read, $write, $except, 2) === false) {
            break;
        }

        foreach ($read as $stream) {
            $chunk = fread($stream, 8192);
            if ($chunk !== '' && $chunk !== false) {
                setup_log_output($log, $chunk);
            }

            if (! feof($stream)) {
                continue;
            }

            foreach ($open as $descriptor => $candidate) {
                if ($candidate === $stream) {
                    fclose($candidate);
                    unset($open[$descriptor]);
                }
            }
        }

        usleep(20000);
    }

    return (int) proc_close($process);
}

/**
 * The background install itself: every step in order, each one's output
 * logged, with the npm fallback when `npm ci` has nothing to install from.
 */
function setup_run_install(array $paths): int
{
    @file_put_contents($paths['log'], '');
    setup_log_write($paths['log'], '=== Fresh install started '.gmdate('c').' ===');

    foreach (SETUP_STEPS as $step) {
        $attempts = count($step['commands']);
        $exitCode = 1;

        foreach ($step['commands'] as $attempt => $command) {
            setup_log_write($paths['log'], 'STEP '.$step['step'].': '.$command);
            $exitCode = setup_run_command($command, $paths['root'], $paths['log'], $paths['env']);

            if ($exitCode === 0) {
                break;
            }

            if ($attempt + 1 < $attempts) {
                setup_log_write(
                    $paths['log'],
                    'STEP '.$step['step'].' FAILED (exit code '.$exitCode.') - retrying: '.$step['commands'][$attempt + 1],
                );
            }
        }

        if ($exitCode !== 0) {
            setup_log_write($paths['log'], 'STEP '.$step['step'].' FAILED (exit code '.$exitCode.')');
            setup_log_write($paths['log'], 'FAILED');
            setup_release_lock($paths['lock']);

            return $exitCode;
        }

        setup_log_write($paths['log'], 'STEP '.$step['step'].' OK (exit code 0)');
    }

    setup_log_write($paths['log'], 'DONE');
    setup_release_lock($paths['lock']);

    return 0;
}

/**
 * Read the install log back as the state the progress page polls for.
 *
 * @return array{
 *     state: string,
 *     step: string,
 *     step_index: int,
 *     step_total: int,
 *     step_status: string,
 *     command: string,
 *     message: string,
 *     log: list<string>
 * }
 */
function setup_status(array $paths): array
{
    $total = count(SETUP_STEPS);
    $lines = [];
    $raw = is_file($paths['log']) ? (string) @file_get_contents($paths['log']) : '';

    if ($raw !== '') {
        $split = preg_split('/\r\n|\r|\n/', rtrim($raw, "\r\n")) ?: [];

        foreach ($split as $line) {
            if ($line !== '') {
                $lines[] = $line;
            }
        }
    }

    $state = 'idle';
    $index = 0;
    $command = '';
    $stepStatus = 'pending';

    foreach ($lines as $line) {
        if (preg_match('/^STEP (\d+)\/(\d+): (.+)$/', $line, $matches) === 1) {
            $index = max(1, (int) $matches[1]) - 1;
            $command = $matches[3];
            $stepStatus = 'running';
            $state = 'running';

            continue;
        }

        if (preg_match('/^STEP (\d+)\/(\d+) OK \(exit code \d+\)$/', $line, $matches) === 1) {
            $index = max(1, (int) $matches[1]) - 1;
            $stepStatus = 'ok';

            continue;
        }

        if (preg_match('/^STEP (\d+)\/(\d+) FAILED \(exit code \d+\)/', $line, $matches) === 1) {
            $index = max(1, (int) $matches[1]) - 1;
            $stepStatus = 'failed';

            continue;
        }

        if ($line === 'DONE') {
            $state = 'done';
            $stepStatus = 'ok';
            $index = $total;

            continue;
        }

        if ($line === 'FAILED') {
            $state = 'failed';
        }
    }

    $position = $index + 1 <= $total ? 'step '.($index + 1).' of '.$total : '';

    if ($state === 'done') {
        $message = 'The dependencies are installed. The setup wizard is ready.';
    } elseif ($state === 'failed') {
        $message = $position === ''
            ? 'The install stopped. The log below shows why.'
            : 'The install stopped during '.$position.'. The log below shows why.';
    } elseif ($state === 'running') {
        $message = 'Running '.$position.': '.$command;
    } else {
        $message = 'Waiting for the install to start.';
    }

    return [
        'state' => $state,
        'step' => ($index + 1).'/'.$total,
        'step_index' => $index,
        'step_total' => $total,
        'step_status' => $stepStatus,
        'command' => $command,
        'message' => $message,
        'log' => array_slice($lines, -20),
    ];
}

/**
 * The one self-contained document every branch of this page renders: inline
 * styles, no remote assets, responsive down to a 390px phone.
 */
function setup_document(string $title, string $subtitle, string $body, string $script = ''): string
{
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>$title</title>
<style>
:root {
    --bg: #f6f7f9; --card: #ffffff; --text: #1b1b18; --muted: #6b7280;
    --border: #e5e7eb; --accent: #2563eb; --ok: #15803d; --err: #b91c1c;
}
@media (prefers-color-scheme: dark) {
    :root {
        --bg: #0a0a0a; --card: #141414; --text: #ededec; --muted: #9ca3af;
        --border: #2d2d2d; --accent: #3b82f6; --ok: #4ade80; --err: #f87171;
    }
}
* { box-sizing: border-box; }
body {
    margin: 0; font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    background: var(--bg); color: var(--text);
}
.shell { max-width: 640px; margin: 0 auto; padding: 48px 16px 32px; }
.brand { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; }
.brand .mark {
    width: 40px; height: 40px; border-radius: 10px; background: var(--accent); color: #fff;
    display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 18px; flex: none;
}
.brand h1 { font-size: 20px; margin: 0; line-height: 1.2; }
.brand p { margin: 2px 0 0; font-size: 13px; color: var(--muted); }
.card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 28px; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
h2 { font-size: 15px; margin: 0 0 4px; }
p { font-size: 13.5px; line-height: 1.55; margin: 0 0 14px; }
p.last { margin-bottom: 0; }
.note { font-size: 12.5px; color: var(--muted); margin: 12px 0 0; }
ul.reasons { list-style: none; margin: 0 0 18px; padding: 0; }
ul.reasons li { padding: 6px 0 6px 20px; position: relative; font-size: 13.5px; }
ul.reasons li::before { content: ""; position: absolute; left: 2px; top: 13px; width: 6px; height: 6px; border-radius: 50%; background: var(--err); }
ol.steps { list-style: none; margin: 0 0 18px; padding: 0; }
ol.steps li {
    display: flex; align-items: flex-start; gap: 10px; padding: 9px 0;
    border-bottom: 1px solid var(--border); font-size: 13.5px; line-height: 1.5;
}
ol.steps li:last-child { border-bottom: none; }
ol.steps .dot {
    width: 18px; height: 18px; border-radius: 50%; flex: none; margin-top: 2px;
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 700; background: var(--border); color: var(--muted);
}
ol.steps li[data-status="running"] .dot { background: rgba(37,99,235,.15); color: var(--accent); }
ol.steps li[data-status="ok"] .dot { background: rgba(21,128,61,.12); color: var(--ok); }
ol.steps li[data-status="failed"] .dot { background: rgba(185,28,28,.12); color: var(--err); }
code {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Courier New", monospace;
    font-size: 12.5px; background: rgba(107,114,128,.12); padding: 1px 5px; border-radius: 5px;
}
pre.command {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Courier New", monospace;
    font-size: 12.5px; background: rgba(107,114,128,.1); border: 1px solid var(--border);
    border-radius: 8px; padding: 10px 12px; overflow: auto; margin: 0 0 14px;
}
pre.log {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Courier New", monospace;
    font-size: 12px; line-height: 1.5; background: rgba(107,114,128,.08);
    border: 1px solid var(--border); border-radius: 8px; padding: 12px; margin: 0 0 14px;
    max-height: 240px; overflow: auto; white-space: pre-wrap; word-break: break-word;
}
.btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 8px; width: 100%;
    padding: 11px 16px; border: 0; border-radius: 9px; background: var(--accent); color: #fff;
    font-size: 14.5px; font-weight: 600; cursor: pointer; text-decoration: none;
}
.btn:hover { filter: brightness(1.06); }
.status-line { font-size: 13px; color: var(--muted); margin: 0 0 14px; }
.alert { padding: 12px 14px; border-radius: 10px; font-size: 13px; margin-bottom: 16px; border: 1px solid; }
.alert:last-child { margin-bottom: 0; }
.alert-info { background: rgba(37,99,235,.07); border-color: rgba(37,99,235,.25); }
.alert-ok { background: rgba(21,128,61,.08); border-color: rgba(21,128,61,.25); color: var(--ok); }
.alert-error { background: rgba(185,28,28,.08); border-color: rgba(185,28,28,.25); color: var(--err); }
.footnote { text-align: center; font-size: 12px; color: var(--muted); margin-top: 20px; }
@media (max-width: 560px) {
    .shell { padding: 28px 14px 24px; }
    .card { padding: 20px; }
}
</style>
</head>
<body>
<main class="shell">
    <header class="brand">
        <div class="mark">H</div>
        <div>
            <h1>HostVexa</h1>
            <p>$subtitle</p>
        </div>
    </header>
    <div class="card">
$body
    </div>
    <p class="footnote">This page belongs to the first-run setup. It stops being served as soon as the dependencies are installed.</p>
</main>
$script
</body>
</html>
HTML;
}

/**
 * @param  list<string>  $commands
 */
function setup_step_commands(array $commands): string
{
    return implode('</code> then <code>', array_map('setup_esc', $commands));
}

function setup_step_list(): string
{
    $items = '';

    foreach (SETUP_STEPS as $index => $step) {
        $items .= '<li data-step-index="'.$index.'" data-status="pending">'
            .'<span class="dot">'.($index + 1).'</span>'
            .'<span>'.setup_esc($step['label']).' — <code>'.setup_step_commands($step['commands']).'</code></span>'
            .'</li>';
    }

    return '<ol class="steps">'.$items.'</ol>';
}

/**
 * The manual instructions, printed on the fallback page and offered on the
 * progress page when a run fails.
 */
function setup_instructions(): string
{
    return <<<'HTML'
<p>From the application root, run:</p>
<pre class="command">composer install</pre>
<p>For the full install (.env, app key, migrations, npm build, storage:link):</p>
<pre class="command">composer run setup</pre>
<p>For the front-end assets:</p>
<pre class="command">npm ci &amp;&amp; npm run build</pre>
HTML;
}

/**
 * The page a machine that cannot install itself is left with: the same
 * commands, plus what is missing here.
 *
 * @param  list<string>  $missing
 */
function setup_instruction_page(string $subtitle, array $missing, ?string $heading = null): string
{
    $reasons = '';

    foreach ($missing as $reason) {
        $reasons .= '<li>'.setup_esc($reason).'</li>';
    }

    $body = '<h2>'.setup_esc($heading ?? 'Finish the installation by hand').'</h2>'
        .'<p>This copy of HostVexa has no dependencies installed, so it cannot start yet. '
        .'This machine cannot run the installer itself:</p>'
        .'<ul class="reasons">'.$reasons.'</ul>'
        .setup_instructions()
        .'<p class="note">Once the dependencies are in place, reload this page and the first-run setup wizard takes over.</p>';

    return setup_document('HostVexa - Install dependencies', $subtitle, $body);
}

/**
 * The control page: one button, an honest description of the three steps.
 */
function setup_control_page(array $probe): string
{
    $body = '<h2>Install the dependencies</h2>'
        .'<p>This copy of HostVexa has no dependencies installed yet, so it cannot start. '
        .'The button below installs them on this server — no terminal needed. '
        .'It runs the three steps below, in order, and takes several minutes.</p>'
        .setup_step_list()
        .'<a class="btn" href="?action=start">Install dependencies</a>'
        .'<p class="note">You can close this page while it runs and come back; the install continues in the background. '
        .'Prefer to do it yourself? From the application root run <code>composer install</code> '
        .'(or <code>composer run setup</code> for the full install, which also builds the front-end assets).</p>';

    return setup_document('HostVexa - Install dependencies', 'First-run setup', $body);
}

/**
 * The progress page: what the installer is doing right now, its log tail, and
 * the manual instructions as the fallback if a step fails.
 */
function setup_progress_page(string $notice, array $status): string
{
    $log = implode("\n", array_map('setup_esc', $status['log']));

    $noticeMarkup = $notice === ''
        ? ''
        : '<div class="alert alert-info">'.setup_esc($notice).'</div>';

    $body = $noticeMarkup
        .'<h2>Installing the dependencies</h2>'
        .'<p class="status-line" id="status-line">'.setup_esc($status['message']).'</p>'
        .setup_step_list()
        .'<pre class="log" id="log"'.($log === '' ? ' hidden' : '').'>'.$log.'</pre>'
        .'<div id="continue" hidden>'
        .'<div class="alert alert-ok">The dependencies are installed. The setup wizard is ready.</div>'
        .'<a class="btn" href="/">Continue to setup</a>'
        .'</div>'
        .'<div class="alert alert-error" id="fallback" hidden>'
        .'<strong>The install did not finish.</strong> The log above shows where it stopped. '
        .'You can finish the setup from a terminal instead — the steps are below, and nothing is lost by running them by hand.'
        .setup_instructions()
        .'</div>'
        .'<noscript><p class="note">JavaScript is switched off, so this page cannot follow the progress on its own. '
        .'Reload it every few seconds until the steps are done.</p></noscript>';

    $script = <<<'HTML'
<script>
(function () {
    'use strict';
    var timer = setInterval(poll, 3000);

    function node(id) {
        return document.getElementById(id);
    }

    function show(id, visible) {
        var element = node(id);
        if (element) {
            element.hidden = !visible;
        }
    }

    function paint(state) {
        var items = document.querySelectorAll('ol.steps [data-step-index]');
        for (var i = 0; i < items.length; i++) {
            var index = parseInt(items[i].getAttribute('data-step-index'), 10);
            if (index < state.step_index) {
                items[i].setAttribute('data-status', 'ok');
            } else if (index === state.step_index) {
                items[i].setAttribute('data-status', state.step_status);
            } else {
                items[i].setAttribute('data-status', 'pending');
            }
        }

        var status = node('status-line');
        if (status) {
            status.textContent = state.message;
        }

        var log = node('log');
        if (log) {
            log.textContent = state.log.join('\n');
            show('log', state.log.length > 0 && state.state !== 'idle');
        }

        if (state.state === 'done') {
            show('continue', true);
            show('fallback', false);
            setTimeout(function () {
                window.location.href = '/';
            }, 2500);

            return true;
        }

        if (state.state === 'failed') {
            show('continue', false);
            show('fallback', true);

            return true;
        }

        return false;
    }

    function poll() {
        fetch('?action=status', { cache: 'no-store' })
            .then(function (response) {
                return response.json();
            })
            .then(function (state) {
                if (paint(state)) {
                    clearInterval(timer);
                    timer = null;
                }
            })
            .catch(function () {
                var status = node('status-line');
                if (status) {
                    status.textContent = 'Waiting for the installer to report progress. Reload the page if this keeps going.';
                }
            });
    }

    poll();
})();
</script>
HTML;

    return setup_document('HostVexa - Install dependencies', 'First-run setup', $body, $script);
}

$paths = setup_paths();

// CLI mode is only ever the background worker this page starts; it is never
// exposed by a request.
if (PHP_SAPI === 'cli') {
    $arguments = is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [];

    if (($arguments[1] ?? '') !== 'run') {
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Nothing to do here. Load this page over HTTP to install the dependencies.'.PHP_EOL;

        exit(0);
    }

    // The app owns the request once its dependencies exist.
    if (is_file($paths['autoload'])) {
        exit(0);
    }

    // The web request that started this install has already ended, so the PID
    // it left in the lock is gone or reused. The runner is the process that
    // must stay alive, so it claims the lock with its own PID.
    setup_claim_lock($paths['lock']);

    exit(setup_run_install($paths));
}

// The real application owns the request from here on.
if (is_file($paths['autoload'])) {
    header('Location: /');
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', '0');

$action = isset($_GET['action']) && is_string($_GET['action']) ? $_GET['action'] : '';

if ($action === 'status') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(setup_status($paths)) ?: '{}';

    exit;
}

$probe = setup_machine_probe($paths);

if (! $probe['can']) {
    echo setup_instruction_page('First-run setup', $probe['missing']);
    exit;
}

if ($action === 'start') {
    $lock = setup_acquire_lock($paths);

    if (! $lock['acquired']) {
        echo setup_progress_page($lock['reason'], setup_status($paths));
        exit;
    }

    if (! setup_spawn_background($paths)) {
        setup_release_lock($paths['lock']);
        echo setup_instruction_page('First-run setup', ['The install could not be started on this server.']);
        exit;
    }

    echo setup_progress_page('', setup_status($paths));
    exit;
}

echo setup_control_page($probe);
