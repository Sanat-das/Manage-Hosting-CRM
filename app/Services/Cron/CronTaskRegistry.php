<?php

declare(strict_types=1);

namespace App\Services\Cron;

use App\Models\CronTask;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Throwable;

/**
 * The identity contract between a scheduled task in routes/console.php and
 * its stored operator state.
 *
 * The key must be identical in EVERY process that sees the task — the web
 * request rendering the admin page and the CLI process running
 * `schedule:run`. That rules out the obvious candidates:
 *
 *  - `$event->mutexName()` hashes `$event->command`, which embeds the PHP
 *    binary path. The web SAPI resolves php-cgi.exe while the CLI resolves
 *    php.exe, so the same task would hash differently in each.
 *  - `spl_object_hash()` (what an unnamed closure falls back to) is not
 *    stable across processes at all.
 *
 * So the key is the artisan command signature for command tasks
 * ("billing:recurring", "app:cleanup --days=90") and the explicit
 * `->name(...)` for closure tasks. A closure with no name has no stable
 * identity and is reported as unmanageable rather than given a key that
 * would silently rebind to a different task later.
 *
 * Consequence worth knowing: the arguments are PART of the key. Editing
 * `app:cleanup --days=90` to `--days=60` in console.php is a new key, and the
 * task reverts to the default (enabled). That is the safe direction to fail —
 * a renamed task runs rather than silently staying off.
 */
final class CronTaskRegistry
{
    /**
     * The stable key for a scheduled event, or null when the event cannot be
     * addressed (an unnamed closure).
     */
    public function keyFor(Event $event): ?string
    {
        if ($event instanceof CallbackEvent) {
            $name = trim((string) ($event->description ?? ''));

            return $name !== '' ? $name : null;
        }

        return $this->commandSignature((string) ($event->command ?? '')) ?: null;
    }

    /**
     * Strip the "{php binary} {artisan binary}" prefix that
     * Illuminate\Console\Application::formatCommandString() prepends, leaving
     * the artisan signature the developer actually wrote.
     */
    public function commandSignature(string $command): string
    {
        $command = trim($command);

        if ($command === '') {
            return '';
        }

        // Preferred path: the framework escapes the artisan binary the same
        // way in every SAPI ("artisan" on Windows, 'artisan' elsewhere).
        $marker = ConsoleApplication::artisanBinary().' ';

        if (str_contains($command, $marker)) {
            return trim(substr($command, strpos($command, $marker) + strlen($marker)));
        }

        // Fallback for an unquoted or differently escaped binary.
        if (preg_match('/artisan["\']?\s+(.+)$/s', $command, $matches) === 1) {
            return trim($matches[1]);
        }

        return $command;
    }

    /**
     * Enabled flags for every task that has a stored row, keyed by task key.
     * Tasks absent from the map are enabled.
     *
     * @return array<string, bool>
     */
    public function enabledMap(): array
    {
        try {
            return CronTask::query()
                ->pluck('enabled', 'key')
                ->map(static fn ($enabled): bool => (bool) $enabled)
                ->all();
        } catch (Throwable) {
            // No table yet (pre-install / mid-migration). Never let the
            // management layer stop the schedule from running.
            return [];
        }
    }

    /**
     * Default-on: only an explicit stored `false` disables a task.
     */
    public function isEnabled(string $key): bool
    {
        try {
            $stored = CronTask::query()->whereKey($key)->value('enabled');
        } catch (Throwable) {
            return true;
        }

        return $stored === null || (bool) $stored;
    }

    public function setEnabled(string $key, bool $enabled, ?int $userId = null): CronTask
    {
        return CronTask::query()->updateOrCreate(
            ['key' => $key],
            ['enabled' => $enabled, 'updated_by' => $userId],
        );
    }
}
