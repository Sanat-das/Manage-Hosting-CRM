<?php

declare(strict_types=1);

namespace App\Services\Search;

/**
 * The one place a search term becomes a LIKE pattern.
 *
 * `%` and `_` are LIKE metacharacters: left unescaped, `q=%` stops being a
 * search and becomes "return every row", and `q=h_llo` quietly matches
 * "hello". The escape character is `!` rather than a backslash because MySQL's
 * default LIKE escape IS a backslash while SQLite has none, so `ESCAPE '\'`
 * cannot be written as one string literal that means the same thing in both
 * dialects — the same rationale as ChatController::LIKE_ESCAPE.
 */
final class LikePattern
{
    /** The ESCAPE character every provider's LIKE clause must name. */
    public const ESCAPE = '!';

    /**
     * Wrap the term for a "contains" match, escaping the escape character
     * itself first so a literal `!` still matches.
     */
    public static function contains(string $term): string
    {
        return '%'.str_replace(
            [self::ESCAPE, '%', '_'],
            [self::ESCAPE.self::ESCAPE, self::ESCAPE.'%', self::ESCAPE.'_'],
            $term,
        ).'%';
    }
}
