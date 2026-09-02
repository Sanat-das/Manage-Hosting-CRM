<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\IpAddress;
use App\Models\IpSubnet;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\SnmpMonitor\Models\SnmpTarget;
use Modules\SnmpMonitor\Services\TargetService;
use Tests\TestCase;

/**
 * snmp_targets storage + host-resolution service: an explicit stored host
 * beats IPAM, a public-subnet lease beats legacy type=public which beats
 * any lease, and ensureForAccount upserts (auto-filling a null host once a
 * lease appears) without ever storing credentials.
 */
class SnmpTargetsTest extends TestCase
{
    use RefreshDatabase;

    private static bool $autoloaderRegistered = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerSnmpMonitorAutoloader();
        $this->ensureTargetsTable();
    }

    // ------------------------------------------------------------------
    // a) Schema contract: expected columns exist and the table stays
    //    credential-free by design.
    // ------------------------------------------------------------------

    public function test_snmp_targets_table_schema_matches_contract(): void
    {
        $this->assertTrue(Schema::hasTable('snmp_targets'));
        $this->assertTrue(Schema::hasColumns('snmp_targets', [
            'id',
            'hosting_account_id',
            'host',
            'port',
            'target_os',
            'poll_interval',
            'enabled',
            'status',
            'consecutive_failures',
            'last_polled_at',
            'next_poll_at',
            'last_response_ms',
            'created_at',
            'updated_at',
        ]));

        // Guardrail: no credential columns may ever appear here — SNMP
        // community/auth secrets live in the product module config only.
        foreach (['password', 'community', 'auth_password', 'priv_password', 'secret', 'private_key'] as $forbidden) {
            $this->assertFalse(
                Schema::hasColumn('snmp_targets', $forbidden),
                "snmp_targets must not store credential column [{$forbidden}].",
            );
        }
    }

    // ------------------------------------------------------------------
    // b) Explicit configured host wins over any IPAM lease, and
    //    ensureForAccount preserves it instead of clobbering it.
    // ------------------------------------------------------------------

    public function test_explicit_host_wins_over_ipam(): void
    {
        $account = $this->makeAccount($this->makeProduct());
        $this->leaseIp($account, '203.0.113.20');

        $created = SnmpTarget::query()->create([
            'hosting_account_id' => $account->id,
            'host' => '198.51.100.9',
            'target_os' => SnmpTarget::OS_LINUX,
        ]);

        $resolved = app(TargetService::class)->resolveForAccount($account);

        $this->assertSame(['host' => '198.51.100.9', 'source' => TargetService::SOURCE_TARGET], $resolved);

        $ensured = app(TargetService::class)->ensureForAccount($account, SnmpTarget::OS_LINUX);

        $this->assertSame($created->id, $ensured->id);
        $this->assertSame('198.51.100.9', $ensured->refresh()->host);
    }

    // ------------------------------------------------------------------
    // c) With no stored host the public-subnet lease is picked even when
    //    a private lease was created first (order proves subnet priority).
    // ------------------------------------------------------------------

    public function test_ipam_public_subnet_lease_wins_when_no_host(): void
    {
        $account = $this->makeAccount($this->makeProduct());
        $this->leaseIp($account, '10.0.0.10', networkType: 'private', type: 'assigned');
        $this->leaseIp($account, '203.0.113.30', networkType: 'public', type: 'assigned');

        $resolved = app(TargetService::class)->resolveForAccount($account);

        $this->assertSame(['host' => '203.0.113.30', 'source' => TargetService::SOURCE_IPAM_PUBLIC_SUBNET], $resolved);
    }

    // ------------------------------------------------------------------
    // d) Legacy rows carrying type=public on a private subnet are honoured
    //    only after subnet evaluation fails.
    // ------------------------------------------------------------------

    public function test_legacy_type_public_lease_is_second_fallback(): void
    {
        $account = $this->makeAccount($this->makeProduct());
        $this->leaseIp($account, '198.51.100.77', networkType: 'private', type: 'public');

        $resolved = app(TargetService::class)->resolveForAccount($account);

        $this->assertSame(['host' => '198.51.100.77', 'source' => TargetService::SOURCE_LEGACY_TYPE_PUBLIC], $resolved);
    }

    // ------------------------------------------------------------------
    // e) Any remaining lease is the last resort before giving up.
    // ------------------------------------------------------------------

    public function test_any_lease_is_last_resort(): void
    {
        $account = $this->makeAccount($this->makeProduct());
        $this->leaseIp($account, '10.0.0.99', networkType: 'private', type: 'assigned');

        $resolved = app(TargetService::class)->resolveForAccount($account);

        $this->assertSame(['host' => '10.0.0.99', 'source' => TargetService::SOURCE_IPAM_ANY], $resolved);
    }

    // ------------------------------------------------------------------
    // f) An account with zero leases resolves to null and ensureForAccount
    //    stores host=null without throwing (QA failure scenario).
    // ------------------------------------------------------------------

    public function test_zero_leases_resolve_null_and_ensure_stores_null_host_without_throwing(): void
    {
        $account = $this->makeAccount($this->makeProduct());

        $this->assertNull(app(TargetService::class)->resolveForAccount($account));

        $target = app(TargetService::class)->ensureForAccount($account, SnmpTarget::OS_WINDOWS);

        $this->assertInstanceOf(SnmpTarget::class, $target);
        $this->assertNull($target->host);
        $this->assertSame(SnmpTarget::OS_WINDOWS, $target->target_os);
        // Defaults from the migration survive the create path.
        $this->assertTrue($target->enabled);
        $this->assertSame(161, $target->port);
        $this->assertSame(SnmpTarget::STATUS_UNKNOWN, $target->status);
        $this->assertSame(0, $target->consecutive_failures);
        $this->assertNull($target->next_poll_at);
    }

    // ------------------------------------------------------------------
    // g) A null-host target auto-fills on ensureForAccount once a lease
    //    appears — upserted in place, never duplicated.
    // ------------------------------------------------------------------

    public function test_null_host_target_autofills_on_ensure_once_a_lease_appears(): void
    {
        $account = $this->makeAccount($this->makeProduct());

        $created = app(TargetService::class)->ensureForAccount($account, SnmpTarget::OS_LINUX);
        $this->assertNull($created->host);

        $this->leaseIp($account, '203.0.113.44', networkType: 'public', type: 'assigned');

        $updated = app(TargetService::class)->ensureForAccount($account, SnmpTarget::OS_LINUX);

        $this->assertSame($created->id, $updated->id);
        $this->assertSame('203.0.113.44', $updated->host);
        $this->assertDatabaseHas('snmp_targets', [
            'hosting_account_id' => $account->id,
            'host' => '203.0.113.44',
        ]);
    }

    // ------------------------------------------------------------------
    // h) Unsupported OS values are rejected before they can fight the
    //    database enum with a confusing SQL error.
    // ------------------------------------------------------------------

    public function test_ensure_rejects_unsupported_target_os(): void
    {
        $account = $this->makeAccount($this->makeProduct());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('freebsd');

        app(TargetService::class)->ensureForAccount($account, 'freebsd');
    }

    // ------------------------------------------------------------------
    // Fixtures — mirroring SshConsoleModuleTest's snapshot-service data
    // patterns (product + account + polymorphic IPAM lease).
    // ------------------------------------------------------------------

    /**
     * Modules are not composer-mapped; load Modules\SnmpMonitor classes
     * straight from the module src dir like SshConsoleModuleTest does.
     */
    private function registerSnmpMonitorAutoloader(): void
    {
        if (self::$autoloaderRegistered) {
            return;
        }

        self::$autoloaderRegistered = true;

        $prefix = 'Modules\\SnmpMonitor\\';
        $src = dirname(__DIR__, 2).'/modules/snmp-monitor/src';

        spl_autoload_register(static function (string $class) use ($prefix, $src): void {
            if (! str_starts_with($class, $prefix)) {
                return;
            }

            $file = $src.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

            if (is_file($file)) {
                require_once $file;
            }
        });
    }

    /**
     * Guarantee snmp_targets exists even when module migrations have not run:
     * replay this module's own migration under the sqlite :memory: driver.
     */
    private function ensureTargetsTable(): void
    {
        if (Schema::hasTable('snmp_targets')) {
            return;
        }

        $migration = require base_path('modules/snmp-monitor/database/migrations/2026_08_24_000001_create_snmp_targets_table.php');
        $migration->up();
    }

    private function makeProduct(): Product
    {
        static $sequence = 0;
        $sequence++;

        return Product::create(['name' => "SNMP Target Product {$sequence}"]);
    }

    private function makeAccount(Product $product, ?Customer $customer = null): HostingAccount
    {
        static $sequence = 0;
        $sequence++;

        $customer ??= Customer::create(['user_id' => User::factory()->create()->id]);

        return HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'username' => "snmptgt{$sequence}",
            'status' => 'active',
        ]);
    }

    private function leaseIp(HostingAccount $account, string $address, string $networkType = 'public', string $type = 'assigned'): IpAddress
    {
        static $sequence = 0;
        $sequence++;

        $subnet = IpSubnet::create([
            'name' => "SNMP Targets Subnet {$sequence}",
            'subnet_cidr' => "203.0.{$sequence}.0/24",
            'network_type' => $networkType,
        ]);

        return IpAddress::create([
            'subnet_id' => $subnet->id,
            'ip_address' => $address,
            'type' => $type,
            'assigned_to_type' => HostingAccount::class,
            'assigned_to_id' => $account->id,
        ]);
    }
}
