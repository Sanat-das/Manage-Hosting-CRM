<?php

declare(strict_types=1);

namespace App\Contracts;

interface RegistrarDriver
{
    /** Whether this registrar connection talks to a live remote API. */
    public function isOnline(): bool;

    /** Whether required credentials are present (false disables the registrar). */
    public function isConfigured(): bool;

    /**
     * Check whether a domain is available for registration.
     *
     * @return array{available: bool, premium: bool, price: ?float, currency: ?string, message: ?string}
     */
    public function checkAvailability(string $domain): array;

    /**
     * Register a domain.
     *
     * @param  array{domain: string, years: int, contacts?: array, nameservers?: array, protect_privacy?: bool}  $payload
     * @return array{status: string, domain: string, order_id: ?string, expires_at: ?string, message: ?string}
     */
    public function register(array $payload): array;

    /**
     * Renew a domain registration.
     *
     * @param  array{domain: string, years: int}  $payload
     * @return array{status: string, domain: string, order_id: ?string, expires_at: ?string, message: ?string}
     */
    public function renew(array $payload): array;

    /**
     * Transfer a domain into this registrar.
     *
     * @param  array{domain: string, auth_code: string, contacts?: array, nameservers?: array}  $payload
     * @return array{status: string, domain: string, transfer_id: ?string, message: ?string}
     */
    public function transfer(array $payload): array;

    /**
     * Pricing for a TLD, or null when the TLD is not offered.
     *
     * @return array{tld: string, register: float, renew: float, transfer: float, currency: string}|null
     */
    public function getPricing(string $tld): ?array;
}
