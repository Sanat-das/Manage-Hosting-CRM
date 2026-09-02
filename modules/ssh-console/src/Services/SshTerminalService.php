<?php

declare(strict_types=1);

namespace Modules\SshConsole\Services;

use App\Models\HostingAccount;
use Illuminate\Support\Facades\Cache;
use Modules\SshConsole\Exceptions\SshException;
use phpseclib3\Crypt\RSA;
use phpseclib3\Net\SSH2;

/**
 * Web SSH terminal backend for the ssh-console module, built on the pure-PHP
 * phpseclib3 client (no ssh2 extension required).
 *
 * Process model: PHP-FPM workers are stateless, so an open SSH connection
 * cannot be shared between HTTP requests. The lifecycle therefore lives
 * inside ONE long-running streamed response:
 *
 *   1. POST /open   — creates only the audit row and returns a token.
 *   2. POST /input  — any worker appends keystrokes to a cache queue.
 *   3. GET  /stream — THIS request connects, logs in, holds the interactive
 *                     shell, drains the input queue (~10 Hz), and emits
 *                     newline-delimited JSON frames until closed/idle/expired.
 *   4. POST /close  — queues a close control message; the streamer finalizes
 *                     the audit row in its `finally` block.
 *
 * Frames yielded by streamLoop(): ['o' => base64-output], ['e' => message],
 * ['h' => 1] heartbeat. Keystrokes and output are never persisted or logged.
 */
class SshTerminalService
{
    private const CACHE_PREFIX_INPUT = 'ssh-console.in.';

    private const CACHE_PREFIX_CONTROL = 'ssh-console.ctrl.';

    private const CACHE_PREFIX_ACTIVITY = 'ssh-console.act.';

    /** Cache TTL of the per-session queues; also bounds stale token reuse. */
    private const QUEUE_TTL_SECONDS = 3600;

    /** Hard cap for one streamed terminal session. */
    public const MAX_LIFETIME_SECONDS = 1800;

    /** Close the session after this many seconds without input AND output. */
    public const IDLE_TIMEOUT_SECONDS = 600;

    /** Socket read timeout per tick — 50ms keeps htop's 1s auto-refresh
     *  from clobbering a keystroke that arrived 90ms ago. */
    private const READ_TIMEOUT_SECONDS = 0.05;

    /** Emit a heartbeat frame after this many seconds of silence. */
    private const HEARTBEAT_SECONDS = 15;

    public const DEFAULT_COLUMNS = 80;

    public const DEFAULT_ROWS = 24;

    /**
     * Effective SSH host for an account: explicit config host first, then the
     * assigned public IP, then any assigned IP. Null when nothing is usable.
     */
    public function resolveHost(HostingAccount $account, ?string $configHost): ?string
    {
        $host = trim((string) $configHost);

        if ($host !== '') {
            return $host;
        }

        $account->loadMissing('ipAddresses.subnet');

        $fallback = $account->ipAddresses->first(function ($ip) {
            return $ip->subnet !== null && $ip->subnet->network_type === 'public';
        }) ?? $account->ipAddresses->firstWhere('type', 'public')
            ?? $account->ipAddresses->first();

        $ip = trim((string) ($fallback?->ip_address ?? ''));

        return $ip !== '' ? $ip : null;
    }

    /**
     * Connect, authenticate (password first, else encrypted private key with
     * optional passphrase) and enable an xterm PTY sized to the initial
     * window. The shell itself is opened lazily by phpseclib on first read.
     *
     * @param  array{username: ?string, password: ?string, private_key: ?string, passphrase: ?string}  $auth
     *
     * @throws SshException on connect, auth, key-parse or setup failure
     */
    public function connect(string $host, int $port, array $auth, int $columns = self::DEFAULT_COLUMNS, int $rows = self::DEFAULT_ROWS): SSH2
    {
        $username = trim((string) ($auth['username'] ?? ''));

        if ($username === '') {
            throw new SshException('No SSH username configured for this service.');
        }

        try {
            $ssh = new SSH2($host, $port);
        } catch (\Throwable $e) {
            throw new SshException('Could not reach '.$host.':'.$port.' — '.trim(str_replace(["\r", "\n"], ' ', $e->getMessage())), 0, $e);
        }

        try {
            // Must be enabled before login(): it is sent with the PTY request
            // when the shell channel opens.  xterm-256color lets htop/vim/etc.
            // use full colour — phpseclib's default is vt100 which limits them.
            $ssh->setTerminal('xterm-256color');
            $ssh->enablePTY();

            $password = trim((string) ($auth['password'] ?? ''));
            $privateKey = trim((string) ($auth['private_key'] ?? ''));
            $passphrase = trim((string) ($auth['passphrase'] ?? ''));

            if ($password !== '') {
                $loggedIn = $ssh->login($username, $password);
            } elseif ($privateKey !== '') {
                try {
                    $key = RSA::loadPrivateKey($privateKey, $passphrase !== '' ? $passphrase : false);
                } catch (\Throwable $e) {
                    throw new SshException('Invalid private key — '.trim(str_replace(["\r", "\n"], ' ', $e->getMessage())), 0, $e);
                }

                $loggedIn = $ssh->login($username, $key);
            } else {
                throw new SshException('No SSH password or private key configured for this service.');
            }

            if (! $loggedIn) {
                throw new SshException('SSH authentication failed for "'.$username.'" on '.$host.':'.$port.'.');
            }
        } catch (SshException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new SshException('SSH login failed — '.trim(str_replace(["\r", "\n"], ' ', $e->getMessage())), 0, $e);
        }

        // Short per-read timeout so streamLoop() ticks at READ_TIMEOUT_SECONDS
        // cadence. Without this phpseclib blocks for its 10 s default and then
        // surfaces the expiry as read() === true — indistinguishable from a
        // channel close, which streamLoop() would treat as end-of-session.
        $ssh->setTimeout(self::READ_TIMEOUT_SECONDS);
        $ssh->setWindowSize($columns, $rows);

        return $ssh;
    }

    /**
     * Append keystrokes to the session's input queue (called from any worker).
     * Uses a 3s lock to avoid the classic get+put race when htop refreshes
     * and the user types at the same time — without the lock the second
     * writer overwrites the first's buffer.
     */
    public function pushInput(string $token, string $data): void
    {
        if ($data === '') {
            return;
        }

        $key = self::CACHE_PREFIX_INPUT.$token;
        $lockKey = $key.':lock';

        $lock = Cache::lock($lockKey, 1);

        try {
            $lock->block(1, function () use ($key, $data): void {
                $buffer = (string) Cache::get($key, '');
                $buffer .= $data;
                Cache::put($key, $buffer, now()->addSeconds(self::QUEUE_TTL_SECONDS));
            });
        } catch (\Throwable) {
            // Fallback: best-effort append if lock cannot be acquired (e.g. file cache no lock).
            $buffer = (string) Cache::get($key, '');
            $buffer .= $data;
            Cache::put($key, $buffer, now()->addSeconds(self::QUEUE_TTL_SECONDS));
        }

        $this->touchActivity($token);
    }

    /**
     * Queue a control message ('resize' | 'close') for the streaming worker.
     *
     * @param  array{type: string, cols?: int, rows?: int}  $message
     */
    public function pushControl(string $token, array $message): void
    {
        $key = self::CACHE_PREFIX_CONTROL.$token;

        $queue = Cache::get($key);
        $queue = is_array($queue) ? $queue : [];
        $queue[] = $message;

        Cache::put($key, $queue, now()->addSeconds(self::QUEUE_TTL_SECONDS));
        $this->touchActivity($token);
    }

    /**
     * Queue a close request (idempotent; safe to call more than once).
     */
    public function signalClose(string $token): void
    {
        $this->pushControl($token, ['type' => 'close']);
    }

    /**
     * The streamed terminal loop. Drains queued input into the shell, yields
     * output frames, honours resize/close controls, and terminates on idle
     * timeout, lifetime cap, server disconnect or queued close.
     *
     * @return \Generator<int, array{o?: string, e?: string, h?: int}>
     */
    public function streamLoop(SSH2 $ssh, string $token): \Generator
    {
        $startedAt = microtime(true);
        $deadline = $startedAt + self::MAX_LIFETIME_SECONDS;
        $lastOutputAt = microtime(true);
        $lastHeartbeatAt = microtime(true);

        while (microtime(true) < $deadline) {
            // --- Controls & input (queued by other workers via cache) ---
            foreach ($this->drainControl($token) as $control) {
                if (($control['type'] ?? '') === 'close') {
                    return;
                }

                if (($control['type'] ?? '') === 'resize'
                    && isset($control['cols'], $control['rows'])
                    && is_numeric($control['cols']) && is_numeric($control['rows'])) {
                    $ssh->setWindowSize((int) $control['cols'], (int) $control['rows']);
                }
            }

            $input = $this->drainInput($token);

            if ($input !== '') {
                try {
                    $ssh->write($input);
                } catch (\Throwable $e) {
                    yield ['e' => 'Connection lost while writing to the server.'];

                    return;
                }
            }

            // --- Output ---
            $chunk = false;

            $readException = false;
            try {
                $chunk = $ssh->read('', SSH2::READ_NEXT);
            } catch (\Throwable $e) {
                // phpseclib can throw "Undefined array key 2" on the first
                // read tick when the SSH handshake races READ_NEXT, or on
                // transient channel-buffer states. If the connection is still
                // live, skip this tick and continue.
                if ($ssh->isConnected()) {
                    $readException = true;
                } else {
                    yield ['e' => 'Connection lost.'];

                    return;
                }
            }
            if ($readException) {
                continue;
            }

            if (is_string($chunk) && $chunk !== '') {
                $lastOutputAt = microtime(true);
                $lastHeartbeatAt = $lastOutputAt;

                yield ['o' => base64_encode($chunk)];
            } elseif ($chunk === true) {
                // phpseclib returns true BOTH for a genuine channel EOF/close
                // AND for an expired read timeout (is_timeout). Only the
                // latter is survivable — with the short read timeout it is
                // the normal "no data this tick" case.
                if ($ssh->isTimeout()) {
                    // Idle tick: fall through to the idle guard + heartbeat.
                } else {
                    yield ['e' => 'Session closed by remote host.'];

                    return;
                }
            } else {
                // false → hard transport failure.
                yield ['e' => 'Connection lost.'];

                return;
            }

            // --- Idle guard ---
            $inputActivityAt = $this->activityAt($token);
            $lastActiveAt = max($lastOutputAt, $inputActivityAt ?? 0.0);

            if ((microtime(true) - $lastActiveAt) > self::IDLE_TIMEOUT_SECONDS) {
                yield ['e' => 'Session timed out due to inactivity.'];

                return;
            }

            // --- Heartbeat ---
            if ((microtime(true) - $lastHeartbeatAt) >= self::HEARTBEAT_SECONDS) {
                $lastHeartbeatAt = microtime(true);

                yield ['h' => 1];
            }
        }

        yield ['e' => 'Session reached its maximum duration.'];
    }

    /**
     * Remove and return all queued input for the token.
     * Also uses the same lock as pushInput so a concurrent push does not
     * interleave with the pull and lose bytes during htop's 1s refresh.
     */
    private function drainInput(string $token): string
    {
        $key = self::CACHE_PREFIX_INPUT.$token;
        $lockKey = $key.':lock';

        $lock = Cache::lock($lockKey, 1);

        try {
            return $lock->block(1, function () use ($key): string {
                $buffer = Cache::pull($key, '');

                return is_string($buffer) ? $buffer : '';
            });
        } catch (\Throwable) {
            $buffer = Cache::pull($key, '');

            return is_string($buffer) ? $buffer : '';
        }
    }

    /**
     * Remove and return all queued control messages for the token.
     *
     * @return list<array<string, mixed>>
     */
    private function drainControl(string $token): array
    {
        $key = self::CACHE_PREFIX_CONTROL.$token;

        $queue = Cache::pull($key, []);

        return is_array($queue) ? array_values($queue) : [];
    }

    private function touchActivity(string $token): void
    {
        Cache::put(
            self::CACHE_PREFIX_ACTIVITY.$token,
            microtime(true),
            now()->addSeconds(self::QUEUE_TTL_SECONDS)
        );
    }

    /**
     * Timestamp of the most recent input push, or null when unknown/expired.
     */
    private function activityAt(string $token): ?float
    {
        $value = Cache::get(self::CACHE_PREFIX_ACTIVITY.$token);

        return is_float($value) || is_int($value) ? (float) $value : null;
    }
}
