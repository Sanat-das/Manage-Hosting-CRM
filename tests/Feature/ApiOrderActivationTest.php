<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\Invoice;
use App\Models\IpAddress;
use App\Models\IpSubnet;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\User;
use App\Services\HostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API order activation provisioning (Api\OrderController::updateStatus).
 *
 * Mirrors the admin flow: activating a pending order for a product that
 * requires an IP (require_public_ip / require_private_ip flags) provisions
 * the hosting account and leases the address from the IPAM pool inside the
 * same transaction. An exhausted pool rolls the activation back and the API
 * answers 422 — the order stays pending.
 */
class ApiOrderActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_activation_provisions_hosting_account_and_leases_public_ip(): void
    {
        $user = $this->apiUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct([
            'name' => 'VPS Cloud',
            'require_public_ip' => true,
        ]);
        $subnet = $this->makeSubnet('public');
        $free = $this->makeIp($subnet, '10.20.0.1');
        $order = $this->makePendingOrder($customer, $product);

        $response = $this->actingAsApi($user)->putJson("/api/orders/{$order->id}/status", [
            'status' => 'active',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'active');

        $account = HostingAccount::where('order_id', $order->id)->sole();
        $this->assertSame(HostingService::STATUS_PENDING, $account->status);

        $this->assertDatabaseHas('ip_addresses', [
            'id' => $free->id,
            'assigned_to_type' => HostingAccount::class,
            'assigned_to_id' => $account->id,
        ]);
    }

    public function test_activation_succeeds_when_ip_pool_exhausted(): void
    {
        $user = $this->apiUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct([
            'name' => 'VPS Cloud',
            'require_public_ip' => true,
        ]);
        $private = $this->makeSubnet('private'); // only a private pool exists
        $this->makeIp($private, '10.21.0.1');
        $order = $this->makePendingOrder($customer, $product);

        // IP leasing is best-effort: an exhausted pool never blocks
        // activation — the order activates and the hosting account is
        // created pending, with IPs assigned later from the hosting page.
        $response = $this->actingAsApi($user)->putJson("/api/orders/{$order->id}/status", [
            'status' => 'active',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'active');

        $account = HostingAccount::where('order_id', $order->id)->sole();
        $this->assertSame(HostingService::STATUS_PENDING, $account->status);
        $this->assertSame(0, IpAddress::query()->where('assigned_to_type', HostingAccount::class)->count());
        $this->assertSame(1, OrderStatusHistory::where('order_id', $order->id)->count());
    }

    public function test_activation_of_ip_less_product_skips_provisioning(): void
    {
        $user = $this->apiUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(); // shared_hosting, no IP flags
        $order = $this->makePendingOrder($customer, $product);

        $response = $this->actingAsApi($user)->putJson("/api/orders/{$order->id}/status", [
            'status' => 'active',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'active');
        $this->assertSame(0, HostingAccount::count());
    }

    public function test_illegal_transition_returns_422(): void
    {
        $user = $this->apiUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makePendingOrder($customer, $product);

        // pending -> provisioning is not a legal edge (must go through paid);
        // OrderService rejects it and no history row is written.
        $response = $this->actingAsApi($user)->putJson("/api/orders/{$order->id}/status", [
            'status' => 'provisioning',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', "Order cannot move from 'pending' to 'provisioning'.");
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0, OrderStatusHistory::where('order_id', $order->id)->count());
    }

    public function test_activation_writes_order_status_history_via_shared_service(): void
    {
        $user = $this->apiUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makePendingOrder($customer, $product);

        $response = $this->actingAsApi($user)->putJson("/api/orders/{$order->id}/status", [
            'status' => 'active',
        ]);

        $response->assertOk();

        // The API path now writes the same per-order audit row as the admin UI.
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'from_status' => 'pending',
            'to_status' => 'active',
        ]);
    }

    public function test_store_creates_order_item_draft_invoice_and_activity(): void
    {
        $user = $this->apiUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['name' => 'API Shared Hosting']);

        $response = $this->actingAsApi($user)->postJson('/api/orders', [
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 2,
            'unit_price' => 100.00,
            'domain_name' => 'api.example.com',
        ]);

        $response->assertStatus(201);
        $this->assertSame('pending', $response->json('data.status'));

        // Order + item snapshot.
        $order = Order::sole();
        $this->assertMatchesRegularExpression('/^ORD-\d{4}-\d{5}$/', $order->order_number);
        $this->assertSame(200.00, (float) $order->total);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 100.00,
            'total' => 200.00,
        ]);

        // The API store now writes the draft invoice + activity row, matching
        // the admin UI / storefront / admin cart conventions.
        $this->assertDatabaseHas('invoices', [
            'order_id' => $order->id,
            'status' => 'draft',
            'amount' => 200.00,
        ]);
        $this->assertDatabaseHas('activity_log', [
            'customer_id' => $customer->id,
            'action' => 'order_created',
        ]);
        $this->assertSame(1, ActivityLog::where('action', 'order_created')->count());
    }

    public function test_store_writes_config_options_snapshot(): void
    {
        $user = $this->apiUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['name' => 'API Snapshot Hosting']);

        $this->actingAsApi($user)->postJson('/api/orders', [
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 100.00,
            'domain_name' => 'api.example.com',
        ])->assertStatus(201);

        // The API order path captures the product's option configuration into
        // the order item's config_options JSON (empty selection set).
        $item = OrderItem::sole();
        $this->assertIsArray($item->config_options);
        $this->assertArrayHasKey('product_group_name', $item->config_options);
        $this->assertArrayHasKey('provisioning_module', $item->config_options);
        $this->assertSame([], $item->config_options['options']);
    }

    private function apiUser(): User
    {
        // The `role` column is the authoritative source HasRoles::hasRole()
        // checks first. assignRole('admin') alone is a no-op here: RefreshDatabase
        // leaves adminlte_roles empty, so there is no admin Role row to attach.
        $user = User::factory()->create(['role' => 'admin']);
        $user->assignRole('admin');

        return $user;
    }

    private function actingAsApi(User $user): self
    {
        $token = $user->createToken('test-token')->plainTextToken;

        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ]);
    }

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Shared Hosting Basic',
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'show_in_order' => true,
            'status' => 'active',
        ], $attributes));
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'API Test Corp',
            'status' => 'active',
        ]);
    }

    private function makePendingOrder(Customer $customer, Product $product): Order
    {
        return Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 100.00,
            'status' => Order::STATUS_PENDING,
        ]);
    }

    private function makeSubnet(string $networkType = 'private'): IpSubnet
    {
        static $sequence = 0;
        $sequence++;

        return IpSubnet::create([
            'name' => "API Test Subnet {$sequence}",
            'subnet_cidr' => "10.2{$sequence}.0.0/24",
            'network_type' => $networkType,
        ]);
    }

    private function makeIp(IpSubnet $subnet, string $address): IpAddress
    {
        return IpAddress::create([
            'subnet_id' => $subnet->id,
            'ip_address' => $address,
        ]);
    }
}
