<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\PanelAccount;
use App\Models\Product;
use App\Models\ProductModule;
use App\Models\ProvisioningEvent;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\ServerGroupMember;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\Modules\ModuleManager;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\DirectAdmin\DirectAdmin;
use Modules\Plesk\Plesk;
use Modules\Virtualizor\Virtualizor;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Plesk, DirectAdmin and Virtualizor on top of AbstractPanelModule.
 *
 * Each panel reports failure in its own way and none of them use HTTP status
 * codes to do it, so the fakes below all return 200 with a panel-shaped error
 * body. That is the thing most likely to be got wrong, so it is what these
 * tests pin down.
 *
 * NB: these drivers are written against each vendor's documented API shape and
 * verified against faked responses - they have not been run against a live
 * panel.
 */
class PanelModulesProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->reconcile();
    }

    private function activate(string $slug): void
    {
        $manager = app(ModuleManager::class);
        $manager->activate($manager->find($slug));
    }

    // ─────────────────────────── Plesk ───────────────────────────

    public function test_plesk_creates_a_client_then_a_subscription(): void
    {
        Http::fake([
            '*/api/v2/clients' => Http::response(['id' => 42]),
            '*/api/v2/domains' => Http::response(['id' => 77]),
        ]);

        $order = $this->makePaidOrder('plesk', 8443);
        $result = app(OrderService::class)->advanceAfterPayment($order);

        $this->assertSame(Order::STATUS_ACTIVE, $result->fresh()->status);

        $account = PanelAccount::sole();
        $this->assertSame('plesk', $account->panel);
        $this->assertSame('77', $account->external_id);

        // Order matters: the subscription needs the client id.
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/v2/clients')
            && $r['login'] === $account->username);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/v2/domains')
            && $r['name'] === 'acme.test'
            && $r['owner_client']['id'] === 42
            && $r['plan']['name'] === 'starter');
    }

    public function test_plesk_suspend_uses_the_cli_gateway_and_checks_the_exit_code(): void
    {
        Http::fake([
            '*/api/v2/clients' => Http::response(['id' => 1]),
            '*/api/v2/domains' => Http::response(['id' => 2]),
            '*/api/v2/cli/subscription/call' => Http::response(['code' => 0, 'stdout' => 'SUCCESS']),
        ]);

        app(OrderService::class)->advanceAfterPayment($this->makePaidOrder('plesk', 8443));

        $result = app(Plesk::class)->suspend(ServiceInstance::sole(), []);

        $this->assertTrue($result->success);
        $this->assertSame(PanelAccount::STATUS_SUSPENDED, PanelAccount::sole()->status);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/cli/subscription/call')
            && $r['params'] === ['--suspend', 'acme.test']);
    }

    public function test_plesk_cli_nonzero_exit_is_a_failure_despite_http_200(): void
    {
        Http::fake([
            '*/api/v2/clients' => Http::response(['id' => 1]),
            '*/api/v2/domains' => Http::response(['id' => 2]),
            '*/api/v2/cli/subscription/call' => Http::response(['code' => 1, 'stderr' => 'Subscription does not exist']),
        ]);

        app(OrderService::class)->advanceAfterPayment($this->makePaidOrder('plesk', 8443));

        $result = app(Plesk::class)->suspend(ServiceInstance::sole(), []);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Subscription does not exist', (string) $result->message);
        // The local record must not claim suspended when the panel refused.
        $this->assertSame(PanelAccount::STATUS_ACTIVE, PanelAccount::sole()->status);
    }

    public function test_plesk_rest_error_fails_the_order(): void
    {
        Http::fake([
            '*/api/v2/clients' => Http::response(['message' => 'Login name is already in use'], 400),
        ]);

        $result = app(OrderService::class)->advanceAfterPayment($this->makePaidOrder('plesk', 8443));

        $this->assertSame(Order::STATUS_FAILED, $result->fresh()->status);
        $this->assertSame(0, PanelAccount::count());
        $this->assertStringContainsString(
            'Login name is already in use',
            ProvisioningEvent::where('event_status', 'failed')->sole()->result['error'],
        );
    }

    // ─────────────────────────── DirectAdmin ───────────────────────────

    public function test_directadmin_creates_a_user_and_parses_the_urlencoded_response(): void
    {
        Http::fake(['*' => Http::response('error=0&text=Account%20Created')]);

        $order = $this->makePaidOrder('directadmin', 2222);
        $result = app(OrderService::class)->advanceAfterPayment($order);

        $this->assertSame(Order::STATUS_ACTIVE, $result->fresh()->status);

        $account = PanelAccount::sole();
        $this->assertSame('directadmin', $account->panel);
        // DirectAdmin caps usernames at 10 characters.
        $this->assertLessThanOrEqual(10, strlen($account->username));

        Http::assertSent(fn ($r) => str_contains($r->url(), 'CMD_API_ACCOUNT_USER')
            && $r['action'] === 'create'
            && $r['username'] === $account->username
            && $r['domain'] === 'acme.test'
            && $r['package'] === 'starter');
    }

    public function test_directadmin_error_response_is_a_failure_despite_http_200(): void
    {
        Http::fake(['*' => Http::response('error=1&text=Cannot%20Create%20User&details=Username%20already%20exists')]);

        $result = app(OrderService::class)->advanceAfterPayment($this->makePaidOrder('directadmin', 2222));

        $this->assertSame(Order::STATUS_FAILED, $result->fresh()->status);

        $error = ProvisioningEvent::where('event_status', 'failed')->sole()->result['error'];
        $this->assertStringContainsString('Cannot Create User', $error);
        $this->assertStringContainsString('Username already exists', $error);
    }

    public function test_directadmin_html_login_page_is_treated_as_a_failure(): void
    {
        // Wrong credentials: DirectAdmin answers 200 with a login form, which
        // parses to no `error` key at all.
        Http::fake(['*' => Http::response('<html><body><form>login</form></body></html>')]);

        $result = app(OrderService::class)->advanceAfterPayment($this->makePaidOrder('directadmin', 2222));

        $this->assertSame(Order::STATUS_FAILED, $result->fresh()->status);
        $this->assertStringContainsString(
            'unexpected response',
            ProvisioningEvent::where('event_status', 'failed')->sole()->result['error'],
        );
    }

    public function test_directadmin_suspend_and_terminate_use_select_users(): void
    {
        Http::fake(['*' => Http::response('error=0&text=ok')]);

        app(OrderService::class)->advanceAfterPayment($this->makePaidOrder('directadmin', 2222));

        $service = ServiceInstance::sole();
        $module = app(DirectAdmin::class);
        $username = PanelAccount::sole()->username;

        $this->assertTrue($module->suspend($service, [])->success);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'CMD_API_SELECT_USERS')
            && ($r['suspend'] ?? null) === 'Suspend' && $r['select0'] === $username);

        $this->assertTrue($module->unsuspend($service, [])->success);
        $this->assertSame(PanelAccount::STATUS_ACTIVE, PanelAccount::sole()->status);

        $this->assertTrue($module->terminate($service, [])->success);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'CMD_API_SELECT_USERS')
            && ($r['delete'] ?? null) === 'yes');
        $this->assertSame(PanelAccount::STATUS_TERMINATED, PanelAccount::sole()->status);
    }

    // ─────────────────────────── Virtualizor ───────────────────────────

    public function test_virtualizor_creates_a_vps_and_records_the_vpsid(): void
    {
        Http::fake(['*' => Http::response(['done' => 1, 'vpsid' => 909, 'ips' => ['10.5.5.5']])]);

        $order = $this->makePaidOrder('virtualizor', 4085, ['plan' => '3', 'osid' => '270']);
        $result = app(OrderService::class)->advanceAfterPayment($order);

        $this->assertSame(Order::STATUS_ACTIVE, $result->fresh()->status);

        $account = PanelAccount::sole();
        $this->assertSame('virtualizor', $account->panel);
        $this->assertSame('909', $account->external_id);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'act=addvs')
            && str_contains($r->url(), 'apikey=KEYID')
            && $r['plid'] === '3'
            && $r['osid'] === '270');
    }

    public function test_virtualizor_provisions_without_a_domain(): void
    {
        Http::fake(['*' => Http::response(['vpsid' => 5])]);

        // A VPS has a hostname, not a domain - a domainless order must work.
        $order = $this->makePaidOrder('virtualizor', 4085, ['plan' => '3', 'osid' => '270'], domain: null);
        $result = app(OrderService::class)->advanceAfterPayment($order);

        $this->assertSame(Order::STATUS_ACTIVE, $result->fresh()->status);
        Http::assertSent(fn ($r) => str_ends_with((string) $r['hostname'], '.vps'));
    }

    public function test_virtualizor_missing_plan_config_fails_before_any_call(): void
    {
        Http::fake();

        $result = app(OrderService::class)->advanceAfterPayment(
            $this->makePaidOrder('virtualizor', 4085, ['plan' => '', 'osid' => ''])
        );

        $this->assertSame(Order::STATUS_FAILED, $result->fresh()->status);
        Http::assertNothingSent();
        $this->assertStringContainsString(
            'Plan ID',
            ProvisioningEvent::where('event_status', 'failed')->sole()->result['error'],
        );
    }

    public function test_virtualizor_flattens_a_field_error_map(): void
    {
        Http::fake(['*' => Http::response(['error' => ['hostname' => 'Invalid hostname', 'plid' => 'No such plan']])]);

        $result = app(OrderService::class)->advanceAfterPayment(
            $this->makePaidOrder('virtualizor', 4085, ['plan' => '3', 'osid' => '270'])
        );

        $this->assertSame(Order::STATUS_FAILED, $result->fresh()->status);

        $error = ProvisioningEvent::where('event_status', 'failed')->sole()->result['error'];
        $this->assertStringContainsString('Invalid hostname', $error);
        $this->assertStringContainsString('No such plan', $error);
    }

    public function test_virtualizor_without_a_vpsid_cannot_be_managed(): void
    {
        Http::fake(['*' => Http::response(['done' => 1])]);

        $result = app(OrderService::class)->advanceAfterPayment(
            $this->makePaidOrder('virtualizor', 4085, ['plan' => '3', 'osid' => '270'])
        );

        // No id means nothing can address the machine later - fail rather than
        // record an unmanageable VPS.
        $this->assertSame(Order::STATUS_FAILED, $result->fresh()->status);
        $this->assertSame(0, PanelAccount::count());
    }

    public function test_virtualizor_lifecycle_targets_the_vpsid(): void
    {
        Http::fake(['*' => Http::response(['vpsid' => 909])]);

        app(OrderService::class)->advanceAfterPayment(
            $this->makePaidOrder('virtualizor', 4085, ['plan' => '3', 'osid' => '270'])
        );

        $module = app(Virtualizor::class);
        $service = ServiceInstance::sole();

        $this->assertTrue($module->suspend($service, [])->success);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'suspend=909'));

        $this->assertTrue($module->terminate($service, [])->success);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'delete=909'));
    }

    // ─────────────────────── shared behaviour ───────────────────────

    #[DataProvider('panels')]
    public function test_unreachable_panel_fails_without_leaking_credentials(string $slug, int $port): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: connection refused'));

        $config = $slug === 'virtualizor' ? ['plan' => '3', 'osid' => '270'] : [];
        $result = app(OrderService::class)->advanceAfterPayment($this->makePaidOrder($slug, $port, $config));

        $this->assertSame(Order::STATUS_FAILED, $result->fresh()->status);

        $error = json_encode(ProvisioningEvent::where('event_status', 'failed')->sole()->result);
        $this->assertStringContainsString('Could not reach', $error);
        $this->assertStringNotContainsString('SECRET', $error);
    }

    #[DataProvider('panels')]
    public function test_unconfigured_server_fails_before_any_call(string $slug, int $port): void
    {
        Http::fake();

        $config = $slug === 'virtualizor' ? ['plan' => '3', 'osid' => '270'] : [];
        $order = $this->makePaidOrder($slug, $port, $config);
        Server::query()->update(['api_key' => null, 'api_username' => null]);

        $result = app(OrderService::class)->advanceAfterPayment($order);

        $this->assertSame(Order::STATUS_FAILED, $result->fresh()->status);
        Http::assertNothingSent();
        $this->assertStringContainsString(
            'is not configured for '.$slug,
            ProvisioningEvent::where('event_status', 'failed')->sole()->result['error'],
        );
    }

    public static function panels(): array
    {
        return [
            'plesk' => ['plesk', 8443],
            'directadmin' => ['directadmin', 2222],
            'virtualizor' => ['virtualizor', 4085],
        ];
    }

    // ─────────────────────────── helpers ───────────────────────────

    /**
     * @param  array<string, mixed>  $config
     */
    private function makePaidOrder(string $slug, int $port, array $config = [], ?string $domain = 'acme.test'): Order
    {
        $this->activate($slug);

        $server = Server::create([
            'name' => $slug.'-1',
            'ip_address' => '10.0.0.1',
            'panel_type' => $slug,
            'api_url' => 'https://panel.example.net:'.$port,
            'api_username' => $slug === 'virtualizor' ? 'KEYID' : 'admin',
            'api_key' => 'SECRET',
            'max_accounts' => 0,
            'status' => 'active',
        ]);

        $group = ServerGroup::create(['name' => $slug.' group', 'status' => 'active']);
        ServerGroupMember::create([
            'server_group_id' => $group->id,
            'server_id' => $server->id,
            'priority' => 1,
        ]);

        $product = Product::create([
            'name' => $slug.' plan',
            'price' => 100,
            'provisioning_module' => $slug,
            'server_group_id' => $group->id,
        ]);

        ProductModule::create([
            'product_id' => $product->id,
            'module_id' => app(ModuleManager::class)->find($slug)->id,
            'enabled' => true,
            'config' => $config === [] ? ['plan' => 'starter'] : $config,
        ]);

        $customer = Customer::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'active',
        ]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 100.00,
            'domain_name' => $domain,
            'status' => Order::STATUS_PENDING,
        ]);

        return app(OrderService::class)->markPaid($order);
    }
}
