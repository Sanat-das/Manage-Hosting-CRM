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
use App\Models\ProductModule;
use App\Models\Role;
use App\Models\User;
use App\Services\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\SshConsole\Models\SshConsoleConfig;
use Modules\SshConsole\Models\SshConsoleSession;
use Modules\SshConsole\Services\SshTerminalService;
use Tests\TestCase;

/**
 * Linux VPS web SSH terminal end-to-end against the real module: settings
 * persistence with keep-if-blank secrets, password reveal, session lifecycle
 * (open -> input/resize queues -> close), per-admin ownership guard and the
 * streamed failure path (unreachable host marks the audit row failed and
 * emits an error frame). No live SSH server required.
 */
class SshConsoleTerminalTest extends TestCase
{
    use RefreshDatabase;

    private Module $module;

    /**
     * One admin identity per test: open/input/stream must authenticate as
     * the SAME user because terminal tokens are scoped to their creator.
     */
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
        // Routes added after the router was compiled have a stale nameList —
        // refresh so route() / getByName() resolve the final names.
        app('router')->getRoutes()->refreshNameLookups();
        app('router')->getRoutes()->refreshActionLookups();
    }

    // ------------------------------------------------------------------
    // a) Settings update persists host/port/username; secrets are stored
    //    encrypted and independently keep-if-blank.
    // ------------------------------------------------------------------

    public function test_update_persists_settings_and_preserves_blank_secrets(): void
    {
        $account = $this->makeAccountWithIp();

        $this->actingAsAdmin()
            ->from(route('admin.hosting.show', $account))
            ->put(route('admin.ssh-console.update', $account), [
                'host' => ' 203.0.113.50 ',
                'port' => '2222',
                'username' => ' deploy ',
                'password' => ' secret-password ',
                'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nAAAATEST\n-----END OPENSSH PRIVATE KEY-----",
                'passphrase' => ' key-pass ',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'SSH settings saved.');

        $config = SshConsoleConfig::query()->where('hosting_account_id', $account->id)->firstOrFail();

        $this->assertSame('203.0.113.50', $config->host);
        $this->assertSame(2222, $config->port);
        $this->assertSame('deploy', $config->username);

        // Encrypted at rest, decrypted through the model cast.
        $this->assertNotSame('secret-password', $config->getRawOriginal('password_encrypted'));
        $this->assertSame('secret-password', $config->password_encrypted);
        $this->assertStringContainsString('AAAATEST', (string) $config->private_key_encrypted);
        $this->assertSame('key-pass', $config->passphrase_encrypted);

        // Blank secret inputs preserve every stored secret.
        $this->actingAsAdmin()
            ->put(route('admin.ssh-console.update', $account), [
                'host' => 'vps.example.test',
                'username' => 'root',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $config->refresh();

        $this->assertSame('vps.example.test', $config->host);
        $this->assertSame(22, $config->port);
        $this->assertSame('root', $config->username);
        $this->assertSame('secret-password', $config->password_encrypted);
        $this->assertStringContainsString('AAAATEST', (string) $config->private_key_encrypted);
        $this->assertSame('key-pass', $config->passphrase_encrypted);
    }

    // ------------------------------------------------------------------
    // b) Password endpoint reveals the decrypted password on demand and
    //    reports whether a key is configured.
    // ------------------------------------------------------------------

    public function test_password_endpoint_reveals_secret_and_key_presence(): void
    {
        $account = $this->makeAccountWithIp();

        SshConsoleConfig::create([
            'hosting_account_id' => $account->id,
            'host' => '203.0.113.50',
            'port' => 22,
            'username' => 'root',
            'password_encrypted' => 'plain-secret',
            'private_key_encrypted' => 'KEYDATA',
        ]);

        $this->actingAsAdmin()
            ->get(route('admin.ssh-console.password', $account))
            ->assertOk()
            ->assertJson(['password' => 'plain-secret', 'hasKey' => true]);
    }

    // ------------------------------------------------------------------
    // c) Terminal page renders connection details without credentials.
    // ------------------------------------------------------------------

    public function test_html_page_renders_target_without_credentials(): void
    {
        $account = $this->makeAccountWithIp();

        SshConsoleConfig::create([
            'hosting_account_id' => $account->id,
            'host' => null,
            'port' => 22,
            'username' => 'root',
            'password_encrypted' => 'SUPERSECRET',
        ]);

        $this->actingAsAdmin()
            ->get(route('admin.ssh-console.html', $account))
            ->assertOk()
            ->assertSee('root@203.0.113.10:22')
            ->assertSee('(from assigned IP)', false)
            ->assertDontSee('SUPERSECRET')
            ->assertSee('xterm');
    }

    // ------------------------------------------------------------------
    // d) Open creates an owned audit row and returns a 32-hex token;
    //    input/resize queue through the cache; close finalizes the row.
    // ------------------------------------------------------------------

    public function test_session_lifecycle_open_input_resize_close(): void
    {
        $account = $this->makeAccountWithIp();

        $token = $this->openSession($account);
        $session = SshConsoleSession::query()->where('token', $token)->firstOrFail();
        $this->assertSame('opened', $session->status);
        $this->assertNotNull($session->started_at);

        // Input lands in the cache queue in order, binary-safe: keystrokes
        // are relayed base64-encoded so middleware trimming cannot corrupt
        // control characters (\r = Enter).
        foreach (["ls -la\r", "echo hi\n"] as $chunk) {
            $this->postJson(route('admin.ssh-console.input', [$account, $token]), ['data' => base64_encode($chunk)])
                ->assertNoContent();
        }
        $queued = Cache::get('ssh-console.in.'.$token);
        $this->assertSame("ls -la\recho hi\n", $queued);

        // Resize lands as a control message.
        $this->postJson(route('admin.ssh-console.resize', [$account, $token]), ['cols' => 120, 'rows' => 40])
            ->assertNoContent();
        $control = Cache::get('ssh-console.ctrl.'.$token);
        $this->assertSame([['type' => 'resize', 'cols' => 120, 'rows' => 40]], $control);

        // Close signals the streamer and optimistically finalizes the row.
        $this->postJson(route('admin.ssh-console.close', [$account, $token]))
            ->assertNoContent();

        $session->refresh();
        $this->assertSame('closed', $session->status);
        $this->assertNotNull($session->ended_at);

        // Finalization is idempotent: a second close does not duplicate work
        // or resurrect the row.
        $this->postJson(route('admin.ssh-console.close', [$account, $token]))
            ->assertNoContent();
        $this->assertSame(1, SshConsoleSession::query()->where('token', $token)->count());
    }

    // ------------------------------------------------------------------
    // e) Tokens are bearer credentials scoped to their creator: another
    //    admin's token is 404 for input/close/stream, and malformed tokens
    //    never reach a query.
    // ------------------------------------------------------------------

    public function test_tokens_are_scoped_to_their_owner(): void
    {
        $account = $this->makeAccountWithIp();
        $token = $this->openSession($account);

        // A DIFFERENT admin (same role, so middleware passes) must not be
        // able to use someone else's terminal token.
        $other = User::factory()->create();
        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        foreach (['hosting.view', 'hosting.manage'] as $permName) {
            $perm = Permission::firstOrCreate(['name' => $permName], ['label' => ucfirst($permName)]);
            $adminRole->permissions()->syncWithoutDetaching($perm->id);
        }
        $other->assignRole('admin');

        $this->actingAs($other)
            ->postJson(route('admin.ssh-console.input', [$account, $token]), ['data' => 'rm -rf /'])
            ->assertNotFound();

        $this->actingAs($other)
            ->postJson(route('admin.ssh-console.close', [$account, $token]))
            ->assertNotFound();

        // Malformed token short-circuits before any lookup.
        $this->actingAsAdmin()
            ->postJson(route('admin.ssh-console.close', [$account, str_repeat('z', 40)]))
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // e2) The hosting show page renders the SSH (and RDP) host fields as
    //     dropdowns listing the IPs assigned to the account — public first,
    //     with a "Use assigned IP" default and a preserved "(custom)" entry
    //     for legacy free-text hosts.
    // ------------------------------------------------------------------

    public function test_hosting_show_renders_assigned_ip_dropdowns(): void
    {
        $manager = app(ModuleManager::class);

        // Enable rdp-console too so its RDP modal renders on the same page.
        $ws = $manager->find('rdp-console');

        if ($ws !== null) {
            $manager->activate($ws);
            $instance = $manager->resolve($ws);
            $instance?->boot($manager->contextFor($ws));
        }

        $manager->registerModuleRoutes();
        app('router')->getRoutes()->refreshNameLookups();
        app('router')->getRoutes()->refreshActionLookups();

        $account = $this->makeAccountWithIp();

        // The SSH/RDP cards are gated on an enabled module link per product.
        ProductModule::create([
            'product_id' => $account->product_id,
            'module_id' => $this->module->id,
            'enabled' => true,
            'config' => [],
        ]);

        // A second, private assignment — must be listed below the public one.
        IpAddress::create([
            'subnet_id' => IpSubnet::create([
                'name' => 'SSH Private Subnet',
                'subnet_cidr' => '10.99.0.0/24',
            ])->id,
            'ip_address' => '10.99.0.5',
            'type' => 'private',
            'assigned_to_type' => HostingAccount::class,
            'assigned_to_id' => $account->id,
        ]);

        SshConsoleConfig::create([
            'hosting_account_id' => $account->id,
            'host' => null,
            'port' => 22,
            'username' => 'root',
        ]);

        $response = $this->actingAsAdmin()
            ->get(route('admin.hosting.show', $account))
            ->assertOk()
            ->assertSee('id="ssh-host"', false)
            ->assertSee('<option value="">Use assigned IP</option>', false)
            ->assertSee('203.0.113.10 · Public', false)
            ->assertSee('10.99.0.5 · Private', false);

        if ($ws !== null) {
            ProductModule::create([
                'product_id' => $account->product_id,
                'module_id' => $ws->id,
                'enabled' => true,
                'config' => [],
            ]);

            $response = $this->actingAsAdmin()
                ->get(route('admin.hosting.show', $account))
                ->assertOk()
                ->assertSee('id="rdp-host"', false)
                ->assertSee('<option value="">Use assigned IP</option>', false);
        }

        // Legacy free-text host that is not among the assigned IPs is
        // preserved as a selected "(custom)" option instead of being lost.
        SshConsoleConfig::query()->where('hosting_account_id', $account->id)
            ->update(['host' => 'vps.example.test']);

        $this->actingAsAdmin()
            ->get(route('admin.hosting.show', $account))
            ->assertOk()
            ->assertSee('<option value="vps.example.test" selected>vps.example.test (custom)</option>', false);
    }

    // ------------------------------------------------------------------
    // f) The streamed request owns the SSH lifecycle: an unreachable host
    //    fails fast (connection refused), marks the audit row 'failed' and
    //    emits an {"e":...} NDJSON error frame.
    // ------------------------------------------------------------------

    public function test_stream_with_unreachable_host_fails_row_and_emits_error_frame(): void
    {
        $account = $this->makeAccountWithIp();

        SshConsoleConfig::create([
            'hosting_account_id' => $account->id,
            'host' => '127.0.0.1',
            'port' => 1, // nothing listens here — instant refusal
            'username' => 'root',
            'password_encrypted' => 'irrelevant',
        ]);

        $token = $this->openSession($account);

        $response = $this->actingAsAdmin()
            ->get(route('admin.ssh-console.stream', [$account, $token]));

        $response->assertOk();

        $frames = array_filter(array_map('trim', explode("\n", (string) $response->streamedContent())));
        $this->assertNotEmpty($frames, 'Stream must emit at least one frame.');

        $decoded = array_map(fn (string $line) => json_decode($line, true), $frames);
        $errorFrames = array_values(array_filter($decoded, fn ($f) => is_array($f) && isset($f['e'])));

        $this->assertNotEmpty($errorFrames, 'An {"e":...} error frame must be emitted.');
        $this->assertDatabaseHas('ssh_console_sessions', [
            'token' => $token,
            'status' => 'failed',
        ]);
    }

    // ------------------------------------------------------------------
    // g) resolveHost: explicit config host wins over the assigned IP;
    //    without both there is no usable host.
    // ------------------------------------------------------------------

    public function test_resolve_host_prefers_config_then_public_ip(): void
    {
        $service = app(SshTerminalService::class);

        $withConfig = $this->makeAccountWithIp();
        $this->assertSame('10.9.9.9', $service->resolveHost($withConfig, ' 10.9.9.9 '));

        $fallback = $this->makeAccountWithIp();
        $this->assertSame('203.0.113.10', $service->resolveHost($fallback, null));
        $this->assertSame('203.0.113.10', $service->resolveHost($fallback, '   '));

        $bare = $this->makeAccount($this->makeProduct());
        $this->assertNull($service->resolveHost($bare, null));
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /**
     * Open a terminal session through the HTTP endpoint and return its token.
     */
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

        return Product::create(['name' => "Linux VPS {$sequence}"]);
    }

    private function makeAccount(Product $product): HostingAccount
    {
        static $sequence = 0;
        $sequence++;

        $customer = Customer::create(['user_id' => User::factory()->create()->id]);

        return HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'username' => "linvps{$sequence}",
            'status' => 'active',
        ]);
    }

    private function makeAccountWithIp(): HostingAccount
    {
        static $sequence = 0;
        $sequence++;

        $account = $this->makeAccount($this->makeProduct());

        $subnet = IpSubnet::create([
            'name' => "SSH Subnet {$sequence}",
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
