<?php

declare(strict_types=1);

namespace App\Contracts\Module;

/**
 * Outcome of a provisioning operation (provision/suspend/unsuspend/terminate).
 *
 * Immutable value object. Modules return `ok()` on success — optionally with
 * a message and provider data (e.g. remote identifiers) — or `fail()` with a
 * human-readable reason. The manager inspects `success` to decide whether to
 * advance the service instance state machine or record the error.
 */
final class ProvisioningResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $message = null,
        public readonly array $data = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $data  provider-side data to persist (e.g. remote ids)
     */
    public static function ok(string $message = '', array $data = []): self
    {
        return new self(success: true, message: $message === '' ? null : $message, data: $data);
    }

    public static function fail(string $message): self
    {
        return new self(success: false, message: $message);
    }
}
