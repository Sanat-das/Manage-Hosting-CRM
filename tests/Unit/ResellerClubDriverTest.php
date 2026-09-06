<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\RegistrarException;
use App\Services\Registrars\ResellerClubDriver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResellerClubDriverTest extends TestCase
{
    private const CONFIGURED_SETTINGS = [
        'api_id' => '123456',
        'api_key' => 'test-secret-key',
        'username' => 'reseller-user',
        'api_endpoint' => 'https://http-api.resellerclub.com',
        'test_mode' => '0',
        'enabled' => '1',
    ];

    public function test_unconfigured_is_not_configured(): void
    {
        Http::preventStrayRequests();

        $driver = new ResellerClubDriver('resellerclub', [
            'api_id' => '',
            'api_key' => '',
            'username' => '',
        ]);

        $this->assertFalse($driver->isConfigured());
        $this->assertFalse($driver->isOnline());

        // Never fabricate availability when credentials are missing.
        $this->expectException(RegistrarException::class);
        $this->expectExceptionMessage('not configured');

        $driver->checkAvailability('example.com');
    }

    public function test_configured_driver_parses_availability(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*resellerclub.com*' => Http::response([
                'example.com' => ['status' => 'available', 'class' => 'available'],
            ]),
        ]);

        $driver = new ResellerClubDriver('resellerclub', self::CONFIGURED_SETTINGS);

        $this->assertTrue($driver->isConfigured());
        $this->assertTrue($driver->isOnline());

        $result = $driver->checkAvailability('example.com');

        $this->assertTrue($result['available']);
        $this->assertFalse($result['premium']);
        $this->assertNull($result['price']);
        $this->assertNull($result['message']);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'domains/available.json')
            && str_contains($request->url(), 'domain-name=example')
            && str_contains($request->url(), 'tld=com'));
    }

    public function test_registered_domain_returns_not_available(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*resellerclub.com*' => Http::response([
                'example.com' => ['status' => 'regthroughothers', 'class' => ''],
            ]),
        ]);

        $driver = new ResellerClubDriver('resellerclub', self::CONFIGURED_SETTINGS);

        $result = $driver->checkAvailability('example.com');

        $this->assertFalse($result['available']);
        $this->assertFalse($result['premium']);
        $this->assertNull($result['price']);
    }

    public function test_api_error_throws(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*resellerclub.com*' => Http::response(['error' => 'Invalid Credentials'], 401),
        ]);

        $driver = new ResellerClubDriver('resellerclub', self::CONFIGURED_SETTINGS);

        $this->expectException(RegistrarException::class);
        $this->expectExceptionMessage('Invalid Credentials');

        $driver->checkAvailability('example.com');
    }

    public function test_punycode_normalized(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*resellerclub.com*' => Http::response([
                'xn--mnchen-3ya.de' => ['status' => 'available', 'class' => 'available'],
            ]),
        ]);

        $driver = new ResellerClubDriver('resellerclub', self::CONFIGURED_SETTINGS);

        $result = $driver->checkAvailability('münchen.de');

        $this->assertTrue($result['available']);

        // The outgoing request must carry the ASCII (punycode) form of the
        // IDN label: münchen → xn--mnchen-3ya (full domain xn--mnchen-3ya.de).
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'xn--mnchen-3ya')
            && ! str_contains($request->url(), 'm%C3%BCnchen'));
    }

    public function test_get_pricing_returns_null_when_unavailable(): void
    {
        Http::preventStrayRequests();

        // Unconfigured driver: pricing is best-effort, never fabricated.
        $unconfigured = new ResellerClubDriver('resellerclub', []);
        $this->assertNull($unconfigured->getPricing('com'));

        // Configured driver but the API fails: still null, no exception.
        Http::fake([
            '*resellerclub.com*' => Http::response('Server Error', 500),
        ]);

        $driver = new ResellerClubDriver('resellerclub', self::CONFIGURED_SETTINGS);
        $this->assertNull($driver->getPricing('com'));
    }
}
