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
use App\Services\Provisioning\ServerAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Cpanel\Cpanel;
use Tests\TestCase;

/**
 * End-to-end cover for the cPanel module: a paid order must produce a real
 * createacct call against the allocated WHM server, and every failure mode
 * must land the order in 'failed' with the panel's own reason recorded rather
 * than throwing into the payment path.
 *
 * WHM answers HTTP 200 for application-level errors, so the fakes below return
 * 200 with `metadata.result = 0` for the failure cases — asserting on status
 * codes here would test nothing.
 */
class CpanelProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The real modules directory (not the fixture one) — this exercises
        // the shipped cpanel module, including its own migration.
        $manager = app(ModuleManager::class);
        $manager->reconcile();
        $manager->activate($manager->find('cpanel'));
    }

    public function test_paid_order_provisions_a_cpanel_account(): void
    {
        Http::fake([
            '*/json-api/createacct*' => Http::response([
                'metadata' => ['result' => 1, 'reason' => 'Account Created'],
                'data' => ['ip' => '10.0.0.9', 'nameserver' => 'ns1.example.net'],
            ]),
        ]);

        $order = $this->makePaidOrder();

        $result = app(OrderService::class)->advanceAfterPayment($order);

        $this->assertSame(Order::STATUS_ACTIVE, $result->fresh()->status);

        $account = PanelAccount::sole();
        $this->assertSame('active', $account->status);
        $this->assertSame('acme.test', $account->domain);
        $this->assertNotNull($account->provisioned_at);

        // WHM was called with the account's identity and the product's package.
        Http::assertSent(function ($request) use ($account) {
            // NB: parse_str() rewrites dots in keys, so `api.version` arrives
            // as `api_version` — assert on the raw query string for that one.
            $rawQuery = (string) parse_url($request->url(), PHP_URL_QUERY);
            parse_str($rawQuery, $query);

            return str_contains($request->url(), '/json-api/createacct')
                && $query['username'] === $account->username
                && $query['domain'] === 'acme.test'
                && $query['plan'] === 'starter'
                && str_contains($rawQuery, 'api.version=1')
                && $request->header('Authorization')[0] === 'whm root:TOKEN123';
        });
    }

    public function test_generated_password_is_encrypted_at_rest_and_redacted_from_the_audit_log(): void
    {
        Http::fake(['*/json-api/createacct*' => Http::response([
            'metadata' => ['result' => 1, 'reason' => 'Account Created'],
        ])]);

        app(OrderService::class)->advanceAfterPayment($this->makePaidOrder());

        $account = PanelAccount::sole();
        $plaintext = $account->password_encrypted;
        $this->assertNotEmpty($plaintext);

        // The column holds ciphertext, not the password.
        $raw = DB::table('panel_accounts')->where('id', $account->id)->value('password_encrypted');
        $this->assertNotSame($plaintext, $raw);
        $this->assertStringNotContainsString($plaintext, (string) $raw);

        // And the append-only event log never keeps it at all.
        $event = ProvisioningEvent::where('event_status', 'completed')->sole();
        $this->assertSame('[redacted]', $event->result['password']);
        $this->assertStringNotContainsString($plaintext, json_encode($event->result));
    }

    public function test_whm_error_fails_the_order_with_the_panel_reason(): void
    {
        Http::fake(['*/json-api/createacct*' => Http::response([
            'metadata' => ['result' => 0, 'reason' => 'Username already exists'],
        ])]);

        $order = $this->makePaidOrder();

        $result = app(OrderService::class)->advanceAfterPayment($order);

        $this->assertSame(Order::STATUS_FAILED, $result->fresh()->status);
        $this->assertSame(0, PanelAccount::count());

        $event = ProvisioningEvent::where('event_status', 'failed')->sole();
        $this->assertStringContainsString('Username already exists', $event->result['error']);
    }

    public function test_unreachable_whm_fails_cleanly_without_leaking_the_token(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: connection refused'));

        $order = $this->makePaidOrder();

        $result = app(OrderService::class)->advanceAfterPayment($order);

        $this->assertSame(Order::STATUS_FAILED, $result->fresh()->status);

        $event = ProvisioningEvent::where('event_status', 'failed')->sole();
        $this->assertStringContainsString('Could not reach WHM', $event->result['error']);
        $this->assertStringNotContainsString('TOKEN123', json_encode($event->result));
    }

    public function test_server_without_credentials_fails_before_any_http_call(): void
    {
        Http::fake();

        $order = $this->makePaidOrder();
        Server::query()->update(['api_key' => null, 'api_username' => null]);

        $result = app(OrderService::class)->advanceAfterPayment($order);

        $this->assertSame(Order::STATUS_FAILED, $result->fresh()->status);
        Http::assertNothingSent();

        $event = ProvisioningEvent::where('event_status', 'failed')->sole();
        $this->assertStringContainsString('is not configured for cpanel', $event->result['error']);
        $this->assertStringContainsString('api_username and api_key', $event->result['error']);
    }

    public function test_reprovisioning_an_active_account_does_not_call_createacct_twice(): void
    {
        Http::fake(['*/json-api/createacct*' => Http::response([
            'metadata' => ['result' => 1, 'reason' => 'Account Created'],
        ])]);

        $order = $this->makePaidOrder();
        app(OrderService::class)->advanceAfterPayment($order);

        $service = ServiceInstance::sole();
        $config = ['plan' => 'starter'];

        $second = app(Cpanel::class)->provision($service, $config);

        $this->assertTrue($second->success);
        $this->assertStringContainsString('already provisioned', (string) $second->message);
        Http::assertSentCount(1);
    }

    public function test_suspend_unsuspend_and_terminate_hit_the_right_whm_functions(): void
    {
        Http::fake([
            '*/json-api/createacct*' => Http::response(['metadata' => ['result' => 1]]),
            '*/json-api/suspendacct*' => Http::response(['metadata' => ['result' => 1]]),
            '*/json-api/unsuspendacct*' => Http::response(['metadata' => ['result' => 1]]),
            '*/json-api/removeacct*' => Http::response(['metadata' => ['result' => 1]]),
        ]);

        app(OrderService::class)->advanceAfterPayment($this->makePaidOrder());

        $service = ServiceInstance::sole();
        $module = app(Cpanel::class);

        $this->assertTrue($module->suspend($service, [])->success);
        $this->assertSame('suspended', PanelAccount::sole()->status);
        $this->assertNotNull(PanelAccount::sole()->suspended_at);

        $this->assertTrue($module->unsuspend($service, [])->success);
        $this->assertSame('active', PanelAccount::sole()->status);

        $this->assertTrue($module->terminate($service, [])->success);
        $this->assertSame('terminated', PanelAccount::sole()->status);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/json-api/suspendacct'));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/json-api/removeacct'));
    }

    public function test_lifecycle_calls_fail_when_no_account_was_ever_created(): void
    {
        Http::fake();

        $service = ServiceInstance::create([
            'customer_id' => $this->makeCustomer()->id,
            'service_tag' => 'SVC-ORPHAN',
            'username' => 'orphan',
        ]);

        $result = app(Cpanel::class)->suspend($service, []);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('No cpanel account is recorded', (string) $result->message);
        Http::assertNothingSent();
    }

    public function test_username_is_cpanel_legal(): void
    {
        Http::fake(['*/json-api/createacct*' => Http::response(['metadata' => ['result' => 1]])]);

        // A domain that is reserved, digit-leading and over-long once suffixed.
        app(OrderService::class)->advanceAfterPayment(
            $this->makePaidOrder('9verylongdomainname.example.test')
        );

        $username = PanelAccount::sole()->username;

        $this->assertLessThanOrEqual(16, strlen($username));
        $this->assertMatchesRegularExpression('/^[a-z][a-z0-9]*$/', $username);
    }

    public function test_allocator_honours_group_priority_and_skips_full_servers(): void
    {
        $allocator = app(ServerAllocator::class);

        $group = ServerGroup::create(['name' => 'Shared', 'status' => 'active']);
        $full = Server::create([
            'name' => 'full', 'ip_address' => '10.0.0.1', 'panel_type' => 'cpanel',
            'max_accounts' => 1, 'status' => 'active',
        ]);
        $spare = Server::create([
            'name' => 'spare', 'ip_address' => '10.0.0.2', 'panel_type' => 'cpanel',
            'max_accounts' => 0, 'status' => 'active',
        ]);

        ServerGroupMember::create(['server_group_id' => $group->id, 'server_id' => $full->id, 'priority' => 1]);
        ServerGroupMember::create(['server_group_id' => $group->id, 'server_id' => $spare->id, 'priority' => 2]);

        $product = Product::create(['name' => 'P', 'price' => 1, 'server_group_id' => $group->id]);

        // Priority 1 wins while it has room.
        $this->assertSame($full->id, $allocator->allocate($product, 'cpanel')->id);

        // Fill it, and allocation falls through to the next priority tier.
        ServiceInstance::create([
            'customer_id' => $this->makeCustomer()->id,
            'server_id' => $full->id,
            'service_tag' => 'SVC-FILL',
            'username' => 'fill',
            'status' => 'active',
        ]);

        $this->assertSame($spare->id, $allocator->allocate($product, 'cpanel')->id);
    }

    public function test_allocator_ignores_servers_of_the_wrong_panel_type(): void
    {
        Server::create([
            'name' => 'plesk box', 'ip_address' => '10.0.0.3',
            'panel_type' => 'plesk', 'status' => 'active',
        ]);

        $this->assertNull(app(ServerAllocator::class)->allocate(null, 'cpanel'));
    }

    // ─────────────────────────── helpers ───────────────────────────

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'active',
        ]);
    }

    private function makePaidOrder(string $domain = 'acme.test'): Order
    {
        $server = Server::create([
            'name' => 'whm-1',
            'ip_address' => '10.0.0.1',
            'panel_type' => 'cpanel',
            'api_url' => 'https://whm.example.net:2087',
            'api_username' => 'root',
            'api_key' => 'TOKEN123',
            'max_accounts' => 0,
            'status' => 'active',
        ]);

        $group = ServerGroup::create(['name' => 'cPanel Shared', 'status' => 'active']);
        ServerGroupMember::create([
            'server_group_id' => $group->id,
            'server_id' => $server->id,
            'priority' => 1,
        ]);

        $product = Product::create([
            'name' => 'cPanel Starter',
            'price' => 100,
            'provisioning_module' => 'cpanel',
            'server_group_id' => $group->id,
        ]);

        // The WHM package is per-product config on the module link.
        $module = app(ModuleManager::class)->find('cpanel');
        ProductModule::create([
            'product_id' => $product->id,
            'module_id' => $module->id,
            'enabled' => true,
            'config' => ['plan' => 'starter', 'verify_tls' => true],
        ]);

        $order = Order::create([
            'customer_id' => $this->makeCustomer()->id,
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
