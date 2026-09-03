<?php

namespace App\Http\Middleware;

use App\Support\AppSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attach OWASP-recommended security headers to every web response.
 *
 * Intended to be appended to the "web" middleware group in bootstrap/app.php
 * so that all browser-facing responses carry baseline hardening without
 * requiring per-route opt-in.
 *
 * CSP is intentionally permissive for AdminLTE: script/style allow
 * 'unsafe-inline' (AdminLTE inlines) and cdn.jsdelivr.net (Bootstrap Icons
 * and supporting CDN assets). Tighten as the frontend is refactored to
 * eliminate inline handlers/styles.
 */
class SecurityHeaders
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Gated by security_headers_enabled toggle (default: enabled).
        if (! AppSettings::bool('security_headers_enabled', true)) {
            return $next($request);
        }

        /** @var Response $response */
        $response = $next($request);

        // Remove headers that disclose server / stack information.
        $response->headers->remove('Server');
        $response->headers->remove('X-Powered-By');

        // OWASP baseline headers.
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN', false);
        $response->headers->set('X-Content-Type-Options', 'nosniff', false);
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);
        $response->headers->set('Permissions-Policy', 'geolocation=()', false);

        // Modern browsers ignore X-XSS-Protection; set to 0 to explicitly
        // disable the legacy reflected-XSS filter that can introduce side-channels.
        $response->headers->set('X-XSS-Protection', '0', false);

        // Content Security Policy — minimal and production-safe.
        // - 'self' + 'unsafe-inline' required for AdminLTE inline scripts/styles.
        // - cdn.jsdelivr.net allowed for Bootstrap Icons and related CDN assets.
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net",
            "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net",
            "font-src 'self' https://cdn.jsdelivr.net data:",
            "img-src 'self' data: https:",
            "connect-src 'self'",
            "frame-ancestors 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);

        $response->headers->set('Content-Security-Policy', $csp, false);

        // HSTS — only over HTTPS, otherwise browsers would cache a broken policy
        // for http:// origins. Value aligns with OWASP recommendation.
        if ($request->isSecure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains',
                false
            );
        }

        return $response;
    }
}
