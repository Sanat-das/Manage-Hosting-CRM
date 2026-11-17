<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\Role;
use App\Models\User;
use App\Services\HostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An add-on line on an order carries the PARENT service's product_id, so a
 * billing line picked by product_id alone can land on the add-on row instead
 * of the service — and the admin billing form would then write the add-on's
 * schedule. The admin hosting screens and the customer CSV export must always
 * resolve the SERVICE line.
 */
class AdminAddonBillingLineIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_hosting_edit_page_bills_from_the_service_line(): void
    {
        $admin = $this->adminUser(['hosting.view', 'hosting.edit']);
        $fixture = $this->makeOrderWithAddonLine();
        $account = $this->makeHostingAccount($fixture);

        $response = $this->actingAs($admin)->get(route('admin.hosting.edit', $account));

        $response->assertOk();
        $response->assertViewHas('billingItem', fn ($item) => $item !== null && (int) $item->id === (int) $fixture['service_item']->id);

        // The billing form pre-fills the renewal date from that line.
        $response->assertSee('value="'.$fixture['service_item']->next_billing_date->format('Y-m-d').'"', false);
        $response->assertDontSee('value="'.$fixture['addon_item']->next_billing_date->format('Y-m-d').'"', false);
    }

    public function test_admin_hosting_show_page_package_snapshot_comes_from_the_service_line(): void
    {
        $admin = $this->adminUser(['hosting.view']);
        $fixture = $this->makeOrderWithAddonLine();

        $fixture['service_item']->update(['config_options' => [
            'product_group_name' => 'Service Line Package',
            'provisioning_module' => 'cpanel',
            'options' => [],
        ]]);
        $fixture['addon_item']->update(['config_options' => [
            'product_group_name' => 'Add-on Line Package',
            'provisioning_module' => 'manual',
            'options' => [],
        ]]);

        $account = $this->makeHostingAccount($fixture);

        $response = $this->actingAs($admin)->get(route('admin.hosting.show', $account));

        $response->assertOk();
        $response->assertSee('Service Line Package');
        $response->assertDontSee('Add-on Line Package');
    }

    public function test_update_billing_writes_the_service_line_and_leaves_the_addon_line_untouched(): void
    {
        $admin = $this->adminUser(['hosting.view', 'hosting.edit']);
        $fixture = $this->makeOrderWithAddonLine();
        $account = $this->makeHostingAccount($fixture);

        $response = $this->actingAs($admin)->put(route('admin.hosting.update-billing', $account), [
            'billing_cycle' => 'annual',
            'next_billing_date' => '2027-06-15',
            'next_due_date' => '2027-06-01',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // The service line carries the new schedule.
        $this->assertDatabaseHas('order_items', [
            'id' => $fixture['service_item']->id,
            'product_addon_id' => null,
            'billing_cycle' => 'annual',
            'next_billing_date' => '2027-06-15 00:00:00',
        ]);

        // The add-on line keeps its own cycle and date.
        $this->assertDatabaseHas('order_items', [
            'id' => $fixture['addon_item']->id,
            'product_addon_id' => $fixture['addon']->id,
            'billing_cycle' => 'quarterly',
            'next_billing_date' => '2026-12-10 00:00:00',
        ]);
    }

    public function test_customer_hosting_csv_export_uses_the_service_line(): void
    {
        $admin = $this->adminUser(['customers.view']);
        $fixture = $this->makeOrderWithAddonLine();
        $this->makeHostingAccount($fixture);

        $response = $this->actingAs($admin)->get(route('admin.customers.show', $fixture['customer']).'?tab=hosting&export=csv');

        $response->assertOk();

        $csv = $response->streamedContent();

        // The service line's cycle, amount and renewal date — not the add-on's.
        $this->assertStringContainsString('Monthly', $csv);
        $this->assertStringNotContainsString('Quarterly', $csv);
        $this->assertStringContainsString('100.00', $csv);
        $this->assertStringNotContainsString('25.00', $csv);
        $this->assertStringContainsString('2027-03-15', $csv);
        $this->assertStringNotContainsString('2026-12-10', $csv);
    }

    public function test_upgraded_service_bills_through_its_service_line_not_the_addon_line(): void
    {
        // After an upgrade the served item's product_id is rewritten to the new
        // product while hosting_accounts and the add-on lines keep the old one:
        // a product_id-only picker then matches ONLY the add-on line.
        $admin = $this->adminUser(['hosting.view', 'hosting.edit']);
        $fixture = $this->makeOrderWithAddonLine();
        $fixture['service_item']->update(['product_id' => $this->makeProduct(['name' => 'Shared Hosting v2'])->id]);
        $account = $this->makeHostingAccount($fixture);

        // The billing edit still resolves a service line — never the add-on.
        $edit = $this->actingAs($admin)->get(route('admin.hosting.edit', $account));

        $edit->assertOk();
        $edit->assertViewHas('billingItem', fn ($item) => $item !== null && (int) $item->id === (int) $fixture['service_item']->id);

        $response = $this->actingAs($admin)->put(route('admin.hosting.update-billing', $account), [
            'billing_cycle' => 'annual',
            'next_billing_date' => '2027-06-15',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // The service line takes the new schedule.
        $this->assertDatabaseHas('order_items', [
            'id' => $fixture['service_item']->id,
            'product_addon_id' => null,
            'billing_cycle' => 'annual',
            'next_billing_date' => '2027-06-15 00:00:00',
        ]);

        // The add-on line is not this service's billing line: it keeps its own
        // cycle and date.
        $this->assertDatabaseHas('order_items', [
            'id' => $fixture['addon_item']->id,
            'product_addon_id' => $fixture['addon']->id,
            'billing_cycle' => 'quarterly',
            'next_billing_date' => '2026-12-10 00:00:00',
        ]);
    }

    public function test_upgraded_service_csv_export_reports_the_service_line_not_the_addon_line(): void
    {
        $admin = $this->adminUser(['customers.view']);
        $fixture = $this->makeOrderWithAddonLine();
        $fixture['service_item']->update(['product_id' => $this->makeProduct(['name' => 'Shared Hosting v2'])->id]);
        $this->makeHostingAccount($fixture);

        $response = $this->actingAs($admin)->get(route('admin.customers.show', $fixture['customer']).'?tab=hosting&export=csv');

        $response->assertOk();

        $csv = $response->streamedContent();

        // The add-on line is the only one carrying the account's product_id —
        // it must still not answer for the service.
        $this->assertStringContainsString('Monthly', $csv);
        $this->assertStringNotContainsString('Quarterly', $csv);
        $this->assertStringContainsString('100.00', $csv);
        $this->assertStringNotContainsString('25.00', $csv);
        $this->assertStringContainsString('2027-03-15', $csv);
        $this->assertStringNotContainsString('2026-12-10', $csv);
    }

    // ---------------------------------------------------------- helpers

    /**
     * An order carrying a service line and an add-on line that share the
     * account's product_id — the shape AddOnService writes.
     *
     * @return array{customer: Customer, product: Product, addon: ProductAddon, order: Order, service_item: OrderItem, addon_item: OrderItem}
     */
    private function makeOrderWithAddonLine(): array
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $addon = $this->makeAddon($product);
        $order = $this->makeOrder($customer, $product);

        $addonItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $addon->name,
            'product_addon_id' => $addon->id,
            'billing_cycle' => 'quarterly',
            'quantity' => 1,
            'unit_price' => 25.00,
            'total' => 25.00,
            'next_billing_date' => '2026-12-10',
        ]);

        $serviceItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 100.00,
            'total' => 100.00,
            'next_billing_date' => '2027-03-15',
        ]);

        $addonItem->update(['parent_item_id' => $serviceItem->id]);

        return [
            'customer' => $customer,
            'product' => $product,
            'addon' => $addon,
            'order' => $order,
            'service_item' => $serviceItem,
            'addon_item' => $addonItem,
        ];
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function makeHostingAccount(array $fixture): HostingAccount
    {
        return HostingAccount::create([
            'customer_id' => $fixture['customer']->id,
            'product_id' => $fixture['product']->id,
            'order_id' => $fixture['order']->id,
            'username' => 'addon_billing_acct',
            'status' => HostingService::STATUS_ACTIVE,
        ]);
    }

    private function adminUser(array $permissionNames = ['hosting.view', 'hosting.edit']): User
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);

        $permissionIds = [];

        foreach ($permissionNames as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['label' => ucwords(str_replace('.', ' ', $name))]
            );
            $permissionIds[] = $permission->id;
        }

        $adminRole->permissions()->sync($permissionIds);
        $user->assignRole('admin');

        return $user;
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Add-on Billing Isolation Corp',
            'status' => 'active',
        ]);
    }

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Shared Hosting',
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'status' => 'active',
        ], $attributes));
    }

    private function makeAddon(Product $product, array $attributes = []): ProductAddon
    {
        return ProductAddon::create(array_merge([
            'product_id' => $product->id,
            'name' => 'Extra Storage',
            'billing_cycle' => 'quarterly',
            'price' => 25.00,
            'setup_fee' => 0,
            'status' => 'active',
        ], $attributes));
    }

    private function makeOrder(Customer $customer, Product $product, string $status = Order::STATUS_ACTIVE): Order
    {
        return Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 100.00,
            'status' => $status,
            'next_billing_date' => '2026-12-10',
        ]);
    }
}
