<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\RegistrarDriver;
use App\Models\RegistrarSetting;
use App\Services\Registrars\RegistrarManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class RegistrarManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_for_resolves_configured_driver(): void
    {
        RegistrarSetting::setMany('resellerclub', [
            'driver' => StubRegistrarDriver::class,
            'api_key' => 'test-key',
            'enabled' => '1',
        ]);

        $manager = app(RegistrarManager::class);
        $driver = $manager->driverFor('resellerclub');

        $this->assertInstanceOf(RegistrarDriver::class, $driver);
        $this->assertInstanceOf(StubRegistrarDriver::class, $driver);
        $this->assertTrue($driver->isConfigured());
        $this->assertTrue($driver->isOnline());
    }

    public function test_unknown_driver_throws(): void
    {
        RegistrarSetting::setMany('broken', [
            'driver' => 'App\\Services\\Registrars\\DoesNotExistDriver',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown registrar driver');

        app(RegistrarManager::class)->driverFor('broken');
    }

    public function test_non_driver_class_throws(): void
    {
        RegistrarSetting::setMany('notdriver', [
            'driver' => RegistrarManager::class,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement');

        app(RegistrarManager::class)->driverFor('notdriver');
    }

    public function test_find_by_code_returns_null_for_unknown(): void
    {
        $this->assertNull(app(RegistrarManager::class)->findByCode('nonexistent'));
    }

    public function test_provider_singleton_resolves_same_instance(): void
    {
        $first = app(RegistrarManager::class);
        $second = app(RegistrarManager::class);

        $this->assertSame($first, $second);
    }
}

/**
 * Test stub that implements RegistrarDriver.
 * Lives in the test file so class_exists() finds it
 * when driverFor() resolves via app().
 */
class StubRegistrarDriver implements RegistrarDriver
{
    public function __construct(
        private readonly ?string $registrar = null,
        private readonly array $settings = [],
    ) {}

    public function isOnline(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        return ($this->settings['api_key'] ?? null) !== null;
    }

    public function checkAvailability(string $domain): array
    {
        return [
            'available' => true,
            'premium' => false,
            'price' => null,
            'currency' => null,
            'message' => null,
        ];
    }

    public function register(array $payload): array
    {
        return [
            'status' => 'registered',
            'domain' => $payload['domain'],
            'order_id' => null,
            'expires_at' => null,
            'message' => null,
        ];
    }

    public function renew(array $payload): array
    {
        return [
            'status' => 'renewed',
            'domain' => $payload['domain'],
            'order_id' => null,
            'expires_at' => null,
            'message' => null,
        ];
    }

    public function transfer(array $payload): array
    {
        return [
            'status' => 'transferred',
            'domain' => $payload['domain'],
            'transfer_id' => null,
            'message' => null,
        ];
    }

    public function getPricing(string $tld): ?array
    {
        return null;
    }
}
