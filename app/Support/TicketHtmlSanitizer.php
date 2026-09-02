<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Sanitizes a ticket reply's HTML body before it is ever rendered in the
 * thread view.
 *
 * Inbound HTML comes from whoever emails support — trusting it directly would
 * let a crafted `<script>`, an `onerror` handler, or a `javascript:` link run
 * in the admin's session the moment a staff member opens the ticket. Outbound
 * HTML (Task 6's compose editor) is staff-authored, but is sanitized the same
 * way anyway: defense in depth costs nothing here, and it is one code path to
 * reason about instead of two.
 *
 * `allowSafeElements()` is the W3C Sanitizer API "safe" preset — strips
 * scripts and other dangerous behaviors (inline event handlers, style-based
 * injection) while keeping ordinary formatting. `data:` image sources are
 * allowed by the library's default media scheme list, which is what lets the
 * compose editor's inline (non-`cid:`) image embedding survive sanitization.
 */
final class TicketHtmlSanitizer
{
    private static ?HtmlSanitizer $sanitizer = null;

    public static function sanitize(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        return self::instance()->sanitize($html);
    }

    private static function instance(): HtmlSanitizer
    {
        if (self::$sanitizer === null) {
            $config = (new HtmlSanitizerConfig)
                ->allowSafeElements()
                ->allowLinkSchemes(['http', 'https', 'mailto'])
                ->allowRelativeLinks()
                ->allowMediaSchemes(['http', 'https', 'data']);

            self::$sanitizer = new HtmlSanitizer($config);
        }

        return self::$sanitizer;
    }
}
