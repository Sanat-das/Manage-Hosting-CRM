<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\Module;
use App\Models\Product;
use App\Models\ProductModule;
use App\Models\User;
use App\Services\Modules\ModuleManager;
use FreeDSx\Snmp\Exception\EndOfWalkException;
use FreeDSx\Snmp\Oid;
use FreeDSx\Snmp\SnmpClient;
use FreeDSx\Snmp\SnmpWalk;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Modules\SnmpMonitor\Models\SnmpTarget;
use Modules\SnmpMonitor\Services\SnmpCollector;

/**
 * Shared plumbing for snmp-monitor pipeline feature tests: module class
 * autoloading, monitoring-connection table replay under sqlite :memory:,
 * a scripted network-free SNMP client and account/target fixtures.
 */
trait InteractsWithSnmpMonitorModule
{
    private static bool $snmpAutoloaderRegistered = false;

    /**
     * The dedicated monitoring connection — under tests it mirrors sqlite
     * :memory:, so every assertion against snmp_* time-series tables must
     * go through this connection, never assertDatabaseHas().
     */
    protected function monitoring(): Connection
    {
        return DB::connection('monitoring');
    }

    protected function registerSnmpMonitorAutoloader(): void
    {
        if (self::$snmpAutoloaderRegistered) {
            return;
        }

        self::$snmpAutoloaderRegistered = true;

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
     * Create the module's own tables through the REAL production path —
     * ModuleManager::activate() replays database/migrations via the
     * ModuleMigrationRunner (snmp_targets on the default connection plus
     * the snmp_* time-series tables on the monitoring connection).
     * RefreshDatabase only covers core migrations, so every suite touching
     * SNMP tables activates the module first.
     */
    protected function ensureSnmpMonitoringTables(ModuleManager $manager): Module
    {
        $manager->reconcile();

        $module = $manager->find('snmp-monitor');
        $this->assertNotNull($module, 'snmp-monitor module must be discovered.');

        $manager->activate($module);

        return $module->refresh();
    }

    /**
     * The already-activated snmp-monitor row (setUp ran the activation).
     */
    protected function activateSnmpMonitorModule(ModuleManager $manager): Module
    {
        $module = $manager->find('snmp-monitor');
        $this->assertNotNull($module, 'snmp-monitor module must be discovered.');

        return $module;
    }

    /**
     * Product linked to the active snmp-monitor module with a per-product
     * config whose encrypted fields are stored as ciphertext exactly like
     * the admin UI does (so the job must decrypt them).
     */
    protected function makeMonitoredProduct(ModuleManager $manager, Module $module, array $config = []): Product
    {
        static $sequence = 0;
        $sequence++;

        $product = Product::create(['name' => "SNMP Pipeline Product {$sequence}"]);

        $config = array_merge([
            'snmp_version' => 'v2c',
            'snmp_community' => 'pub-community',
            'snmp_port' => 161,
            'snmp_timeout' => 2,
            'snmp_auth_password' => 's3cret-pass',
            'collect_cpu' => true,
            'collect_memory' => true,
            'collect_disks' => true,
            'collect_network' => true,
            'collect_processes' => false,
        ], $config);

        ProductModule::create([
            'product_id' => $product->id,
            'module_id' => $module->id,
            'enabled' => true,
            'config' => $manager->encryptConfig($module, $config),
        ]);

        return $product;
    }

    protected function makeAccount(Product $product): HostingAccount
    {
        static $sequence = 0;
        $sequence++;

        $customer = Customer::create(['user_id' => User::factory()->create()->id]);

        return HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'username' => "snmppipe{$sequence}",
            'status' => 'active',
        ]);
    }

    protected function makeTarget(HostingAccount $account, array $overrides = []): SnmpTarget
    {
        return SnmpTarget::query()->create(array_merge([
            'hosting_account_id' => $account->id,
            'host' => '192.0.2.50',
            'target_os' => SnmpTarget::OS_LINUX,
            'poll_interval' => 60,
        ], $overrides));
    }

    /**
     * A faked FreeDSx SNMP client with scripted responses and no network
     * I/O. Public properties let a test mutate counters or inject failures
     * between polls. Hand-rolled as an anonymous subclass instead of a
     * Mockery mock: mocking the concrete SnmpClient/SnmpWalk classes
     * generates eval'd mock classes large enough to exhaust phpunit's
     * memory limit.
     */
    protected function fakeSnmpClient(): SnmpClient
    {
        $client = new class extends SnmpClient
        {
            /** @var array<string, Oid> scripted getOid() answers keyed by OID. */
            public array $gets = [];

            /** @var array<string, SnmpWalk> scripted walk() answers keyed by start OID. */
            public array $walks = [];

            /** When set, getOid() throws this (simulates an unreachable agent). */
            public ?\Throwable $getException = null;

            public function getOid($oid): ?Oid
            {
                if ($this->getException !== null) {
                    throw $this->getException;
                }

                return $this->gets[(string) $oid] ?? null;
            }

            public function walk(?string $startAt = null, ?string $endAt = null): SnmpWalk
            {
                if ($this->getException !== null) {
                    throw $this->getException;
                }

                $key = (string) $startAt;

                if (! isset($this->walks[$key])) {
                    throw new \RuntimeException("Unexpected SNMP walk [{$key}].");
                }

                return $this->walks[$key];
            }

            public function close(): void {}
        };

        $client->gets['1.3.6.1.2.1.1.5.0'] = Oid::fromString('1.3.6.1.2.1.1.5.0', 'LINUX-VPS-01');
        $client->gets['1.3.6.1.2.1.1.1.0'] = Oid::fromString(
            '1.3.6.1.2.1.1.1.0',
            'Linux linux-vps-01 5.15.0-91-generic #101-Ubuntu SMP Tue Nov 22 14:00:00 UTC 2022 x86_64'
        );
        // 283953000 hundredths of a second = 32 days, 20:45:30.
        $client->gets['1.3.6.1.2.1.1.3.0'] = Oid::fromTimeticks('1.3.6.1.2.1.1.3.0', 283953000);
        // hrMemorySize is reported in KB -> 4096 MB.
        $client->gets['1.3.6.1.2.1.25.2.2.0'] = Oid::fromInteger('1.3.6.1.2.1.25.2.2.0', 4194304);
        // UCD-SNMP-MIB laLoad.1 (empty hrProcessorLoad walk falls back here).
        $client->gets['1.3.6.1.4.1.2021.10.1.3.1'] = Oid::fromString('1.3.6.1.4.1.2021.10.1.3.1', '0.42');

        $client->walks['1.3.6.1.2.1.25.3.3.1.2'] = static::fakeSnmpWalk([]);

        // hrStorageTable: row 1 = /dev/sda1 fixed disk (4096-byte units,
        // 100 GB total / 50 GB used), row 2 = physical memory (1024-byte
        // units, used = 2048 MB), rows 3-5 = linux mount-noise rows.
        $client->walks['1.3.6.1.2.1.25.2.3.1'] = static::fakeSnmpWalk([
            Oid::fromOid('1.3.6.1.2.1.25.2.3.1.2.1', '1.3.6.1.2.1.25.2.1.4'),
            Oid::fromString('1.3.6.1.2.1.25.2.3.1.3.1', '/dev/sda1'),
            Oid::fromInteger('1.3.6.1.2.1.25.2.3.1.4.1', 4096),
            Oid::fromInteger('1.3.6.1.2.1.25.2.3.1.5.1', 26214400),
            Oid::fromInteger('1.3.6.1.2.1.25.2.3.1.6.1', 13107200),
            Oid::fromOid('1.3.6.1.2.1.25.2.3.1.2.2', '1.3.6.1.2.1.25.2.1.2'),
            Oid::fromString('1.3.6.1.2.1.25.2.3.1.3.2', 'Physical memory'),
            Oid::fromInteger('1.3.6.1.2.1.25.2.3.1.4.2', 1024),
            Oid::fromInteger('1.3.6.1.2.1.25.2.3.1.5.2', 4194304),
            Oid::fromInteger('1.3.6.1.2.1.25.2.3.1.6.2', 2097152),
            Oid::fromOid('1.3.6.1.2.1.25.2.3.1.2.3', '1.3.6.1.2.1.25.2.1.4'),
            Oid::fromString('1.3.6.1.2.1.25.2.3.1.3.3', '/dev/loop0'),
            Oid::fromInteger('1.3.6.1.2.1.25.2.3.1.4.3', 1024),
            Oid::fromInteger('1.3.6.1.2.1.25.2.3.1.5.3', 62592),
            Oid::fromInteger('1.3.6.1.2.1.25.2.3.1.6.3', 62592),
        ]);

        // IF-MIB ifTable: lo (index 1) and eth0 (index 2) with counters.
        $client->walks['1.3.6.1.2.1.2.2.1'] = static::fakeSnmpWalk([
            Oid::fromInteger('1.3.6.1.2.1.2.2.1.1.1', 1),
            Oid::fromString('1.3.6.1.2.1.2.2.1.2.1', 'lo'),
            Oid::fromInteger('1.3.6.1.2.1.2.2.1.10.1', 500),
            Oid::fromInteger('1.3.6.1.2.1.2.2.1.16.1', 700),
            Oid::fromInteger('1.3.6.1.2.1.2.2.1.1.2', 2),
            Oid::fromString('1.3.6.1.2.1.2.2.1.2.2', 'eth0'),
            Oid::fromInteger('1.3.6.1.2.1.2.2.1.10.2', 1000),
            Oid::fromInteger('1.3.6.1.2.1.2.2.1.16.2', 2000),
        ]);

        return $client;
    }

    /**
     * A walk iterator over a fixed list of OIDs (no network).
     *
     * @param  list<Oid>  $oids
     */
    protected static function fakeSnmpWalk(array $oids): SnmpWalk
    {
        return new class($oids) extends SnmpWalk
        {
            private array $queue;

            public function __construct(array $oids)
            {
                // Parent constructor intentionally skipped: no client needed.
                $this->queue = $oids;
            }

            public function hasOids(): bool
            {
                return $this->queue !== [];
            }

            public function next(): Oid
            {
                if ($this->queue === []) {
                    throw new EndOfWalkException('There are no more OIDs left in the walk.');
                }

                return array_shift($this->queue);
            }
        };
    }

    /**
     * A collect()-recording stand-in bound into the container under the
     * collector's class name so the job resolves it instead of building a
     * live client. SnmpCollector is final, so this delegates to a real
     * instance rather than extending it — the job only ever calls
     * collect(host, config), which is exactly what gets recorded.
     */
    protected function bindCapturingCollector(SnmpClient $fake): object
    {
        $inner = new SnmpCollector($fake);

        $capturing = new class($inner)
        {
            /** @var list<array{0: string, 1: array<string, mixed>}> */
            public array $calls = [];

            public function __construct(private readonly SnmpCollector $inner) {}

            public function collect(string $host, array $config): array
            {
                $this->calls[] = [$host, $config];

                return $this->inner->collect($host, $config);
            }
        };

        app()->instance(SnmpCollector::class, $capturing);

        return $capturing;
    }
}
