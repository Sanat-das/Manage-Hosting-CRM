<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Renders a chat message body to the HTML that is broadcast and displayed.
 *
 * Chat bodies are stored as the plain text the author typed. They are never
 * injected into the DOM raw: everything is entity-escaped FIRST, and only then
 * are the handful of recognised markers turned back into tags. Doing it in that
 * order is what makes injection impossible — a body containing `<script>`
 * becomes text before any tag is introduced.
 *
 * The result is passed through TicketHtmlSanitizer as well. That is redundant
 * by construction, and kept deliberately: it means a future edit to the marker
 * handling cannot quietly become an XSS hole.
 *
 * Supported markers, chosen to match what people actually type in a chat box:
 *   ```fenced```  → <pre><code>
 *   `inline`      → <code>
 *   **bold**      → <strong>
 *   *italic*      → <em>
 *   newlines      → <br>
 *   bare URLs     → <a> with noopener
 */
final class ChatBodyHtml
{
    public static function render(?string $body): string
    {
        if ($body === null || trim($body) === '') {
            return '';
        }

        $escaped = htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Fenced blocks first, and their contents are parked while the inline
        // markers run so that a ** inside a code sample stays literal.
        $blocks = [];
        $escaped = preg_replace_callback(
            '/```(?:[a-z0-9_+-]*)\r?\n?(.*?)```/is',
            static function (array $m) use (&$blocks): string {
                $blocks[] = '<pre><code>'.rtrim($m[1]).'</code></pre>';

                return "\0BLOCK".(count($blocks) - 1)."\0";
            },
            $escaped,
        ) ?? $escaped;

        $inline = [];
        $escaped = preg_replace_callback(
            '/`([^`\r\n]+)`/',
            static function (array $m) use (&$inline): string {
                $inline[] = '<code>'.$m[1].'</code>';

                return "\0INLINE".(count($inline) - 1)."\0";
            },
            $escaped,
        ) ?? $escaped;

        $escaped = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', '<strong>$1</strong>', $escaped) ?? $escaped;
        $escaped = preg_replace('/(?<!\*)\*(?=\S)([^*\r\n]+)(?<=\S)\*(?!\*)/', '<em>$1</em>', $escaped) ?? $escaped;

        $escaped = preg_replace(
            '#(?<!["\'>])\b(https?://[^\s<]+[^\s<.,;:!?)\]])#i',
            '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>',
            $escaped,
        ) ?? $escaped;

        $escaped = nl2br($escaped, false);

        foreach ($inline as $index => $html) {
            $escaped = str_replace("\0INLINE{$index}\0", $html, $escaped);
        }

        foreach ($blocks as $index => $html) {
            $escaped = str_replace("\0BLOCK{$index}\0", $html, $escaped);
        }

        return (string) TicketHtmlSanitizer::sanitize($escaped);
    }
}
