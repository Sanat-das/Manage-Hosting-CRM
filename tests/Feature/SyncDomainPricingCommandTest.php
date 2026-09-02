<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\RegistrarDriver;
use App\Models\DomainPricing;
use App\Models\DomainSyncLog;
use App\Models\RegistrarSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncDomainPricingCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_populates_pricing(): void
    {
        RegistrarSetting::setMany('resellerclub', [
            'driver' => StubSyncDriver::class,
            'api_key' => 'k',
            'enabled' => '1',
        ]);

        $this->artisan('domains:sync-pricing')->assertExitCode(0);

        $pricing = DomainPricing::where('tld', 'com')->first();
        $this->assertNotNull($pricing);
        $this->assertSame('899.00', (string) $pricing->register_price);
        $this->assertSame('849.00', (string) $pricing->renew_price);
        $this->assertSame('899.00', (string) $pricing->transfer_price);
        $this->assertSame('INR', $pricing->currency);
        $this->assertNotNull($pricing->synced_at);

        $log = DomainSyncLog::where('provider', 'resellerclub')
            ->where('operation', 'sync-pricing')
            ->where('status', 'success')
            ->first();

        $this->assertNotNull($log);
        $this->assertContains('com', $log->payload['synced'] ?? []);
    }

    public function test_sync_with_no_registrar_skips(): void
    {
        $this->artisan('domains:sync-pricing')->assertExitCode(0);

        $this->assertDatabaseHas('domain_sync_log', [
            'provider' => 'none',
            'operation' => 'sync-pricing',
            'status' => 'skipped',
        ]);

        $this->assertSame(0, DomainPricing::count());
    }

    public function test_sync_registered_in_schedule(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('domains:sync-pricing')
            ->assertExitCode(0);
    }
}

/**
 * Test stub implementing RegistrarDriver.
 *
 * Lives in this test file so class_exists() finds it when the manager resolves
 * the driver via app(). Only getPricing() returns a fixed price for 'com';
 * everything else is a no-op. Never hits the network.
 */
class StubSyncDriver implements RegistrarDriver
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
        if ($tld !== 'com') {
            return null;
        }

        return [
            'tld' => 'com',
            'register' => 899.0,
            'renew' => 849.0,
            'transfer' => 899.0,
            'currency' => 'INR',
        ];
    }
}
