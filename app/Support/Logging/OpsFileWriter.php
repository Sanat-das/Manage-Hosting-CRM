<?php

declare(strict_types=1);

namespace App\Support\Logging;

use App\Support\SecretRedactor;

/**
 * Appends to the raw operational logs (update.log, rollback.log) that must
 * survive even a half-broken boot — which is why they bypass Monolog and keep
 * writing straight to disk.
 *
 * Adds what the bare file_put_contents calls never had: SecretRedactor on
 * every entry and size-capped rotation (10 MB per file, three archives kept).
 * Failures stay silent by design: these writes must never break an update.
 *
 * Assumes the single-writer contract of the update/rollback paths (the updater
 * serializes runs with a cache lock): the size check and rotation are not
 * atomic across concurrent processes, so two simultaneous writers can overshoot
 * the cap or drop one archive generation.
 */
final class OpsFileWriter
{
    public const MAX_BYTES = 10_485_760;

    public const KEEP_ARCHIVES = 3;

    public static function append(string $path, string $entry): void
    {
        $entry = SecretRedactor::redact($entry);

        if (is_file($path) && (int) filesize($path) + strlen($entry) > self::MAX_BYTES) {
            self::rotate($path);
        }

        @file_put_contents($path, $entry, FILE_APPEND | LOCK_EX);
    }

    private static function rotate(string $path): void
    {
        @unlink($path.'.'.self::KEEP_ARCHIVES);

        for ($i = self::KEEP_ARCHIVES - 1; $i >= 1; $i--) {
            if (is_file($path.'.'.$i)) {
                @rename($path.'.'.$i, $path.'.'.($i + 1));
            }
        }

        @rename($path, $path.'.1');
    }
}
