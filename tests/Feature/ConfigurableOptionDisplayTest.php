<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionGroupProduct;
use App\Models\ProductOptionLinkValue;
use App\Models\ProductOptionLinkValuePricing;
use App\Models\ProductPricing;
use App\Models\Role;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\OrderConfigSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Where a service's configurable options are shown: the ORDER, the INVOICE and
 * the SERVICE itself.
 *
 * The features are the product (8 GB RAM, 200 GB storage, priority support),
 * so wherever the customer is told what they bought or what they owe, the
 * features are named. Fixed options included — they are declared by the
 * product rather than chosen, which is precisely why they used to be invisible
 * everywhere.
 */
class ConfigurableOptionDisplayTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private ProductOptionGroupProduct $ramLink;

    private ProductOptionGroupProduct $storageLink;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'name' => 'Cloud VPS',
            'price' => 199.00,
            'billing_cycle' => 'monthly',
            'show_in_order' => true,
            'only_admin' => false,
            'status' => 'active',
        ]);

        ProductPricing::create([
            'product_id' => $this->product->id,
            'billing_cycle' => 'monthly',
            'price' => 199.00,
            'setup_fee' => 0,
        ]);

        // A FIXED feature with a unit: the customer gets 8 GB and pays 100 for
        // it, and it must read as "8 GB", never as "8".
        $ramGroup = ProductOptionGroup::create([
            'name' => 'RAM',
            'unit' => 'GB',
            'sort_order' => 1,
            'type' => 'dropdown',
        ]);

        $this->ramLink = ProductOptionGroupProduct::create([
            'product_id' => $this->product->id,
            'option_group_id' => $ramGroup->id,
            'customer_editable' => false,
            'sort_order' => 1,
        ]);

        foreach ([['8', 100.00, true], ['16', 300.00, false]] as $sort => [$label, $modifier, $default]) {
            $value = ProductOptionLinkValue::create([
                'product_option_group_product_id' => $this->ramLink->id,
                'label' => $label,
                'is_default' => $default,
                'sort_order' => $sort + 1,
            ]);

            ProductOptionLinkValuePricing::create([
                'product_option_link_value_id' => $value->id,
                'billing_cycle' => 'monthly',
                'price_modifier' => $modifier,
            ]);
        }

        // A second fixed feature whose value speaks for itself (no unit).
        $storageGroup = ProductOptionGroup::create([
            'name' => 'Backup',
            'sort_order' => 2,
            'type' => 'dropdown',
        ]);

        $this->storageLink = ProductOptionGroupProduct::create([
            'product_id' => $this->product->id,
            'option_group_id' => $storageGroup->id,
            'customer_editable' => false,
            'sort_order' => 2,
        ]);

        ProductOptionLinkValue::create([
            'product_option_group_product_id' => $this->storageLink->id,
            'label' => 'Daily off-site',
            'is_default' => true,
            'sort_order' => 1,
        ]);
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Display Corp',
            'status' => 'active',
        ]);
    }

    private function adminUser(string ...$permissions): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->assignRole('admin');

        return $user;
    }

    /**
     * An order + item carrying the snapshot, as every order-entry point writes it.
     */
    private function makeOrder(Customer $customer): Order
    {
        $order = Order::create([
            'customer_id' => $customer->id,
            'product_id' => $this->product->id,
            'order_number' => 'ORD-2026-0001',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 299.00,
            'status' => Order::STATUS_ACTIVE,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 299.00,
            'total' => 299.00,
            'config_options' => app(OrderConfigSnapshot::class)
                ->capture($this->product, null, [], 'monthly'),
        ]);

        return $order;
    }

    public function test_the_admin_order_page_names_the_fixed_features(): void
    {
        $customer = $this->makeCustomer();
        $order = $this->makeOrder($customer);

        $this->actingAs($this->adminUser('orders.view'))
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee('RAM')
            ->assertSee('8 GB')
            ->assertSee('Backup')
            ->assertSee('Daily off-site');
    }

    public function test_the_invoice_carries_and_shows_the_features(): void
    {
        $customer = $this->makeCustomer();
        $order = $this->makeOrder($customer);

        $invoice = app(BillingService::class)->createInvoiceForOrder($order);

        // The snapshot is copied onto the invoice line, so the invoice explains
        // itself without re-reading a catalog that may since have changed.
        $invoiceItem = $invoice->items()->sole();
        $this->assertIsArray($invoiceItem->config_options);
        $this->assertSame('8', $invoiceItem->config_options['options'][0]['selected']);
        $this->assertSame(100.0, (float) $invoiceItem->config_options['options'][0]['price_applied']);

        $this->actingAs($this->adminUser('invoices.view'))
            ->get(route('admin.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('RAM')
            ->assertSee('8 GB')
            ->assertSee('Daily off-site');
    }

    public function test_the_client_sees_the_features_on_their_invoice(): void
    {
        $customer = $this->makeCustomer();
        $order = $this->makeOrder($customer);

        $invoice = app(BillingService::class)->createInvoiceForOrder($order);

        $this->actingAs($customer->user)
            ->get(route('client.invoices.show', $invoice->id))
            ->assertOk()
            ->assertSee('RAM')
            ->assertSee('8 GB')
            ->assertSee('Daily off-site');
    }

    public function test_the_service_page_shows_only_the_value_in_effect(): void
    {
        $customer = $this->makeCustomer();
        $order = $this->makeOrder($customer);

        $account = HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $this->product->id,
            'order_id' => $order->id,
            'domain' => 'example.com',
            'status' => 'active',
        ]);

        $this->actingAs($customer->user)
            ->get(route('client.hosting.show', $account->id))
            ->assertOk()
            ->assertSee('Product Configuration')
            ->assertSee('8 GB')
            // The other value in the group must NOT appear: the customer has
            // 8 GB, not every value the group offers. "8, 16" is verbatim what
            // the removed live-catalog fallback printed.
            ->assertDontSee('8, 16')
            ->assertDontSee('16 GB');
    }

    public function test_a_service_without_a_snapshot_still_shows_its_fixed_features(): void
    {
        // Accounts predating the snapshot have no config_options anywhere. The
        // product's declared configuration is resolved instead - still the one
        // value in effect, not the whole list.
        $customer = $this->makeCustomer();

        $order = Order::create([
            'customer_id' => $customer->id,
            'product_id' => $this->product->id,
            'order_number' => 'ORD-2026-0002',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 299.00,
            'status' => Order::STATUS_ACTIVE,
        ]);

        $account = HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $this->product->id,
            'order_id' => $order->id,
            'domain' => 'legacy.example.com',
            'status' => 'active',
        ]);

        $this->actingAs($customer->user)
            ->get(route('client.hosting.show', $account->id))
            ->assertOk()
            ->assertSee('8 GB')
            ->assertDontSee('8, 16')
            ->assertDontSee('16 GB');
    }
}
