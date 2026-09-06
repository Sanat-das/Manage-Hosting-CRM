<?php

namespace Tests\Feature;

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
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionGroupProduct;
use App\Models\ProductOptionLinkPricing;
use App\Models\ProductOptionLinkValue;
use App\Models\ProductOptionLinkValuePricing;
use App\Models\ProductOptionPricing;
use App\Models\ProductOptionValue;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin New Order enhancements: multi-product lines, per-line configurable
 * options, create-as-active (with provisioning), billing controls and the
 * inline customer creation endpoint.
 */
class AdminOrderEnhancementTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);

        foreach (['orders.view', 'orders.create', 'orders.edit', 'customers.create'] as $permissionName) {
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

    private function makeSubnet(string $networkType = 'private'): IpSubnet
    {
        static $sequence = 0;
        $sequence++;

        return IpSubnet::create([
            'name' => "Enhancement Test Subnet {$sequence}",
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

    // ─────────────────────────── Multi-line orders ───────────────────────────

    public function test_store_creates_multiline_order_with_per_line_items(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $productA = $this->makeProduct(['name' => 'Shared Hosting']);
        $productB = $this->makeProduct(['name' => 'Backup Addon', 'require_domain' => false]);

        $response = $this->actingAs($admin)->post(route('admin.orders.store'), [
            'customer_id' => $customer->id,
            'domain_name' => 'example.com',
            'payment_method' => 'razorpay',
            'status' => 'pending',
            // The form's checkbox — unticked means no invoice.
            'generate_invoice' => 1,
            'lines' => [
                ['product_id' => $productA->id, 'billing_cycle' => 'monthly', 'quantity' => 2, 'unit_price' => 199.00],
                ['product_id' => $productB->id, 'billing_cycle' => 'annual', 'quantity' => 1, 'unit_price' => 500.00],
            ],
        ]);

        $order = Order::sole();

        // The first line is the order's primary product.
        $this->assertSame($productA->id, $order->product_id);
        $this->assertSame('monthly', $order->billing_cycle);
        $this->assertSame('898.00', (string) $order->total); // 2 × 199 + 1 × 500
        $this->assertSame('razorpay', $order->payment_method);
        $this->assertSame(2, $order->items()->count());

        // Every line became an order_item with its own cycle + quantity.
        $items = $order->items()->orderBy('id')->get();
        $this->assertSame($productA->id, $items[0]->product_id);
        $this->assertSame('monthly', $items[0]->billing_cycle);
        $this->assertSame(2, $items[0]->quantity);
        $this->assertSame('398.00', (string) $items[0]->total);

        $this->assertSame($productB->id, $items[1]->product_id);
        $this->assertSame('annual', $items[1]->billing_cycle);
        $this->assertSame(1, $items[1]->quantity);
        $this->assertSame('500.00', (string) $items[1]->total);

        // The draft invoice carries one line per order item.
        $invoice = Invoice::sole();
        $this->assertSame(2, $invoice->items()->count());
        $this->assertSame('898.00', (string) $invoice->total);

        $response->assertRedirect(route('admin.orders.show', $order));
        $response->assertSessionHas('success');
    }

    public function test_store_captures_option_selections_per_line(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();

        // Editable dropdown link with two values (mirrors the storefront's
        // per-type controls on the order form).
        $group = ProductOptionGroup::create([
            'name' => 'Support Level',
            'sort_order' => 1,
            'type' => 'dropdown',
        ]);

        $link = ProductOptionGroupProduct::create([
            'product_id' => $product->id,
            'option_group_id' => $group->id,
            'customer_editable' => true,
        ]);

        foreach (['Standard Support', 'Priority Support'] as $index => $label) {
            $value = ProductOptionValue::create([
                'option_group_id' => $group->id,
                'label' => $label,
                'sort_order' => $index + 1,
            ]);

            ProductOptionPricing::create([
                'option_value_id' => $value->id,
                'billing_cycle' => 'monthly',
                'price_modifier' => $index === 0 ? 0 : 149,
            ]);

            ProductOptionLinkValue::create([
                'product_option_group_product_id' => $link->id,
                'label' => $label,
                'is_default' => $index === 0,
                'sort_order' => $index + 1,
            ]);

            ProductOptionLinkValuePricing::create([
                'product_option_link_value_id' => $value->id,
                'billing_cycle' => 'monthly',
                'price_modifier' => $index === 0 ? 0 : 149,
            ]);
        }

        $this->actingAs($admin)->post(route('admin.orders.store'), [
            'customer_id' => $customer->id,
            'domain_name' => 'example.com',
            'lines' => [
                [
                    'product_id' => $product->id,
                    'billing_cycle' => 'monthly',
                    'quantity' => 1,
                    'unit_price' => 100.00,
                    'options' => [$link->id => 'Priority Support'],
                ],
            ],
        ]);

        $order = Order::sole();
        $item = $order->items()->firstOrFail();
        $snapshot = $item->config_options; // cast to array by the model

        $this->assertSame('249.00', (string) $order->total, 'The option modifier is charged: 100 + 149.');
        $this->assertSame('249.00', (string) $item->unit_price);
        $this->assertSame('Priority Support', $snapshot['options'][0]['selected'], 'The line selection is captured in the order-item snapshot.');
        $this->assertSame(['Standard Support', 'Priority Support'], $snapshot['options'][0]['values']);
    }

    public function test_store_charges_continuous_option_selection_value_times_unit_price(): void
    {
        // Continuous links (slider / number / quantity) are priced per unit:
        // the entered value multiplies the link's per-cycle unit price.
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();

        $group = ProductOptionGroup::create([
            'name' => 'CPU Cores',
            'sort_order' => 1,
            'type' => 'number',
            'input_min' => 1,
            'input_max' => 10,
            'input_step' => 1,
        ]);

        $link = ProductOptionGroupProduct::create([
            'product_id' => $product->id,
            'option_group_id' => $group->id,
            'customer_editable' => true,
        ]);

        ProductOptionLinkPricing::create([
            'product_option_group_product_id' => $link->id,
            'billing_cycle' => 'monthly',
            'price_modifier' => 100.00, // ₹100 per unit
        ]);

        $this->actingAs($admin)->post(route('admin.orders.store'), [
            'customer_id' => $customer->id,
            'domain_name' => 'example.com',
            'lines' => [
                [
                    'product_id' => $product->id,
                    'billing_cycle' => 'monthly',
                    'quantity' => 1,
                    'unit_price' => 200.00,
                    'options' => [$link->id => 5], // 5 units × ₹100
                ],
            ],
        ]);

        $order = Order::sole();
        $item = $order->items()->firstOrFail();

        $this->assertSame('700.00', (string) $order->total, 'The continuous option charges 200 + 5 × 100 = 700.');
        $this->assertSame('700.00', (string) $item->unit_price);
        $this->assertSame(5, $item->config_options['options'][0]['selected']);
    }

    public function test_store_charges_decimal_continuous_option_value_with_rounding(): void
    {
        // Step-driven number options accept decimal values: the per-unit
        // charge is value × unit price, rounded to 2dp. 2.5 × ₹99.99 =
        // ₹249.975 → ₹249.98, on the ₹100.00 base → ₹349.98.
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();

        $group = ProductOptionGroup::create([
            'name' => 'Storage (TB)',
            'sort_order' => 1,
            'type' => 'number',
            'input_min' => 0.5,
            'input_max' => 10,
            'input_step' => 0.5,
        ]);

        $link = ProductOptionGroupProduct::create([
            'product_id' => $product->id,
            'option_group_id' => $group->id,
            'customer_editable' => true,
        ]);

        ProductOptionLinkPricing::create([
            'product_option_group_product_id' => $link->id,
            'billing_cycle' => 'monthly',
            'price_modifier' => 99.99, // ₹99.99 per unit
        ]);

        $this->actingAs($admin)->post(route('admin.orders.store'), [
            'customer_id' => $customer->id,
            'domain_name' => 'example.com',
            'lines' => [
                [
                    'product_id' => $product->id,
                    'billing_cycle' => 'monthly',
                    'quantity' => 1,
                    'unit_price' => 100.00,
                    'options' => [$link->id => 2.5],
                ],
            ],
        ]);

        $order = Order::sole();
        $item = $order->items()->firstOrFail();

        $this->assertSame('349.98', (string) $order->total, '2.5 × 99.99 = 249.975 rounds to 249.98 on the 100.00 base.');
        $this->assertSame('349.98', (string) $item->unit_price);
        $this->assertSame(2.5, (float) $item->config_options['options'][0]['selected']);
    }

    public function test_store_ignores_informational_option_pricing(): void
    {
        // Informational links are display-only: even a crafted payload that
        // submits a selection for them must NOT change the charged price, and
        // the selection is dropped from the snapshot (selected = null).
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();

        $group = ProductOptionGroup::create([
            'name' => 'Datacenter',
            'sort_order' => 1,
            'type' => 'dropdown',
        ]);

        $link = ProductOptionGroupProduct::create([
            'product_id' => $product->id,
            'option_group_id' => $group->id,
            'customer_editable' => false,
        ]);

        foreach ([['Mumbai', 100.0], ['Delhi', 200.0]] as $index => [$label, $modifier]) {
            $value = ProductOptionValue::create([
                'option_group_id' => $group->id,
                'label' => $label,
                'sort_order' => $index + 1,
            ]);

            ProductOptionPricing::create([
                'option_value_id' => $value->id,
                'billing_cycle' => 'monthly',
                'price_modifier' => $modifier,
            ]);

            ProductOptionLinkValue::create([
                'product_option_group_product_id' => $link->id,
                'label' => $label,
                'is_default' => $index === 0,
                'sort_order' => $index + 1,
            ]);

            ProductOptionLinkValuePricing::create([
                'product_option_link_value_id' => $value->id,
                'billing_cycle' => 'monthly',
                'price_modifier' => $modifier,
            ]);
        }

        $this->actingAs($admin)->post(route('admin.orders.store'), [
            'customer_id' => $customer->id,
            'domain_name' => 'example.com',
            'lines' => [
                [
                    'product_id' => $product->id,
                    'billing_cycle' => 'monthly',
                    'quantity' => 1,
                    'unit_price' => 100.00,
                    // A crafted selection for the informational link — the
                    // value carries a +200 modifier that must be ignored.
                    'options' => [$link->id => 'Delhi'],
                ],
            ],
        ]);

        $order = Order::sole();
        $item = $order->items()->firstOrFail();

        $this->assertSame('100.00', (string) $order->total, 'Informational options never charge.');
        $this->assertSame('100.00', (string) $item->unit_price);
        $this->assertNull($item->config_options['options'][0]['selected'], 'The informational selection is dropped from the snapshot.');
    }

    public function test_store_captures_domain_and_billing_state_per_line(): void
    {
        // Gap 3: each line's domain is captured on its order_item, and each
        // item carries its own recurring-billing snapshot (cycle limit +
        // counter) so renewals run per service, WHMCS-style.
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $productA = $this->makeProduct(['name' => 'Shared Hosting', 'require_domain' => false]);
        $productB = $this->makeProduct(['name' => 'Backup Addon', 'require_domain' => true, 'recurring_cycles_limit' => 12]);

        $this->actingAs($admin)->post(route('admin.orders.store'), [
            'customer_id' => $customer->id,
            'lines' => [
                ['product_id' => $productA->id, 'billing_cycle' => 'monthly', 'quantity' => 1, 'unit_price' => 100.00],
                ['product_id' => $productB->id, 'billing_cycle' => 'annual', 'quantity' => 1, 'unit_price' => 500.00, 'domain_name' => 'backup.example.com'],
            ],
        ]);

        $order = Order::sole();
        $this->assertNull($order->domain_name, 'Line 0 has no domain, so the order has none.');

        $items = $order->items()->orderBy('id')->get();
        $this->assertNull($items[0]->domain_name);
        $this->assertSame('backup.example.com', $items[1]->domain_name, 'The secondary line\'s domain is captured on its item.');

        // Per-item billing state: both are recurring → initial invoice counts
        // as cycle 1; the snapshot limit comes from the product.
        $this->assertSame(1, $items[0]->billing_cycles_count);
        $this->assertSame(0, $items[0]->recurring_cycles_limit);
        $this->assertSame(1, $items[1]->billing_cycles_count);
        $this->assertSame(12, $items[1]->recurring_cycles_limit);
    }

    public function test_store_requires_domain_for_secondary_line_product(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $productA = $this->makeProduct(['name' => 'Shared Hosting', 'require_domain' => false]);
        $productB = $this->makeProduct(['name' => 'Backup Addon', 'require_domain' => true]);

        $this->actingAs($admin)->post(route('admin.orders.store'), [
            'customer_id' => $customer->id,
            'lines' => [
                ['product_id' => $productA->id, 'billing_cycle' => 'monthly', 'quantity' => 1, 'unit_price' => 100.00],
                ['product_id' => $productB->id, 'billing_cycle' => 'monthly', 'quantity' => 1, 'unit_price' => 100.00],
            ],
        ])->assertSessionHasErrors('lines.1.domain_name');

        $this->assertSame(0, Order::count());
    }

    public function test_order_form_embeds_customer_editable_flags_for_option_links(): void
    {
        // Gap 1 (data side): the embedded product map marks each option link
        // as customer-editable or informational, so the renderer shows
        // display-only text for informational links instead of controls.
        $admin = $this->adminUser();
        $product = $this->makeProduct(['require_domain' => false]);

        $editableGroup = ProductOptionGroup::create(['name' => 'Support Level', 'sort_order' => 1, 'type' => 'dropdown']);
        ProductOptionGroupProduct::create([
            'product_id' => $product->id,
            'option_group_id' => $editableGroup->id,
            'customer_editable' => true,
        ]);

        $infoGroup = ProductOptionGroup::create(['name' => 'Datacenter', 'sort_order' => 2, 'type' => 'dropdown']);
        ProductOptionGroupProduct::create([
            'product_id' => $product->id,
            'option_group_id' => $infoGroup->id,
            'customer_editable' => false,
        ]);

        $this->actingAs($admin)->get(route('admin.orders.create'))
            ->assertOk()
            ->assertSee('"name":"Support Level","type":"dropdown","customerEditable":true', false)
            ->assertSee('"name":"Datacenter","type":"dropdown","customerEditable":false', false);
    }

    public function test_order_show_page_surfaces_payment_method_and_per_item_details(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['require_domain' => false]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 100.00,
            'status' => Order::STATUS_PENDING,
            'payment_method' => 'razorpay',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'billing_cycle' => 'monthly',
            'domain_name' => 'site.example.com',
            'quantity' => 1,
            'unit_price' => 100.00,
            'total' => 100.00,
            'config_options' => ['options' => [
                ['group' => 'Support Level', 'selected' => 'Priority Support'],
            ]],
        ]);

        $this->actingAs($admin)->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee('Razorpay')                       // payment method
            ->assertSee('site.example.com')               // per-item domain
            ->assertSee('Support Level: Priority Support') // captured option selection
            ->assertSee('Monthly');
    }

    public function test_order_form_embeds_submitted_options_for_restoration(): void
    {
        // On a validation-error re-render (the closest thing to editing an
        // order), the submitted option selections must be embedded on the line
        // so the JS restores them — and the checkbox cap applies to the
        // restored (pre-checked) options.
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();

        $group = ProductOptionGroup::create(['name' => 'Extras', 'sort_order' => 1, 'type' => 'checkbox', 'input_max' => 2]);
        $link = ProductOptionGroupProduct::create([
            'product_id' => $product->id,
            'option_group_id' => $group->id,
            'customer_editable' => true,
        ]);

        foreach (['Backup', 'SSL'] as $index => $label) {
            ProductOptionValue::create(['option_group_id' => $group->id, 'label' => $label, 'sort_order' => $index + 1]);
            ProductOptionLinkValue::create([
                'product_option_group_product_id' => $link->id,
                'label' => $label,
                'is_default' => false,
                'sort_order' => $index + 1,
            ]);
        }

        // The product requires a domain; omitting it fails validation.
        $this->actingAs($admin)
            ->from(route('admin.orders.create'))
            ->post(route('admin.orders.store'), [
                'customer_id' => $customer->id,
                'lines' => [[
                    'product_id' => $product->id,
                    'billing_cycle' => 'monthly',
                    'quantity' => 1,
                    'unit_price' => 100.00,
                    'options' => [$link->id => ['Backup', 'SSL']],
                ]],
            ])
            ->assertSessionHasErrors('lines.0.domain_name');

        // The re-rendered create page carries the submitted selections in the
        // line's data attribute for JS restoration (quotes HTML-escaped).
        $this->actingAs($admin)
            ->get(route('admin.orders.create'))
            ->assertOk()
            ->assertSee('data-submitted-options', false)
            ->assertSee('&quot;Backup&quot;,&quot;SSL&quot;', false);
    }

    // ─────────────────────────── Create as Active ───────────────────────────
    public function test_store_creates_order_as_active_and_provisions(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['require_public_ip' => true]);
        $subnet = $this->makeSubnet('public');
        $this->makeIp($subnet, '203.0.113.10');

        $response = $this->actingAs($admin)->post(route('admin.orders.store'), [
            'customer_id' => $customer->id,
            'domain_name' => 'example.com',
            'status' => 'active',
            'lines' => [
                ['product_id' => $product->id, 'billing_cycle' => 'monthly', 'quantity' => 1, 'unit_price' => 100.00],
            ],
        ]);

        $order = Order::sole();
        $this->assertSame('active', $order->status);
        $this->assertNotNull($order->next_billing_date, 'Activation seeds the recurring schedule.');
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'from_status' => 'pending',
            'to_status' => 'active',
        ]);

        // Provisioning ran inside the same transaction: hosting account
        // created and the public IP leased to it.
        $this->assertDatabaseHas('hosting_accounts', ['order_id' => $order->id]);
        $this->assertDatabaseHas('ip_addresses', [
            'ip_address' => '203.0.113.10',
            'assigned_to_type' => HostingAccount::class,
        ]);

        $response->assertRedirect(route('admin.orders.show', $order));
    }

    public function test_store_active_order_succeeds_when_ip_pool_is_exhausted(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['require_public_ip' => true]);

        // No IP available in the pool — IP leasing is best-effort, so the
        // order still creates as active; the hosting account is created
        // pending and the IP is assigned later from the hosting page.
        $this->actingAs($admin)->from(route('admin.orders.create'))
            ->post(route('admin.orders.store'), [
                'customer_id' => $customer->id,
                'domain_name' => 'example.com',
                'status' => 'active',
                'lines' => [
                    ['product_id' => $product->id, 'billing_cycle' => 'monthly', 'quantity' => 1, 'unit_price' => 100.00],
                ],
            ]);

        $this->assertSame(1, Order::count(), 'Order creation succeeds even with an exhausted IP pool.');
        $this->assertSame('active', Order::sole()->status);
        $this->assertDatabaseHas('hosting_accounts', ['order_id' => Order::sole()->id]);
    }

    // ─────────────────────────── Inline customer ───────────────────────────

    public function test_quick_store_creates_customer_and_returns_json(): void
    {
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)->postJson(route('admin.customers.quick-store'), [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane.doe@example.com',
            'password' => 'Password123',
            'phone' => '+1-555-0100',
            'company' => 'Acme Hosting',
        ]);

        $response->assertStatus(201)
            ->assertJson(['label' => 'Jane Doe — jane.doe@example.com']);

        $this->assertDatabaseHas('users', ['email' => 'jane.doe@example.com', 'role' => 'client']);
        $this->assertDatabaseHas('customers', ['company' => 'Acme Hosting', 'status' => 'active']);

        // Duplicate email → 422 with a field error the modal can render.
        $this->actingAs($admin)->postJson(route('admin.customers.quick-store'), [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane.doe@example.com',
            'password' => 'Password123',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }
}
