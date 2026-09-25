<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductModule;
use App\Models\ProvisioningEvent;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\HostingService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Order Configuration Options snapshot beats the product_module link config
 * for VM resources (cpu/ram/disk), which in turn beats module defaults.
 */
class ProvisioningOptionsResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_snapshot_wins_over_link_config(): void
    {
        $server = $this->hypervServer(['template_vms' => ['gold-win01'], 'template_vm' => 'gold-win01']);
        $product = $this->productWithHypervLink(['cpu' => 2, 'ram' => 2048, 'disk' => 50, 'switch' => 'Default Switch', 'generation' => 2]);
        $customer = $this->customer();
        $order = $this->activeOrderFor($product, $customer->id);
        $this->orderItem($order, $product, [
            ['key' => 'cpu', 'selected' => '2 Cores'],
            ['key' => 'ram', 'selected' => '1024 MB'],
            ['key' => 'disk', 'selected' => '20 GB'],
        ]);
        $account = $this->hostingAccount($product, $server, $customer->id, $order->id, 'testvm1');

        Http::fake($this->fakeCloneSuccess('gold-win01'));

        $result = app(\App\Services\Provisioning\ManualProvisioner::class)->provision($account, 'hyperv', 'gold-win01');

        $this->assertTrue($result->success);
        $joined = implode("\n", $this->bodies());
        // Snapshot values (2 / 1024 MB / 20 GB) reach the host, not link values.
        $this->assertStringContainsString('$cpu = 2', $joined);
        $this->assertStringContainsString('$ram = 1073741824', $joined);
        $this->assertStringContainsString('Resize-VHD', $joined);
        $this->assertStringContainsString('21474836480', $joined);

        $this->assertSame(HostingService::STATUS_ACTIVE, $account->fresh()->status);

        $cfg = ServiceInstance::where('order_id', $order->id)->sole()->provisioning_config ?? [];
        $this->assertSame(2, $cfg['cpu']);
        $this->assertSame(1024, $cfg['ram']);
        $this->assertSame(20, $cfg['disk']);
    }

    public function test_order_backed_account_missing_required_options_fails_without_host_call(): void
    {
        $server = $this->hypervServer(['template_vms' => ['gold-win01'], 'template_vm' => 'gold-win01']);
        $product = $this->productWithHypervLink(['cpu' => 2, 'ram' => 2048, 'disk' => 50, 'switch' => 'Default Switch', 'generation' => 2]);
        $customer = $this->customer();
        $order = $this->activeOrderFor($product, $customer->id);
        // Snapshot carries only cpu — ram and disk are module-required.
        $this->orderItem($order, $product, [
            ['key' => 'cpu', 'selected' => '2 Cores'],
        ]);
        $account = $this->hostingAccount($product, $server, $customer->id, $order->id, 'testvm1');

        Http::fake();

        $result = app(\App\Services\Provisioning\ManualProvisioner::class)->provision($account, 'hyperv', 'gold-win01');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('missing required configuration options', strtolower((string) $result->message));
        Http::assertNothingSent();
        $this->assertSame(HostingService::STATUS_PENDING, $account->fresh()->status);
        $this->assertSame(0, ProvisioningEvent::where('event_status', 'completed')->count());
    }

    public function test_order_less_account_falls_back_to_link_config(): void
    {
        $server = $this->hypervServer(['template_vms' => ['gold-win01'], 'template_vm' => 'gold-win01']);
        $product = $this->productWithHypervLink(['cpu' => 4, 'ram' => 4096, 'disk' => 100, 'switch' => 'Default Switch', 'generation' => 2]);
        $customer = $this->customer();
        $account = $this->hostingAccount($product, $server, $customer->id, null, 'testvm1');

        Http::fake($this->fakeCloneSuccess('gold-win01'));

        $result = app(\App\Services\Provisioning\ManualProvisioner::class)->provision($account, 'hyperv', 'gold-win01');

        $this->assertTrue($result->success);
        $joined = implode("\n", $this->bodies());
        $this->assertStringContainsString('$cpu = 4', $joined);
        $this->assertStringContainsString('$ram = 4294967296', $joined);
        $this->assertStringContainsString('107374182400', $joined);
    }

    public function test_dispatcher_snapshot_wins_for_required_keys(): void
    {
        // Blank-disk server so the auto path provisions without a template.
        $this->hypervServer();
        $user = User::factory()->create();
        $customer = Customer::create(['user_id' => $user->id, 'status' => 'active']);
        $product = Product::create(['name' => 'HV Auto', 'price' => 100, 'provisioning_module' => 'hyperv', 'billing_cycle' => 'monthly', 'status' => 'active']);
        ProductModule::create([
            'product_id' => $product->id,
            'module_slug' => 'hyperv',
            'enabled' => true,
            'provisioning_mode' => ProductModule::PROVISIONING_MODE_AUTO,
            'config' => ['cpu' => 2, 'ram' => 2048, 'disk' => 50],
        ]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 100.00,
            'status' => Order::STATUS_PENDING,
            'domain_name' => 'example.test',
        ]);
        $order = app(OrderService::class)->markPaid($order);
        $this->orderItem($order, $product, [
            ['key' => 'cpu', 'selected' => 4],
        ]);
        $order->load('items');

        Http::fake(function ($request) {
            $body = (string) $request->body();
            if (str_contains($body, 'New-VM')) {
                return Http::response(['vmId' => '11111111-2222-3333-4444-555555555555', 'name' => 'testvm1', 'state' => 'Off', 'vhdPath' => 'C:\\VMs\\testvm1.vhdx', 'switchName' => 'Default Switch', 'generation' => 2]);
            }
            // Host-verify probe after create: the VM now exists.
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => true, 'name' => 'testvm1', 'state' => 'Off', 'vmId' => '11111111-2222-3333-4444-555555555555']);
            }

            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $result = app(OrderService::class)->advanceAfterPayment($order);

        $this->assertSame(Order::STATUS_ACTIVE, $result->fresh()->status);
        $cfg = ServiceInstance::where('order_id', $order->id)->sole()->provisioning_config ?? [];
        // Snapshot cpu=4 is authoritative over link cpu=2 for the required key.
        $this->assertSame(4, $cfg['cpu']);
    }

    // ── helpers (mirror HyperVTemplateCloneTest / ProductHypervTemplateRestrictionTest) ──

    private function hypervServer(array $connectionMeta = []): Server
    {
        return Server::create([
            'name' => 'hv-1',
            'ip_address' => '10.0.0.9',
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

    /**
     * @param  list<array{key: string, selected: mixed}>  $options
     */
    private function orderItem(Order $order, Product $product, array $options): OrderItem
    {
        return OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_price' => 50,
            'total' => 50,
            'config_options' => ['options' => $options],
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

    /** @return list<string> */
    private function bodies(): array
    {
        return collect(Http::recorded())
            ->map(fn ($pair) => (string) $pair[0]->body())
            ->all();
    }
}
