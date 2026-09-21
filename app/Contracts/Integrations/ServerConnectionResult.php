<?php

declare(strict_types=1);

namespace App\Contracts\Integrations;

/**
 * Outcome of a server connectivity probe.
 *
 * Immutable value object returned by TestableServerModule::testConnection().
 * `ok` decides the connection_status badge; `message` is shown on failure
 * (and persisted to servers.connection_error); `latencyMs` feeds the
 * "last checked" display; `meta` is persisted to servers.connection_meta.
 */
final readonly class ServerConnectionResult
{
    public function __construct(
        public bool $ok,
        public string $message = '',
        public int $latencyMs = 0,
        public array $meta = [],
    ) {}

    public static function ok(string $message = '', int $latencyMs = 0, array $meta = []): self
    {
        return new self(ok: true, message: $message, latencyMs: $latencyMs, meta: $meta);
    }

    public static function fail(string $message, int $latencyMs = 0, array $meta = []): self
    {
        return new self(ok: false, message: $message, latencyMs: $latencyMs, meta: $meta);
    }

    /**
     * @return array{ok: bool, message: string, latencyMs: int, meta: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'message' => $this->message,
            'latencyMs' => $this->latencyMs,
            'meta' => $this->meta,
        ];
    }
}
