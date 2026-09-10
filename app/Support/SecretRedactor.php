<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Strips credentials out of text that is about to be stored or displayed.
 *
 * Git puts the remote URL — userinfo and all — into its own error output:
 * `fatal: unable to access 'https://user:ghp_xxx@github.com/o/r.git/': ...`.
 * The updater writes command output verbatim to storage/logs/update.log and
 * into activity_log.metadata, so a remote configured with an embedded token
 * ended up in both stores in the clear. Sanitising only the display field, as
 * UpdateService::sanitizeRemote() did, never covered that.
 *
 * Deliberately one class rather than a copy of the patterns in each service:
 * a security filter that exists twice drifts.
 */
final class SecretRedactor
{
    public const PLACEHOLDER = '***';

    /**
     * Ordered pattern list. Kept narrow on purpose — over-eager redaction of
     * command output makes real failures undiagnosable.
     */
    private const PATTERNS = [
        // URL userinfo: https://token@host, https://user:token@host, ssh://user@host.
        '#\b(https?|ssh|git)://[^/\s:@]+(?::[^/\s@]*)?@#i',
        // GitHub personal access, OAuth, server, user and refresh tokens.
        '#\bgh[pousr]_[A-Za-z0-9]{16,}#',
        // GitHub fine-grained personal access tokens.
        '#\bgithub_pat_[A-Za-z0-9_]{22,}#',
    ];

    private const REPLACEMENTS = [
        '$1://'.self::PLACEHOLDER.'@',
        self::PLACEHOLDER,
        self::PLACEHOLDER,
    ];

    public static function redact(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $result = preg_replace(self::PATTERNS, self::REPLACEMENTS, $text);

        // preg_replace returns null on failure (e.g. backtrack limit on a huge
        // blob). Losing the output entirely would be worse than not redacting,
        // so fall back to a hard scrub of anything resembling userinfo.
        if (! is_string($result)) {
            return str_contains($text, '@') ? self::PLACEHOLDER : $text;
        }

        return $result;
    }
}
