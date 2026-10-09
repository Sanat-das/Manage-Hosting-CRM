<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Logging\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Captures the log correlation id (an inbound X-Request-Id when well-formed,
 * a fresh ULID otherwise) and echoes it back as a response header, so a
 * user-reported failure can be matched to its log lines.
 */
final class AssignRequestId
{
    public function __construct(private readonly RequestContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->capture($request);

        $response = $next($request);

        if ($this->context->requestId() !== null) {
            $response->headers->set('X-Request-Id', $this->context->requestId());
        }

        return $response;
    }
}
