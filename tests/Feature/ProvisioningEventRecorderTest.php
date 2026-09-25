<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\Integrations\ProvisioningResult;
use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductModule;
use App\Models\ProvisioningEvent;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\HostingService;
use App\Services\Modules\ModuleManager;
use App\Services\Provisioning\ManualProvisioner;
use App\Services\Provisioning\ProvisioningDispatcher;
use App\Services\Provisioning\ProvisioningEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithModuleFixtures;
use Tests\Fixtures\Modules\OkModule\OkModule;
use Tests\TestCase;

class ProvisioningEventRecorderTest extends TestCase
{
    use InteractsWithModuleFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpModuleFixtures();
    }

    // ── a. durable running -> completed / failed transitions ──

    public function test_begin_creates_running_row_and_complete_flips_it(): void
    {
        $recorder = app(ProvisioningEventRecorder::class);

        $event = $recorder->begin('provision', ['module' => 'hyperv', 'action' => 'provision']);

        $this->assertSame('running', $event->status);
        $this->assertSame('running', $event->event_status);
        $this->assertSame('provision', $event->event_type);
        $this->assertNull($event->completed_at);
        $this->assertSame('hyperv', $event->payload['module']);

        $completed = $recorder->complete($event, 'VM built', ['external_id' => 'abc', 'password' => 's3cret']);

        $this->assertSame('completed', $completed->status);
        $this->assertSame('completed', $completed->event_status);
        $this->assertNotNull($completed->completed_at);
        $this->assertNull($completed->last_error);
        $this->assertSame('VM built', $completed->result['message']);
        $this->assertSame('abc', $completed->result['external_id']);
        $this->assertSame('[redacted]', $completed->result['password']);

        // Still a single row — complete() flips, it never inserts.
        $this->assertSame(1, ProvisioningEvent::count());
    }

    public function test_fail_flips_running_row_with_last_error_and_null_completed_at(): void
    {
        $recorder = app(ProvisioningEventRecorder::class);

        $event = $recorder->begin('provision', ['module' => 'hyperv']);
        $failed = $recorder->fail($event, 'boom exploded');

        $this->assertSame('failed', $failed->status);
        $this->assertSame('failed', $failed->event_status);
        $this->assertSame('boom exploded', $failed->last_error);
        $this->assertNull($failed->completed_at);
        $this->assertSame('boom exploded', $failed->result['error']);
        $this->assertSame(1, ProvisioningEvent::count());
    }

    // ── b. hosting_account_id link ──

    public function test_hosting_account_id_is_persisted_on_new_rows(): void
    {
        $this->assertTrue(Schema::hasColumn('provisioning_events', 'hosting_account_id'));

        $recorder = app(ProvisioningEventRecorder::class);

        $event = $recorder->begin('provision', ['module' => 'hyperv'], 4242, 4243);
        $this->assertSame(4242, $event->service_instance_id);
        $this->assertSame(4243, $event->hosting_account_id);
        $this->assertSame(4243, $event->fresh()->hosting_account_id);

        $oneShot = $recorder->record('suspend', 'completed', ['module' => 'cpanel'], 'Suspended ok', [], 11, 22);
        $this->assertNotNull($oneShot);
        $this->assertSame(22, $oneShot->hosting_account_id);
        $this->assertSame(11, $oneShot->service_instance_id);
    }

    // ── c. manual success resolves stale awaiting rows ──

    public function test_manual_success_resolves_awaiting_manual_vm_and_writes_single_completed_event(): void
    {
        $server = $this->hypervServer(['template_vms' => ['gold-win01'], 'template_vm' => 'gold-win01']);
        $product = $this->productWithHypervLink(['cpu' => 2, 'ram' => 2048, 'disk' => 50, 'switch' => 'Default Switch', 'generation' => 2]);
        $customer = $this->customer();
        $order = $this->activeOrderFor($product, $customer->id);
        $this->orderItem($order, $product, [
            ['key' => 'cpu', 'selected' => 2],
            ['key' => 'ram', 'selected' => 2048],
            ['key' => 'disk', 'selected' => 50],
        ]);
        $account = $this->hostingAccount($product, $server, $customer->id, $order->id, 'testvm1');

        // The stale queue entry left behind at payment time.
        $awaiting = app(ProvisioningDispatcher::class)->noteAwaitingManualVm($order->fresh());
        $this->assertNotNull($awaiting);
        $this->assertSame('pending', $awaiting->status);

        // Decoys that must NOT be touched: another order's awaiting row and a
        // different pending reason on this order.
        $otherOrder = $this->activeOrderFor($product, $customer->id);
        $otherAwaiting = app(ProvisioningDispatcher::class)->noteAwaitingManualVm($otherOrder->fresh());
        $otherPending = app(ProvisioningEventRecorder::class)->record(
            'provision', 'pending',
            ['reason' => 'manual_link', 'module' => 'ok-module', 'order_id' => $order->id, 'order_number' => $order->order_number],
        );

        Http::fake($this->fakeCloneSuccess('gold-win01'));

        $result = app(ManualProvisioner::class)->provision($account->fresh(), 'hyperv', 'gold-win01');

        $this->assertTrue($result->success);
        $this->assertSame(HostingService::STATUS_ACTIVE, $account->fresh()->status);

        // The awaiting row for this order is resolved…
        $awaiting = $awaiting->fresh();
        $this->assertSame('completed', $awaiting->status);
        $this->assertSame('completed', $awaiting->event_status);
        $this->assertNotNull($awaiting->completed_at);
        $this->assertStringContainsString('Manual VM build', (string) ($awaiting->result['message'] ?? ''));
        $this->assertSame('manual_vm_build', $awaiting->result['resolved_by'] ?? null);

        // …while the decoys stay pending.
        $this->assertSame('pending', $otherAwaiting->fresh()->status);
        $this->assertSame('pending', $otherPending->fresh()->status);

        // Exactly one completed provision event belongs to the new service and
        // no running row was left behind.
        $service = ServiceInstance::where('order_id', $order->id)->first();
        $this->assertNotNull($service);
        $this->assertSame(1, ProvisioningEvent::where('service_instance_id', $service->id)->where('event_status', 'completed')->count());
        $this->assertSame(0, ProvisioningEvent::where('event_status', 'running')->count());

        $completed = ProvisioningEvent::where('service_instance_id', $service->id)->where('event_status', 'completed')->sole();
        $this->assertSame('provision', $completed->payload['action'] ?? null);
        $this->assertSame('hyperv', $completed->payload['module'] ?? null);
        $this->assertSame($account->id, $completed->hosting_account_id);
        $this->assertSame($order->id, $completed->payload['order_id'] ?? null);
    }

    // ── d. manual driver failure ──

    public function test_manual_failure_writes_failed_event_and_leaves_account_pending(): void
    {
        $module = $this->ensureOkModule();

        $server = Server::create([
            'name' => 'ok-server',
            'ip_address' => '10.0.0.50',
            'server_type' => 'custom',
            'api_url' => 'https://panel.example.test',
            'api_username' => 'user',
            'api_key' => 'SECRET',
            'max_accounts' => 0,
            'status' => 'active',
        ]);
        $customer = $this->customer();
        $product = Product::create(['name' => 'Ok Manual', 'price' => 10, 'provisioning_module' => 'cpanel']);
        ProductModule::create([
            'product_id' => $product->id,
            'module_slug' => $module->slug,
            'enabled' => true,
            'config' => [],
        ]);
        $account = HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'server_id' => $server->id,
            'status' => HostingService::STATUS_PENDING,
            'host_name' => 'ok-host-1',
        ]);

        $this->app->bind(OkModule::class, fn () => new class extends OkModule
        {
            public function provision(ServiceInstance $service, array $config): ProvisioningResult
            {
                return ProvisioningResult::fail('panel refused the account');
            }
        });

        Http::fake();

        $result = app(ManualProvisioner::class)->provision($account->fresh(), $module->slug);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('panel refused', (string) $result->message);
        $this->assertSame(HostingService::STATUS_PENDING, $account->fresh()->status);

        $event = ProvisioningEvent::sole();
        $this->assertSame('provision', $event->event_type);
        $this->assertSame('failed', $event->event_status);
        $this->assertSame('failed', $event->status);
        $this->assertStringContainsString('panel refused', (string) ($event->result['error'] ?? ''));
        $this->assertSame('panel refused the account', $event->last_error);
        $this->assertNull($event->completed_at);
        $this->assertSame($account->id, $event->hosting_account_id);
        $this->assertSame(0, ProvisioningEvent::where('event_status', 'completed')->count());
    }

    // ── e. redaction ──

    public function test_redact_strips_secrets_recursively(): void
    {
        $clean = app(ProvisioningEventRecorder::class)->redact([
            'username' => 'admin',
            'password' => 's3cret',
            'API_KEY' => 'KEY',
            'tokenValue' => 'tok',
            'nested' => [
                'private_key' => 'pem',
                'apikey' => 'k',
                'secret_sauce' => 'x',
                'plain' => 'keep',
            ],
            'count' => 3,
        ]);

        $this->assertSame('admin', $clean['username']);
        $this->assertSame('[redacted]', $clean['password']);
        $this->assertSame('[redacted]', $clean['API_KEY']);
        $this->assertSame('[redacted]', $clean['tokenValue']);
        $this->assertSame('[redacted]', $clean['nested']['private_key']);
        $this->assertSame('[redacted]', $clean['nested']['apikey']);
        $this->assertSame('[redacted]', $clean['nested']['secret_sauce']);
        $this->assertSame('keep', $clean['nested']['plain']);
        $this->assertSame(3, $clean['count']);
    }

    // ── helpers ──

    private function ensureOkModule(): \App\Models\Module
    {
        config(['modules.path' => base_path('tests/Fixtures/modules')]);
        $manager = app(ModuleManager::class);
        $manager->reconcile();
        $module = $manager->find('ok-module');
        $manager->activate($module);

        return $module;
    }

    private function hypervServer(array $connectionMeta = []): Server
    {
        return Server::create([
            'name' => 'hv-rec-'.uniqid(),
            'ip_address' => '10.0.0.'.random_int(10, 250),
            'server_type' => 'hyperv',
            'api_url' => 'http://10.0.0.9:5985',
            'api_username' => 'admin',
            'api_password_encrypted' => 'SECRET',
            'max_accounts' => 0,
            'status' => 'active',
            'connection_meta' => $connectionMeta,
        ]);
    }

    private function productWithHypervLink(array $config): Product
    {
        $product = Product::create([
            'name' => 'HV Product '.uniqid(),
            'price' => 50,
            'provisioning_module' => 'hyperv',
            'billing_cycle' => 'monthly',
            'status' => 'active',
        ]);
        ProductModule::create([
            'product_id' => $product->id,
            'module_slug' => 'hyperv',
            'enabled' => true,
            'provisioning_mode' => ProductModule::PROVISIONING_MODE_MANUAL,
            'config' => $config,
        ]);

        return $product;
    }

    private function customer(): Customer
    {
        return Customer::create(['user_id' => User::factory()->create()->id, 'status' => 'active']);
    }

    private function activeOrderFor(Product $product, int $customerId): Order
    {
        return Order::create([
            'customer_id' => $customerId,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 50.00,
            'status' => Order::STATUS_ACTIVE,
            'domain_name' => 'example.test',
        ]);
    }

    private function hostingAccount(Product $product, Server $server, int $customerId, ?int $orderId, string $hostName): HostingAccount
    {
        return HostingAccount::create([
            'customer_id' => $customerId,
            'product_id' => $product->id,
            'server_id' => $server->id,
            'order_id' => $orderId,
            'status' => HostingService::STATUS_PENDING,
            'host_name' => $hostName,
        ]);
    }

    /**
     * @param  list<array{key: string, selected: mixed}>  $options
     */
    private function orderItem(Order $order, Product $product, array $options): \App\Models\OrderItem
    {
        return \App\Models\OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_price' => 50,
            'total' => 50,
            'config_options' => ['options' => $options],
        ]);
    }

    private function fakeCloneSuccess(string $template): callable
    {
        return function ($request) use ($template) {
            $body = (string) $request->body();
            if (str_contains($body, 'Copy-Item')) {
                return Http::response(['vmId' => '11111111-2222-3333-4444-555555555555', 'name' => 'testvm1', 'state' => 'Off', 'vhdPath' => 'C:\\VMs\\testvm1.vhdx', 'switchName' => 'Default Switch', 'generation' => 1, 'cloned' => true, 'templateVm' => $template]);
            }
            if (str_contains($body, 'Test-Path') && str_contains($body, '.vhdx') && ! str_contains($body, $template) && ! str_contains($body, 'Copy-Item')) {
                return Http::response(['vhdPath' => 'C:\\VMs\\testvm1.vhdx']);
            }
            if (str_contains($body, $template) && str_contains($body, 'Get-VM')) {
                return Http::response(['generation' => 1, 'sourceVhd' => 'C:\\VMs\\'.$template.'.vhdx']);
            }
            // Host-verify probe after create (Get-VM -Id): the VM now exists.
            if (str_contains($body, 'Get-VM -Id')) {
                return Http::response(['exists' => true, 'name' => 'testvm1', 'state' => 'Off', 'vmId' => '11111111-2222-3333-4444-555555555555']);
            }
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => false]);
            }

            return Http::response(['error' => 'unexpected'], 500);
        };
    }
}
