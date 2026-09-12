<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Renders a chat message body to the HTML that is broadcast and displayed.
 *
 * Chat bodies are stored as the plain text the author typed. They are never
 * injected into the DOM raw: everything is entity-escaped FIRST, and only then
 * are the handful of recognised markers turned back into tags. Doing it in that
 * order is what makes injection impossible — a body containing `<script>`
 * becomes text before any tag is introduced.
 *
 * The result is then passed through `sanitize()`. That is redundant by
 * construction, and kept deliberately: it means a future edit to the marker
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
    /**
     * Comfortably above any body the renderer can produce.
     *
     * The sanitizer TRUNCATES rather than rejects past its input ceiling, and
     * its 20,000-character default is reachable from a legitimate message:
     * 4,000 characters of `**a**` expand to roughly 34,000 characters of
     * <strong> markup. A truncated message is a silently corrupted one.
     */
    private const MAX_INPUT_LENGTH = 200_000;

    private static ?HtmlSanitizer $sanitizer = null;

    /**
     * The chat's deliberate allow-list.
     *
     * Not TicketHtmlSanitizer's `allowSafeElements()` preset: that is the W3C
     * "safe" set — images, tables, headings, lists — which is right for inbound
     * support email, and far wider than anything this renderer can emit. The
     * list below is exactly the renderer's own vocabulary, so a tag that shows
     * up here is by definition a tag something introduced by mistake, and it is
     * removed.
     *
     * `<a>` keeps only href/target/rel, restricted to http/https/mailto, and
     * rel is forced: a `javascript:` or `data:text/html` href loses its
     * attribute entirely rather than becoming a click-to-execute link.
     */
    public static function sanitize(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        return trim(self::sanitizer()->sanitize($html));
    }

    private static function sanitizer(): HtmlSanitizer
    {
        if (self::$sanitizer === null) {
            $config = (new HtmlSanitizerConfig)
                ->allowElement('strong')
                ->allowElement('em')
                ->allowElement('code')
                ->allowElement('pre')
                ->allowElement('br')
                ->allowElement('a', ['href', 'target', 'rel'])
                ->allowLinkSchemes(['http', 'https', 'mailto'])
                ->forceAttribute('a', 'rel', 'noopener noreferrer')
                ->forceAttribute('a', 'target', '_blank')
                // Dropped, not blocked: a blocked element keeps its text
                // children, which would leave the *contents* of a <script> or
                // <style> sitting in the message as visible text.
                ->dropElement('script')
                ->dropElement('style')
                ->dropElement('iframe')
                ->dropElement('object')
                ->dropElement('embed')
                ->dropElement('form')
                ->dropElement('svg')
                ->dropElement('math')
                ->withMaxInputLength(self::MAX_INPUT_LENGTH);

            self::$sanitizer = new HtmlSanitizer($config);
        }

        return self::$sanitizer;
    }

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

        return self::sanitize($escaped);
    }
}
