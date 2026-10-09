<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Correlation data attached to every log record of an HTTP request.
 *
 * Captured once by the AssignRequestId middleware; read by
 * RequestContextProcessor at record time, so the authenticated user — which
 * resolves after middleware runs — is still included.
 */
final class RequestContext
{
    private ?Request $request = null;

    private ?string $requestId = null;

    public function capture(Request $request): void
    {
        $this->request = $request;
        $this->requestId = $this->resolveRequestId($request);
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        $context = [];

        if ($this->requestId !== null) {
            $context['request_id'] = $this->requestId;
        }

        if ($this->request !== null) {
            $context['route'] = $this->request->route()?->getName() ?? $this->request->path();

            $ip = $this->request->ip();

            if ($ip !== null) {
                $context['ip'] = $ip;
            }

            $userId = $this->request->user()?->getAuthIdentifier();

            if ($userId !== null) {
                $context['user_id'] = $userId;
            }
        }

        if (app()->runningInConsole()) {
            $context['cli'] = $_SERVER['argv'][1] ?? 'console';
        }

        return $context;
    }

    private function resolveRequestId(Request $request): string
    {
        $incoming = $request->header('X-Request-Id');

        // Forward only well-formed upstream ids — anything else could carry
        // control characters into a log line.
        if (is_string($incoming)
            && $incoming !== ''
            && strlen($incoming) <= 64
            && preg_match('/^[A-Za-z0-9._-]+$/', $incoming) === 1) {
            return $incoming;
        }

        return (string) Str::ulid();
    }
}
