<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\IpAddress;
use App\Models\IpSubnet;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use Modules\SshConsole\Models\SshConsoleConfig;
use Modules\SshConsole\Models\SshConsoleSession;
use Modules\SshConsole\Services\SshTerminalService;
use phpseclib3\Net\SSH2;
use Tests\TestCase;

/**
 * NDJSON SSH stream prompt without a live VPS.
 *
 * Mocks SshTerminalService::connect to return a fake SSH2 handle and
 * SshTerminalService::streamLoop to yield deterministic shell-prompt frames.
 * Then GETs the streamed NDJSON endpoint and asserts the streaming contract:
 * 200, application/x-ndjson, X-Accel-Buffering disabled, valid o-frame with
 * a base64-decoded prompt, and proper session finalization. No network I/O.
 */
class SshConsoleStreamTest extends TestCase
{
    use RefreshDatabase;

    private Module $module;

    private ?User $adminUser = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = null;

        $manager = app(ModuleManager::class);
        $manager->reconcile();

        $module = $manager->find('ssh-console');
        $this->assertNotNull($module, 'ssh-console module must be discovered from base_path(\'modules\').');
        $this->module = $module;

        $manager->activate($module);

        $instance = $manager->resolve($module);
        $this->assertNotNull($instance, 'ssh-console provider must resolve.');

        $instance->boot($manager->contextFor($module));
        $manager->registerModuleRoutes();
        app('router')->getRoutes()->refreshNameLookups();
        app('router')->getRoutes()->refreshActionLookups();
    }

    /**
     * Mockery::close() throws when an expectation went unmet — which is what
     * happens whenever a test in this class fails before consuming the stream.
     * Without the finally, that exception skipped parent::tearDown(), so
     * RefreshDatabase never rolled back and every later test in the run died
     * with "cannot start a transaction within a transaction". One failure here
     * used to poison ~70 unrelated tests.
     */
    protected function tearDown(): void
    {
        try {
            \Mockery::close();
        } finally {
            parent::tearDown();
        }
    }

    // ------------------------------------------------------------------
    // Primary: NDJSON prompt without live 10.0.5.109
    // ------------------------------------------------------------------

    public function test_stream_returns_ndjson_prompt_without_live_vps(): void
    {
        $account = $this->makeAccountWithIp();

        SshConsoleConfig::create([
            'hosting_account_id' => $account->id,
            'host' => null, // fall back to assigned 203.0.113.10 — mocked anyway
            'port' => 22,
            'username' => 'root',
            'password_encrypted' => 'test-password',
        ]);

        $fakeSsh = \Mockery::mock(SSH2::class);
        $fakeSsh->shouldReceive('disconnect')->byDefault()->andReturn(true);
        $fakeSsh->shouldIgnoreMissing();

        $this->partialMock(SshTerminalService::class, function (MockInterface $mock) use ($fakeSsh): void {
            $mock->shouldReceive('connect')
                ->once()
                ->andReturn($fakeSsh);

            $mock->shouldReceive('streamLoop')
                ->once()
                ->with($fakeSsh, \Mockery::type('string'))
                ->andReturnUsing(function (SSH2 $ssh, string $token): \Generator {
                    // Simulate a shell banner + prompt. Each frame is base64-encoded
                    // by the real streamLoop contract.
                    yield ['o' => base64_encode("root@fake-vps:~# ")];
                    yield ['o' => base64_encode("\r\nWelcome to fake VPS\r\n")];
                });
        });

        $token = $this->openSession($account);

        $response = $this->actingAsAdmin()
            ->get(route('admin.ssh-console.stream', [$account, $token]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/x-ndjson');
        $response->assertHeader('X-Accel-Buffering', 'no');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Content-Encoding', 'none');

        $raw = (string) $response->streamedContent();
        $lines = array_values(array_filter(array_map('trim', explode("\n", $raw)), fn (string $l) => $l !== ''));
        $this->assertNotEmpty($lines, 'Stream must emit at least one NDJSON line.');

        $frames = array_map(fn (string $line) => json_decode($line, true), $lines);
        foreach ($frames as $i => $frame) {
            $this->assertIsArray($frame, "Line {$i} must be valid JSON: {$lines[$i]}");
        }

        $outputFrames = array_values(array_filter($frames, fn ($f) => is_array($f) && isset($f['o'])));
        $this->assertNotEmpty($outputFrames, 'At least one {"o": base64} output frame must be present.');

        $decoded = implode('', array_map(fn (array $f) => (string) base64_decode((string) $f['o'], true), $outputFrames));
        $this->assertStringContainsString('root@fake-vps', $decoded, 'Decoded prompt must contain the fake shell prompt.');
        $this->assertStringContainsString('Welcome to fake VPS', $decoded);

        // Successful generator completion finalizes the audit row to closed
        // via the controller's finally block (idempotent).
        $this->assertDatabaseHas('ssh_console_sessions', [
            'token' => $token,
            'status' => 'closed',
        ]);
    }

    // ------------------------------------------------------------------
    // Headers: buffering disabled contract (covers chunked + no-cache too)
    // ------------------------------------------------------------------

    public function test_stream_headers_disable_buffering_and_caching(): void
    {
        $account = $this->makeAccountWithIp();

        SshConsoleConfig::create([
            'hosting_account_id' => $account->id,
            'host' => '203.0.113.50',
            'port' => 22,
            'username' => 'deploy',
            'password_encrypted' => 'pw',
        ]);

        $fakeSsh = \Mockery::mock(SSH2::class);
        $fakeSsh->shouldReceive('disconnect')->byDefault()->andReturn(true);
        $fakeSsh->shouldIgnoreMissing();

        $this->partialMock(SshTerminalService::class, function (MockInterface $mock) use ($fakeSsh): void {
            $mock->shouldReceive('connect')->once()->andReturn($fakeSsh);
            $mock->shouldReceive('streamLoop')->once()->andReturnUsing(function (): \Generator {
                yield ['o' => base64_encode('$ ')];
                yield ['h' => 1];
            });
        });

        $token = $this->openSession($account);

        $response = $this->actingAsAdmin()
            ->get(route('admin.ssh-console.stream', [$account, $token]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/x-ndjson');
        $response->assertHeader('X-Accel-Buffering', 'no');
        // Laravel/Symfony normalizes Cache-Control (adds private); check contains not exact.
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', (string) $cacheControl);
        $this->assertStringContainsString('no-cache', (string) $cacheControl);
        $this->assertSame('no-cache', $response->headers->get('Pragma'));

        $raw = (string) $response->streamedContent();
        $frames = array_map(fn (string $l) => json_decode(trim($l), true), array_filter(array_map('trim', explode("\n", $raw))));
        $hasOutput = count(array_filter($frames, fn ($f) => isset($f['o']))) > 0;
        $hasHeartbeat = count(array_filter($frames, fn ($f) => isset($f['h']))) > 0;
        $this->assertTrue($hasOutput, 'Expected at least one o-frame even in header-only test.');
        $this->assertTrue($hasHeartbeat, 'Expected heartbeat frame {h:1} from mocked streamLoop.');
    }

    // ------------------------------------------------------------------
    // Prune: ssh:prune closes stale opened sessions and purges cache queues
    // ------------------------------------------------------------------

    public function test_prune_command_closes_stale_sessions(): void
    {
        $account = $this->makeAccountWithIp();

        $freshToken = bin2hex(random_bytes(16));
        $staleToken = bin2hex(random_bytes(16));

        $fresh = SshConsoleSession::create([
            'hosting_account_id' => $account->id,
            'admin_user_id' => $this->actingAsAdmin()->adminUserIdForTest(),
            'token' => $freshToken,
            'ip_address' => '127.0.0.1',
            'status' => 'opened',
            'started_at' => now(),
        ]);

        $stale = SshConsoleSession::create([
            'hosting_account_id' => $account->id,
            'admin_user_id' => $this->actingAsAdmin()->adminUserIdForTest(),
            'token' => $staleToken,
            'ip_address' => '127.0.0.1',
            'status' => 'opened',
            'started_at' => now()->subMinutes(60),
        ]);

        // Seed cache queues for the stale token — prune must forget them.
        Cache::put('ssh-console.in.'.$staleToken, 'pending input', now()->addHour());
        Cache::put('ssh-console.ctrl.'.$staleToken, [['type' => 'resize', 'cols' => 80, 'rows' => 24]], now()->addHour());
        Cache::put('ssh-console.act.'.$staleToken, microtime(true), now()->addHour());

        $this->artisan('ssh:prune', ['--minutes' => '35'])
            ->assertExitCode(0)
            ->expectsOutputToContain('Pruned 1 stale SSH session');

        $this->assertDatabaseHas('ssh_console_sessions', [
            'id' => $stale->id,
            'status' => 'closed',
            'error' => 'Pruned: stale',
        ]);

        $this->assertDatabaseHas('ssh_console_sessions', [
            'id' => $fresh->id,
            'status' => 'opened',
        ]);

        $this->assertNull(Cache::get('ssh-console.in.'.$staleToken));
        $this->assertNull(Cache::get('ssh-console.ctrl.'.$staleToken));
        $this->assertNull(Cache::get('ssh-console.act.'.$staleToken));
    }

    public function test_prune_dry_run_does_not_modify_rows(): void
    {
        $account = $this->makeAccountWithIp();

        $staleToken = bin2hex(random_bytes(16));

        $stale = SshConsoleSession::create([
            'hosting_account_id' => $account->id,
            'admin_user_id' => $this->actingAsAdmin()->adminUserIdForTest(),
            'token' => $staleToken,
            'ip_address' => '127.0.0.1',
            'status' => 'opened',
            'started_at' => now()->subMinutes(60),
        ]);

        $this->artisan('ssh:prune', ['--minutes' => '35', '--dry-run' => true])
            ->assertExitCode(0);

        $stale->refresh();
        $this->assertSame('opened', $stale->status, 'Dry run must not finalize rows.');
    }

    // ==================================================================
    // Helpers (mirrors SshConsoleTerminalTest)
    // ==================================================================

    private function openSession(HostingAccount $account): string
    {
        $response = $this->actingAsAdmin()
            ->postJson(route('admin.ssh-console.open', $account));

        $response->assertOk();

        $token = (string) $response->json('token');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);

        return $token;
    }

    private function makeProduct(): Product
    {
        static $sequence = 0;
        $sequence++;

        return Product::create(['name' => "Linux VPS Stream {$sequence} ".uniqid()]);
    }

    private function makeAccount(Product $product): HostingAccount
    {
        static $sequence = 0;
        $sequence++;

        $customer = Customer::create(['user_id' => User::factory()->create()->id]);

        return HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'username' => "linvps_stream{$sequence}_".uniqid(),
            'status' => 'active',
        ]);
    }

    private function makeAccountWithIp(): HostingAccount
    {
        static $sequence = 0;
        $sequence++;

        $account = $this->makeAccount($this->makeProduct());

        $subnet = IpSubnet::create([
            'name' => "SSH Stream Subnet {$sequence} ".uniqid(),
            'subnet_cidr' => "203.0.113.{$sequence}/24",
        ]);

        IpAddress::create([
            'subnet_id' => $subnet->id,
            'ip_address' => '203.0.113.10',
            'type' => 'public',
            'assigned_to_type' => HostingAccount::class,
            'assigned_to_id' => $account->id,
        ]);

        return $account;
    }

    /**
     * Return the current admin user for direct model creation.
     * Used so prune tests can set admin_user_id deterministically.
     */
    private function adminUserIdForTest(): int
    {
        // actingAsAdmin() already created the user — reuse it.
        if ($this->adminUser !== null) {
            return $this->adminUser->id;
        }

        $this->actingAsAdmin();

        return $this->adminUser->id;
    }

    private function actingAsAdmin(): self
    {
        if ($this->adminUser === null) {
            $user = User::factory()->create();

            $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
            foreach (['hosting.view', 'hosting.manage'] as $permName) {
                $perm = Permission::firstOrCreate(['name' => $permName], ['label' => ucfirst($permName)]);
                $adminRole->permissions()->syncWithoutDetaching($perm->id);
            }

            $user->assignRole('admin');
            $this->adminUser = $user;
        }

        return $this->actingAs($this->adminUser);
    }
}
