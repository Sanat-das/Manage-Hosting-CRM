<?php

namespace Tests\Feature;

use App\Events\OrderCreated;
use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\Invoice;
use App\Models\IpAddress;
use App\Models\IpSubnet;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Role;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\HostingService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Admin "New Order" flow coverage (the direct OrderController path).
 *
 * Mirrors AdminCartPlaceOrderTest's setup idiom (admin role + permission
 * gates). Locks in the post-consolidation behavior: creation goes through
 * OrderController::store, status changes through OrderService, and the
 * pending→active hop seeds next_billing_date + dispatches OrderCreated
 * while writing the order_status_history audit row.
 */
class AdminOrderFlowTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);

        foreach (['orders.view', 'orders.create', 'orders.edit'] as $permissionName) {
            $permission = Permission::firstOrCreate(
                ['name' => $permissionName],
                ['label' => ucwords(str_replace('.', ' ', $permissionName))]
            );
            $adminRole->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->assignRole('admin');

        return $user;
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
            'company' => 'Test Corp',
            'status' => 'active',
        ]);
    }

    private function makePendingOrder(Customer $customer, Product $product, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.date('Y').'-00001',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 100.00,
            'status' => Order::STATUS_PENDING,
        ], $overrides));
    }

    private function addLadderPrice(Product $product, float $price, string $cycle = 'monthly'): void
    {
        $product->pricing()->create([
            'billing_cycle' => $cycle,
            'price' => $price,
            'setup_fee' => 0,
        ]);
    }

    private function makeSubnet(string $networkType = 'private'): IpSubnet
    {
        static $sequence = 0;
        $sequence++;

        return IpSubnet::create([
            'name' => "Order Test Subnet {$sequence}",
            'subnet_cidr' => "10.1{$sequence}.0.0/24",
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

    // ─────────────────────────── New Order form ───────────────────────────

    public function test_create_form_lists_only_active_products(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $this->addLadderPrice($product, 100.00);
        $inactive = $this->makeProduct(['name' => 'Retired Product', 'status' => 'inactive']);

        $response = $this->actingAs($admin)->get(route('admin.orders.create'));

        $response->assertOk();
        $response->assertViewHas('products', fn ($products) => $products->contains('id', $product->id)
            && ! $products->contains('id', $inactive->id)
            && $products->firstWhere('id', $product->id)->pricing->isNotEmpty());
        $response->assertViewHas('customers', fn ($customers) => $customers->contains('id', $customer->id));

        // The enhanced form renders the line-items editor, billing controls,
        // GST-aware total preview and the inline customer modal.
        $response->assertSee('id="order-lines"', false)
            ->assertSee('id="add-line-btn"', false)
            ->assertSee('id="order-line-template"', false)
            ->assertSee('name="lines[0][product_id]"', false)
            ->assertSee('name="payment_method"', false)
            ->assertSee('id="gst-total"', false)
            ->assertSee('id="new-customer-modal"', false);
    }

    public function test_store_creates_pending_order_with_computed_total(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();

        $response = $this->actingAs($admin)->post(route('admin.orders.store'), [
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 3,
            'unit_price' => 100.50,
            'domain_name' => 'example.com',
            'notes' => 'Urgent onboarding',
        ]);

        $order = Order::sole();

        $this->assertMatchesRegularExpression('/^ORD-\d{4}-\d{5}$/', $order->order_number);
        $this->assertSame('pending', $order->status);
        $this->assertSame('monthly', $order->billing_cycle);
        $this->assertSame(3, $order->quantity);
        $this->assertSame('301.50', (string) $order->total); // server-computed: 3 × 100.50
        $this->assertSame('example.com', $order->domain_name);

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => 'Shared Hosting Basic',
            'quantity' => 3,
            'unit_price' => 100.50,
            'total' => 301.50,
        ]);

        $response->assertRedirect(route('admin.orders.show', $order));
        $response->assertSessionHas('success');
    }

    public function test_store_rejects_inactive_product(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $inactive = $this->makeProduct(['status' => 'inactive']);

        $response = $this->actingAs($admin)->post(route('admin.orders.store'), [
            'customer_id' => $customer->id,
            'product_id' => $inactive->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 100.00,
        ]);

        $response->assertSessionHasErrors('lines.0.product_id');
        $this->assertSame(0, Order::count());
    }

    public function test_store_requires_existing_customer(): void
    {
        $admin = $this->adminUser();
        $product = $this->makeProduct();

        $response = $this->actingAs($admin)->post(route('admin.orders.store'), [
            'customer_id' => 99999,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 100.00,
        ]);

        $response->assertSessionHasErrors('customer_id');
        $this->assertSame(0, Order::count());
    }

    public function test_store_rejects_inactive_customer(): void
    {
        $admin = $this->adminUser();
        $user = User::factory()->create();
        $user->assignRole('client');
        $inactive = Customer::create([
            'user_id' => $user->id,
            'company' => 'Gone Corp',
            'status' => 'inactive',
        ]);
        $product = $this->makeProduct();

        $response = $this->actingAs($admin)->post(route('admin.orders.store'), [
            'customer_id' => $inactive->id,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 100.00,
        ]);

        $response->assertSessionHasErrors('customer_id');
        $this->assertSame(0, Order::count());
    }

    public function test_store_rejects_quantity_above_shared_cap(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();

        $response = $this->actingAs($admin)->post(route('admin.orders.store'), [
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'quantity' => Order::MAX_QUANTITY + 1,
            'unit_price' => 100.00,
        ]);

        $response->assertSessionHasErrors('lines.0.quantity');
        $this->assertSame(0, Order::count());
    }

    public function test_store_writes_creation_audit_row(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['require_domain' => false]);

        $response = $this->actingAs($admin)->post(route('admin.orders.store'), [
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 100.00,
        ]);

        $order = Order::sole();

        $this->assertDatabaseHas('activity_log', [
            'customer_id' => $customer->id,
            'action' => 'order_created',
        ]);

        // The order identity rides in the JSON metadata column.
        $row = DB::table('activity_log')->where('action', 'order_created')->first();
        $this->assertStringContainsString('"order_id":'.$order->id, (string) $row->metadata);

        $response->assertRedirect(route('admin.orders.show', $order));
    }

    public function test_store_writes_config_options_snapshot(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['require_domain' => false]);

        $this->actingAs($admin)->post(route('admin.orders.store'), [
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 100.00,
        ]);

        // The manual order path captures the product's option configuration
        // into the order item's config_options JSON (empty selection set).
        $item = OrderItem::sole();
        $this->assertIsArray($item->config_options);
        $this->assertArrayHasKey('product_group_name', $item->config_options);
        $this->assertArrayHasKey('provisioning_module', $item->config_options);
        $this->assertSame([], $item->config_options['options']);
    }

    public function test_store_creates_draft_invoice_with_line_item(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['require_domain' => false]);

        $response = $this->actingAs($admin)->post(route('admin.orders.store'), [
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 2,
            'unit_price' => 100.50,
            // The form's checkbox — unticked means no invoice.
            'generate_invoice' => 1,
        ]);

        $order = Order::sole();
        $invoice = Invoice::sole();

        $this->assertSame('draft', $invoice->status);
        $this->assertSame($order->id, $invoice->order_id);
        $this->assertSame($customer->id, $invoice->customer_id);
        $this->assertSame('201.00', (string) $invoice->total); // 2 × 100.50
        $this->assertDatabaseHas('invoice_items', [
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'description' => 'Shared Hosting Basic — Monthly',
            'quantity' => 2,
            'unit_price' => 100.50,
            'total' => 201.00,
        ]);

        $response->assertRedirect(route('admin.orders.show', $order));
    }

    public function test_invoice_is_not_created_when_order_fails_validation(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['require_domain' => true]);

        $this->actingAs($admin)->post(route('admin.orders.store'), [
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 100.00,
        ])->assertSessionHasErrors('lines.0.domain_name');

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Invoice::count());
    }

    // ─────────────────── Catalog pricing + domain rules ───────────────────

    public function test_store_matches_catalog_price_for_selected_cycle(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['require_domain' => false]);
        $this->addLadderPrice($product, 100.00, 'monthly');
        $this->addLadderPrice($product, 1000.00, 'annual');

        $response = $this->actingAs($admin)->post(route('admin.orders.store'), [
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'billing_cycle' => 'annual',
            'quantity' => 1,
            'unit_price' => 1000.00,
        ]);

        $response->assertRedirect(route('admin.orders.show', Order::sole()));
        $response->assertSessionHas('success');
    }

    public function test_store_rejects_mismatched_catalog_price(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['require_domain' => false]);
        $this->addLadderPrice($product, 100.00, 'monthly');
        $this->addLadderPrice($product, 1000.00, 'annual');

        // Annual cycle charged the monthly default — the exact misprice the
        // catalog guard exists to stop.
        $response = $this->actingAs($admin)->post(route('admin.orders.store'), [
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'billing_cycle' => 'annual',
            'quantity' => 1,
            'unit_price' => 100.00,
        ]);

        $response->assertSessionHasErrors('lines.0.unit_price');
        $this->assertSame(0, Order::count());
    }

    public function test_store_allows_override_price_with_flag(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['require_domain' => false]);
        $this->addLadderPrice($product, 100.00, 'monthly');
        $this->addLadderPrice($product, 1000.00, 'annual');

        $response = $this->actingAs($admin)->post(route('admin.orders.store'), [
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'billing_cycle' => 'annual',
            'quantity' => 1,
            'unit_price' => 100.00,
            'price_override' => 1,
        ]);

        $response->assertRedirect(route('admin.orders.show', Order::sole()));
        $this->assertSame('100.00', (string) Order::sole()->total);
    }

    public function test_store_accepts_any_price_when_no_ladder_row(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['require_domain' => false]); // no product_pricing rows — legacy

        $response = $this->actingAs($admin)->post(route('admin.orders.store'), [
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'billing_cycle' => 'annual',
            'quantity' => 1,
            'unit_price' => 777.00,
        ]);

        $response->assertRedirect(route('admin.orders.show', Order::sole()));
        $this->assertSame('777.00', (string) Order::sole()->total);
    }

    public function test_domain_required_when_product_requires_domain(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['require_domain' => true]);

        $response = $this->actingAs($admin)->post(route('admin.orders.store'), [
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 100.00,
        ]);

        $response->assertSessionHasErrors('lines.0.domain_name');
        $this->assertSame(0, Order::count());
    }

    public function test_domain_format_validated(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();

        // Single label (no dot) is not a hostname.
        $this->actingAs($admin)->post(route('admin.orders.store'), [
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 100.00,
            'domain_name' => 'not-a-domain',
        ])->assertSessionHasErrors('lines.0.domain_name');

        $this->assertSame(0, Order::count());

        // A real hostname passes, case-insensitively.
        $this->actingAs($admin)->post(route('admin.orders.store'), [
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 100.00,
            'domain_name' => 'Example.COM',
        ])->assertRedirect(route('admin.orders.show', Order::sole()));

        $this->assertSame('Example.COM', Order::sole()->domain_name);
    }

    // ─────────────── Per-product order-form option rules ───────────────

    public function test_store_rejects_cycle_not_offered_by_product_ladder(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['require_domain' => false]);
        $this->addLadderPrice($product, 100.00, 'monthly');
        $this->addLadderPrice($product, 1000.00, 'annual');

        // quarterly is in the order vocabulary but outside this product's ladder.
        $response = $this->actingAs($admin)->post(route('admin.orders.store'), [
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'billing_cycle' => 'quarterly',
            'quantity' => 1,
            'unit_price' => 300.00,
        ]);

        $response->assertSessionHasErrors('lines.0.billing_cycle');
        $this->assertSame(0, Order::count());
    }

    public function test_store_accepts_cycle_offered_by_product_ladder(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['require_domain' => false]);
        $this->addLadderPrice($product, 100.00, 'monthly');
        $this->addLadderPrice($product, 1000.00, 'annual');

        $response = $this->actingAs($admin)->post(route('admin.orders.store'), [
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 100.00,
        ]);

        $response->assertRedirect(route('admin.orders.show', Order::sole()));
        $this->assertSame('monthly', Order::sole()->billing_cycle);
    }

    public function test_store_keeps_any_cycle_for_ladderless_legacy_product(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['require_domain' => false]); // no product_pricing rows

        $response = $this->actingAs($admin)->post(route('admin.orders.store'), [
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'billing_cycle' => 'semi_annual',
            'quantity' => 1,
            'unit_price' => 400.00,
        ]);

        $response->assertRedirect(route('admin.orders.show', Order::sole()));
        $this->assertSame('semi_annual', Order::sole()->billing_cycle);
    }

    public function test_store_rejects_quantity_above_one_for_single_unit_product(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['require_domain' => false, 'quantity_behaviour' => 'none']);

        $response = $this->actingAs($admin)->post(route('admin.orders.store'), [
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 2,
            'unit_price' => 100.00,
        ]);

        $response->assertSessionHasErrors('lines.0.quantity');
        $this->assertSame(0, Order::count());
    }

    public function test_store_accepts_single_quantity_for_single_unit_product(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['require_domain' => false, 'quantity_behaviour' => 'none']);

        $response = $this->actingAs($admin)->post(route('admin.orders.store'), [
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 100.00,
        ]);

        $response->assertRedirect(route('admin.orders.show', Order::sole()));
        $this->assertSame(1, Order::sole()->quantity);
    }

    public function test_store_does_not_ask_for_ips_even_when_product_requires_them(): void
    {
        // IP capture moved off the order form: the flags live on the product
        // edit page and the lease happens at activation. An IP-requiring
        // product must order with NO ip fields submitted at all.
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct([
            'require_domain' => false,
            'require_public_ip' => true,
            'require_private_ip' => true,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.orders.store'), [
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 100.00,
        ]);

        $response->assertRedirect(route('admin.orders.show', Order::sole()));
        $this->assertSame(1, Order::count());
    }

    // ─────────────────────────── Status workflow ───────────────────────────

    public function test_activation_seeds_billing_date_and_writes_history(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makePendingOrder($customer, $product);

        $response = $this->actingAs($admin)->put(route('admin.orders.status', $order), ['status' => 'active']);

        $fresh = $order->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertNotNull($fresh->next_billing_date);
        $this->assertTrue($fresh->next_billing_date->isSameDay(now()->addMonth()));

        // Authoritative per-order audit row (OrderService).
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'from_status' => 'pending',
            'to_status' => 'active',
            'changed_by_user_id' => $admin->id,
        ]);

        // Customer-facing trail entry still written by the controller.
        $this->assertDatabaseHas('activity_log', [
            'customer_id' => $customer->id,
            'action' => 'order_status_changed',
        ]);

        $response->assertRedirect(route('admin.orders.show', $order));
        $response->assertSessionHas('success');
    }

    public function test_activation_dispatches_order_created(): void
    {
        Event::fake([OrderCreated::class]);

        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makePendingOrder($customer, $product);

        $this->actingAs($admin)->put(route('admin.orders.status', $order), ['status' => 'active']);

        Event::assertDispatched(OrderCreated::class, fn (OrderCreated $event) => $event->order->is($order));
    }

    public function test_one_time_activation_has_no_next_billing_date(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makePendingOrder($customer, $product, ['billing_cycle' => 'one_time']);

        $this->actingAs($admin)->put(route('admin.orders.status', $order), ['status' => 'active']);

        $fresh = $order->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertNull($fresh->next_billing_date);
    }

    // ─────────────────────────── Order provisioning ───────────────────────────

    public function test_activation_provisions_hosting_account_and_leases_public_ip(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct([
            'name' => 'VPS Cloud',
            'require_public_ip' => true,
        ]);
        $subnet = $this->makeSubnet('public');
        $free = $this->makeIp($subnet, '10.9.0.1');
        $order = $this->makePendingOrder($customer, $product);

        $this->actingAs($admin)->put(route('admin.orders.status', $order), ['status' => 'active']);

        // Order activated, provisioning side-effect ran atomically.
        $this->assertSame('active', $order->fresh()->status);

        $account = HostingAccount::where('order_id', $order->id)->sole();
        $this->assertSame(HostingService::STATUS_PENDING, $account->status);
        $this->assertSame($customer->id, $account->customer_id);

        // The lease comes from the IPAM pool, not the order form.
        $this->assertDatabaseHas('ip_addresses', [
            'id' => $free->id,
            'assigned_to_type' => HostingAccount::class,
            'assigned_to_id' => $account->id,
        ]);
    }

    public function test_activation_succeeds_when_ip_pool_exhausted(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct([
            'name' => 'VPS Cloud',
            'require_public_ip' => true,
        ]);
        $private = $this->makeSubnet('private'); // only a private pool exists
        $this->makeIp($private, '10.10.0.1');
        $order = $this->makePendingOrder($customer, $product);

        // IP leasing is best-effort: an exhausted pool never rolls the
        // activation back — the order activates and the hosting account is
        // created pending, with IPs assigned later from the hosting page.
        $response = $this->actingAs($admin)->put(route('admin.orders.status', $order), ['status' => 'active']);

        $response->assertSessionHas('success');
        $this->assertSame('active', $order->fresh()->status);
        $this->assertSame(1, HostingAccount::count(), 'the hosting account is still created');
        $this->assertSame(HostingService::STATUS_PENDING, HostingAccount::sole()->status);
        $this->assertSame(0, IpAddress::query()->where('assigned_to_type', HostingAccount::class)->count());
        $this->assertSame(1, OrderStatusHistory::where('order_id', $order->id)->count());
    }

    public function test_illegal_transition_rejected_without_audit_row(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makePendingOrder($customer, $product);

        // Cancel is legal from pending; re-activating a cancelled order is not.
        $this->actingAs($admin)->put(route('admin.orders.status', $order), ['status' => 'cancelled']);
        $this->assertSame('cancelled', $order->fresh()->status);

        $response = $this->actingAs($admin)->put(route('admin.orders.status', $order), ['status' => 'active']);

        $response->assertSessionHasErrors('status');
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(1, OrderStatusHistory::where('order_id', $order->id)->count());
    }

    public function test_show_page_displays_status_history(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makePendingOrder($customer, $product);

        $this->actingAs($admin)->put(route('admin.orders.status', $order), ['status' => 'active']);

        $response = $this->actingAs($admin)->get(route('admin.orders.show', $order));

        $response->assertOk();
        $response->assertSee('pending');
        $response->assertSee('active');
    }

    // ─────────────────── Post-payment provisioning flow ───────────────────

    private function makeLinkedInvoice(Order $order): Invoice
    {
        return Invoice::create([
            'customer_id' => $order->customer_id,
            'order_id' => $order->id,
            'invoice_no' => 'INV-2026-'.random_int(10000, 99999),
            'amount' => 100.00,
            'tax' => 0,
            'total' => 100.00,
            'status' => 'sent',
            'due_date' => now()->addDays(7),
        ]);
    }

    public function test_payment_moves_manual_module_order_to_provisioning(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['provisioning_module' => 'manual']);
        $order = $this->makePendingOrder($customer, $product);
        $invoice = $this->makeLinkedInvoice($order);

        app(BillingService::class)->recordPayment($invoice->id, 100.0, 'bank_transfer');

        // Paid invoice → order pending → paid → provisioning (awaits admin).
        $this->assertSame('provisioning', $order->fresh()->status);
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'from_status' => 'pending',
            'to_status' => 'paid',
        ]);
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'from_status' => 'paid',
            'to_status' => 'provisioning',
        ]);
    }

    public function test_payment_auto_provisions_automated_module_order(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct([
            'provisioning_module' => 'cpanel',
            'name' => 'cPanel Hosting',
            'require_public_ip' => true,
        ]);
        $subnet = $this->makeSubnet('public');
        $free = $this->makeIp($subnet, '10.9.0.1');
        $order = $this->makePendingOrder($customer, $product);
        $invoice = $this->makeLinkedInvoice($order);

        app(BillingService::class)->recordPayment($invoice->id, 100.0, 'bank_transfer');

        $fresh = $order->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertNotNull($fresh->next_billing_date);

        $account = HostingAccount::where('order_id', $order->id)->sole();
        $this->assertSame($customer->id, $account->customer_id);
        $this->assertDatabaseHas('ip_addresses', [
            'id' => $free->id,
            'assigned_to_type' => HostingAccount::class,
            'assigned_to_id' => $account->id,
        ]);

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'from_status' => 'provisioning',
            'to_status' => 'active',
        ]);
    }

    public function test_payment_auto_provisions_when_ip_pool_exhausted(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct([
            'provisioning_module' => 'cpanel',
            'name' => 'VPS Auto',
            'require_public_ip' => true,
        ]);
        $private = $this->makeSubnet('private'); // only a private pool exists
        $this->makeIp($private, '10.10.0.1');
        $order = $this->makePendingOrder($customer, $product);
        $invoice = $this->makeLinkedInvoice($order);

        // IP leasing is best-effort: auto-provisioning with an exhausted
        // public pool still activates the order — the hosting account is
        // created pending and the IP is assigned later from the hosting page.
        app(BillingService::class)->recordPayment($invoice->id, 100.0, 'bank_transfer');

        $fresh = $order->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertSame(1, HostingAccount::count());
        $this->assertSame(HostingService::STATUS_PENDING, HostingAccount::sole()->status);
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'from_status' => 'provisioning',
            'to_status' => 'active',
        ]);
    }

    public function test_payment_auto_provisions_ip_less_hosting_product(): void
    {
        $customer = $this->makeCustomer();
        $group = ProductGroup::create([
            'name' => 'Shared Hosting',
            'slug' => 'shared-hosting',
            'is_hosting' => true,
            'status' => 'active',
        ]);
        // Plesk shared-hosting product WITHOUT any IP flags — the classic
        // case where the service used to vanish from the list entirely
        // because provisionFromOrder gated account creation on requiresIp().
        $product = $this->makeProduct([
            'provisioning_module' => 'plesk',
            'name' => 'Plesk Shared Hosting',
            'product_group_id' => $group->id,
        ]);
        $order = $this->makePendingOrder($customer, $product);
        $invoice = $this->makeLinkedInvoice($order);

        app(BillingService::class)->recordPayment($invoice->id, 100.0, 'bank_transfer');

        $fresh = $order->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertSame(1, HostingAccount::count());
        $account = HostingAccount::sole();
        $this->assertSame($customer->id, $account->customer_id);
        $this->assertSame($order->id, $account->order_id);
        $this->assertSame(HostingService::STATUS_PENDING, $account->status);
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'from_status' => 'provisioning',
            'to_status' => 'active',
        ]);
    }

    public function test_failed_order_offers_retry_provisioning(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct([
            'provisioning_module' => 'plesk',
            'name' => 'Plesk Shared Hosting',
            'require_public_ip' => true,
        ]);
        $order = $this->makePendingOrder($customer, $product);

        // Drive the order to the state an auto-provisioning failure leaves
        // it in: paid -> provisioning -> failed (the failed activation
        // rolled back, so no hosting account exists yet).
        app(OrderService::class)->markPaid($order);
        app(OrderService::class)->markProvisioning($order->fresh());
        app(OrderService::class)->fail($order->fresh());
        $this->assertSame('failed', $order->fresh()->status);
        $this->assertSame(0, HostingAccount::count());

        // The failed order page must offer the manual retry.
        $this->actingAs($this->adminUser())
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee('Retry Provisioning');

        // Retrying re-runs the activation: billing seeded + account created.
        $this->actingAs($this->adminUser())
            ->put(route('admin.orders.status', $order), ['status' => 'active'])
            ->assertRedirect(route('admin.orders.show', $order));

        $fresh = $order->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertNotNull($fresh->next_billing_date);
        $this->assertSame(1, HostingAccount::count());
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'from_status' => 'failed',
            'to_status' => 'active',
        ]);
    }
}
