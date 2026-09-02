<?php

declare(strict_types=1);

namespace App\Services\Registrars;

use App\Contracts\RegistrarDriver;
use App\Exceptions\RegistrarException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * ResellerClub registrar driver.
 *
 * Talks to the ResellerClub HTTP API over the Http facade (fakeable in
 * tests via Http::fake()). Credentials come from the injected settings
 * array (registrar_settings KV store): api_id, api_key, username,
 * api_endpoint, test_mode. Errors always surface as RegistrarException —
 * availability is never fabricated.
 */
class ResellerClubDriver implements RegistrarDriver
{
    public const DEFAULT_ENDPOINT = 'https://http-api.resellerclub.com';

    public const TEST_ENDPOINT = 'https://test.http-api.resellerclub.com';

    private const SUCCESS_STATUSES = ['success', 'successfully', 'completed', 'pending', 'active'];

    public function __construct(
        private readonly ?string $registrar = null,
        private readonly array $settings = [],
    ) {}

    public function isOnline(): bool
    {
        return $this->isConfigured();
    }

    public function isConfigured(): bool
    {
        return $this->setting('api_id') !== ''
            && $this->setting('api_key') !== ''
            && $this->setting('username') !== '';
    }

    public function checkAvailability(string $domain): array
    {
        $this->assertConfigured();

        $domain = $this->normalizeDomain($domain);
        [$name, $tld] = $this->splitDomain($domain);

        $data = $this->get('/domains/available.json', $this->authParams() + [
            'domain-name' => $name,
            'tld' => $tld,
        ]);

        $entry = $data[$domain] ?? null;

        if (! is_array($entry)) {
            throw new RegistrarException(sprintf(
                'ResellerClub availability response did not contain [%s].',
                $domain,
            ));
        }

        $status = strtolower(trim((string) ($entry['status'] ?? '')));
        $class = strtolower((string) ($entry['class'] ?? ''));

        $available = $status === 'available';

        return [
            'available' => $available,
            'premium' => str_contains($class, 'premium') || str_contains($class, 'auction'),
            'price' => isset($entry['price']) && is_numeric($entry['price']) ? (float) $entry['price'] : null,
            'currency' => isset($entry['currency']) && is_string($entry['currency']) ? $entry['currency'] : null,
            'message' => $available ? null : sprintf('Domain status reported by ResellerClub: %s', $status !== '' ? $status : 'unknown'),
        ];
    }

    public function register(array $payload): array
    {
        $this->assertConfigured();

        $domain = $this->normalizeDomain((string) ($payload['domain'] ?? ''));

        $params = $this->authParams() + [
            'domain-name' => $domain,
            'years' => max(1, (int) ($payload['years'] ?? 1)),
        ];

        foreach ((array) ($payload['nameservers'] ?? []) as $index => $nameserver) {
            if (is_string($nameserver) && $nameserver !== '') {
                $params['ns'.($index + 1)] = $nameserver;
            }
        }

        $data = $this->post('/domains/register.json', $params);

        $this->assertSuccess($data, 'Domain registration failed.');

        return [
            'status' => 'registered',
            'domain' => $domain,
            'order_id' => $this->firstString($data, ['orderid', 'order_id', 'entityid', 'entity_id']),
            'expires_at' => $this->firstString($data, ['expirydate', 'expires_at', 'endtime']),
            'message' => $this->firstString($data, ['description', 'message']),
        ];
    }

    public function renew(array $payload): array
    {
        $this->assertConfigured();

        $domain = $this->normalizeDomain((string) ($payload['domain'] ?? ''));

        $data = $this->post('/domains/renew.json', $this->authParams() + [
            'domain-name' => $domain,
            'years' => max(1, (int) ($payload['years'] ?? 1)),
        ]);

        $this->assertSuccess($data, 'Domain renewal failed.');

        return [
            'status' => 'renewed',
            'domain' => $domain,
            'order_id' => $this->firstString($data, ['orderid', 'order_id', 'entityid', 'entity_id']),
            'expires_at' => $this->firstString($data, ['expirydate', 'expires_at', 'endtime']),
            'message' => $this->firstString($data, ['description', 'message']),
        ];
    }

    public function transfer(array $payload): array
    {
        $this->assertConfigured();

        $domain = $this->normalizeDomain((string) ($payload['domain'] ?? ''));
        $authCode = (string) ($payload['auth_code'] ?? '');

        if ($authCode === '') {
            throw new RegistrarException('Domain transfer requires an auth_code.');
        }

        $data = $this->post('/domains/transfer.json', $this->authParams() + [
            'domain-name' => $domain,
            'auth-code' => $authCode,
        ]);

        $this->assertSuccess($data, 'Domain transfer failed.');

        return [
            'status' => 'transferred',
            'domain' => $domain,
            'transfer_id' => $this->firstString($data, ['transferid', 'transfer_id', 'orderid', 'entityid']),
            'message' => $this->firstString($data, ['description', 'message']),
        ];
    }

    public function getPricing(string $tld): ?array
    {
        $tld = strtolower(trim($tld));

        // Pricing is best-effort: never fabricated, null when unavailable.
        if ($tld === '' || ! $this->isConfigured()) {
            return null;
        }

        try {
            $data = $this->get('/domains/pricing.json', $this->authParams() + ['tld' => $tld]);
        } catch (RegistrarException) {
            return null;
        }

        $row = $data[$tld] ?? $data;

        if (! is_array($row)) {
            return null;
        }

        $register = $row['register_price'] ?? $row['register'] ?? null;
        $renew = $row['renew_price'] ?? $row['renew'] ?? null;
        $transfer = $row['transfer_price'] ?? $row['transfer'] ?? null;

        if (! is_numeric($register) || ! is_numeric($renew) || ! is_numeric($transfer)) {
            return null;
        }

        return [
            'tld' => $tld,
            'register' => (float) $register,
            'renew' => (float) $renew,
            'transfer' => (float) $transfer,
            'currency' => isset($row['currency']) && is_string($row['currency']) ? $row['currency'] : 'INR',
        ];
    }

    /**
     * Base API URL: test endpoint in test_mode, configured endpoint
     * otherwise, falling back to the production default.
     */
    private function endpoint(): string
    {
        if ($this->setting('test_mode') === '1') {
            return self::TEST_ENDPOINT;
        }

        $endpoint = $this->setting('api_endpoint');

        return $endpoint !== '' ? rtrim($endpoint, '/') : self::DEFAULT_ENDPOINT;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function get(string $path, array $params): array
    {
        try {
            $response = Http::timeout(15)->get($this->endpoint().$path, $params);
        } catch (ConnectionException $exception) {
            throw new RegistrarException('ResellerClub connection failed: '.$exception->getMessage(), 0, $exception);
        }

        return $this->decodeResponse($response);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function post(string $path, array $params): array
    {
        try {
            $response = Http::asForm()->timeout(30)->post($this->endpoint().$path, $params);
        } catch (ConnectionException $exception) {
            throw new RegistrarException('ResellerClub connection failed: '.$exception->getMessage(), 0, $exception);
        }

        return $this->decodeResponse($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(Response $response): array
    {
        $data = $response->json();

        if (! is_array($data)) {
            throw new RegistrarException(sprintf(
                'ResellerClub returned an unexpected response (HTTP %d).',
                $response->status(),
            ));
        }

        $errorStatus = strtolower((string) ($data['status'] ?? '')) === 'error';

        if ($response->failed() || isset($data['error']) || $errorStatus) {
            throw new RegistrarException('ResellerClub API error: '.$this->apiErrorMessage($data, $response));
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function apiErrorMessage(array $data, Response $response): string
    {
        foreach (['error', 'message', 'description'] as $key) {
            $value = $data[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }

            if (is_array($value)) {
                return (string) json_encode($value);
            }
        }

        return sprintf('HTTP %d', $response->status());
    }

    /**
     * Lowercase and transcode IDN input to its ASCII (punycode) form.
     */
    private function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));

        if ($domain === '' || ! str_contains($domain, '.')) {
            throw new RegistrarException(sprintf('Invalid domain [%s].', $domain));
        }

        if (preg_match('/[^\x00-\x7F]/', $domain) !== 1) {
            return $domain;
        }

        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            if (is_string($ascii) && $ascii !== '') {
                return strtolower($ascii);
            }
        }

        throw new RegistrarException(sprintf('Unable to normalize internationalized domain [%s].', $domain));
    }

    /**
     * Split a normalized domain into name (SLD) and TLD parts.
     *
     * @return array{0: string, 1: string}
     */
    private function splitDomain(string $domain): array
    {
        [$name, $tld] = explode('.', $domain, 2);

        if ($name === '' || $tld === '') {
            throw new RegistrarException(sprintf('Invalid domain [%s].', $domain));
        }

        return [$name, $tld];
    }

    /**
     * @return array<string, string>
     */
    private function authParams(): array
    {
        return [
            'auth-userid' => $this->setting('api_id'),
            'api-key' => $this->setting('api_key'),
            'username' => $this->setting('username'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertSuccess(array $data, string $fallback): void
    {
        $status = strtolower(trim((string) ($data['status'] ?? '')));

        if ($status !== '') {
            if (! in_array($status, self::SUCCESS_STATUSES, true)) {
                $detail = $this->firstString($data, ['message', 'description']);

                throw new RegistrarException('ResellerClub: '.($detail ?? $fallback));
            }

            return;
        }

        if ($this->firstString($data, ['orderid', 'entityid', 'transferid']) === null) {
            throw new RegistrarException('ResellerClub: '.$fallback);
        }
    }

    /**
     * First non-empty string value among the given keys.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     */
    private function firstString(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;

            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    private function setting(string $key): string
    {
        $value = $this->settings[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RegistrarException(sprintf(
                'Registrar [%s] is not configured. Set api_id, api_key and username.',
                $this->registrar ?? 'resellerclub',
            ));
        }
    }
}
