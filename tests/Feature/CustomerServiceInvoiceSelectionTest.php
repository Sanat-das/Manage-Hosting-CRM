<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerServiceInvoiceSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_selecting_two_services_with_distinct_orders_creates_two_draft_invoices(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $orderOne = $this->makeOrder($customer, $product);
        $orderTwo = $this->makeOrder($customer, $product);
        $accountOne = $this->makeHostingAccount($customer, $product, $orderOne, 'svc_one');
        $accountTwo = $this->makeHostingAccount($customer, $product, $orderTwo, 'svc_two');

        $response = $this->actingAs($admin)
            ->from(route('admin.customers.show', $customer))
            ->post(route('admin.customers.services.invoice-selected', $customer), [
                'service_ids' => [$accountOne->id, $accountTwo->id],
            ]);

        $response->assertRedirect(route('admin.customers.show', $customer));
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');

        $this->assertSame(2, Invoice::count());
        $this->assertSame(1, Invoice::where('order_id', $orderOne->id)->where('status', Invoice::STATUS_DRAFT)->count());
        $this->assertSame(1, Invoice::where('order_id', $orderTwo->id)->where('status', Invoice::STATUS_DRAFT)->count());
    }

    public function test_selecting_same_order_twice_creates_one_invoice(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $accountOne = $this->makeHostingAccount($customer, $product, $order, 'svc_dup_one');
        $accountTwo = $this->makeHostingAccount($customer, $product, $order, 'svc_dup_two');

        $response = $this->actingAs($admin)
            ->from(route('admin.customers.show', $customer))
            ->post(route('admin.customers.services.invoice-selected', $customer), [
                'service_ids' => [$accountOne->id, $accountTwo->id],
            ]);

        $response->assertRedirect(route('admin.customers.show', $customer));
        $response->assertSessionHasNoErrors();

        $this->assertSame(1, Invoice::count());
        $this->assertSame($order->id, Invoice::sole()->order_id);
        $this->assertSame(Invoice::STATUS_DRAFT, Invoice::sole()->status);
    }

    public function test_existing_live_invoice_blocks_duplicates(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $account = $this->makeHostingAccount($customer, $product, $order, 'svc_live');

        $existingInvoice = Invoice::create([
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'invoice_no' => 'INV-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'amount' => 100.00,
            'total' => 100.00,
            'status' => Invoice::STATUS_SENT,
            'due_date' => now()->addDays(7),
        ]);

        $response = $this->actingAs($admin)
            ->from(route('admin.customers.show', $customer))
            ->post(route('admin.customers.services.invoice-selected', $customer), [
                'service_ids' => [$account->id],
            ]);

        $response->assertRedirect(route('admin.customers.show', $customer));
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');
        $flash = (string) $response->getSession()->get('success');
        $this->assertStringContainsString('Invoices:', $flash);
        $this->assertStringContainsString('0 created, 1 existing', $flash);
        $this->assertStringContainsString('0 skipped', $flash);
        $this->assertStringContainsString($existingInvoice->invoice_no, $flash);
        $this->assertStringContainsString('Unpaid', $flash);

        $this->assertSame(1, Invoice::count());
        $this->assertSame(Invoice::STATUS_SENT, Invoice::sole()->status);
    }

    public function test_another_customers_service_is_not_invoiced(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $otherCustomer = $this->makeCustomer('Other Corp');
        $product = $this->makeProduct();
        $otherOrder = $this->makeOrder($otherCustomer, $product);
        $otherAccount = $this->makeHostingAccount($otherCustomer, $product, $otherOrder, 'svc_other');

        $response = $this->actingAs($admin)
            ->from(route('admin.customers.show', $customer))
            ->post(route('admin.customers.services.invoice-selected', $customer), [
                'service_ids' => [$otherAccount->id],
            ]);

        $response->assertRedirect(route('admin.customers.show', $customer));
        $response->assertSessionHasErrors('error');

        $this->assertSame(0, Invoice::count());
    }

    public function test_empty_selection_does_not_create_invoices_and_does_not_500(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();

        $response = $this->actingAs($admin)
            ->from(route('admin.customers.show', $customer))
            ->post(route('admin.customers.services.invoice-selected', $customer), [
                'service_ids' => [],
            ]);

        $response->assertRedirect(route('admin.customers.show', $customer));
        $response->assertSessionHasErrors('error');
        $this->assertSame(0, Invoice::count());
    }

    public function test_addon_only_selection_creates_invoice_with_just_the_addon_line(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $parent = $order->items()->whereNull('product_addon_id')->sole();
        $addon = $this->makeProductAddon($product, 'Extra Storage', 50.00);
        $addonItem = $this->makeAddonItem($order, $parent, $product, $addon);
        $this->makeSetupFeeItem($order, $parent, $product, $addon);

        $response = $this->actingAs($admin)
            ->from(route('admin.customers.show', $customer))
            ->post(route('admin.customers.services.invoice-selected', $customer), [
                'addon_ids' => [$addonItem->id],
            ]);

        $response->assertRedirect(route('admin.customers.show', $customer));
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success', 'Invoices: 1 created, 0 existing, 0 skipped, 0 failed.');

        $this->assertSame(1, Invoice::count());
        $invoice = Invoice::sole();
        $this->assertSame($order->id, $invoice->order_id);
        $this->assertSame(Invoice::STATUS_DRAFT, $invoice->status);
        $this->assertSame(50.00, (float) $invoice->amount);
        $this->assertSame("Add-on invoice for order {$order->order_number}", $invoice->notes);
        $this->assertSame(1, $invoice->items()->count());
        $description = $invoice->items()->first()->description;
        $this->assertStringContainsString('Extra Storage', $description);
        $this->assertStringNotContainsString('Shared Hosting', $description);
        $this->assertStringNotContainsString('Setup Fee', $description);
    }

    public function test_two_addons_on_same_order_coinvoice_in_one_invoice(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $parent = $order->items()->whereNull('product_addon_id')->sole();
        $storageAddon = $this->makeProductAddon($product, 'Extra Storage', 50.00);
        $backupAddon = $this->makeProductAddon($product, 'Daily Backup', 30.00);
        $storageItem = $this->makeAddonItem($order, $parent, $product, $storageAddon);
        $backupItem = $this->makeAddonItem($order, $parent, $product, $backupAddon);

        $response = $this->actingAs($admin)
            ->from(route('admin.customers.show', $customer))
            ->post(route('admin.customers.services.invoice-selected', $customer), [
                'addon_ids' => [$storageItem->id, $backupItem->id],
            ]);

        $response->assertRedirect(route('admin.customers.show', $customer));
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success', 'Invoices: 1 created, 0 existing, 0 skipped, 0 failed.');

        $this->assertSame(1, Invoice::count());
        $invoice = Invoice::sole();
        $this->assertSame($order->id, $invoice->order_id);
        $this->assertSame(80.00, (float) $invoice->amount);
        $this->assertSame(2, $invoice->items()->count());

        $descriptions = $invoice->items()->pluck('description')->all();
        $this->assertTrue(collect($descriptions)->contains(fn ($d) => str_contains($d, 'Extra Storage')));
        $this->assertTrue(collect($descriptions)->contains(fn ($d) => str_contains($d, 'Daily Backup')));
    }

    public function test_another_customers_addon_is_not_invoiced(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $otherCustomer = $this->makeCustomer('Other Corp');
        $product = $this->makeProduct();
        $otherOrder = $this->makeOrder($otherCustomer, $product);
        $otherParent = $otherOrder->items()->whereNull('product_addon_id')->sole();
        $addon = $this->makeProductAddon($product, 'Extra Storage', 50.00);
        $otherAddonItem = $this->makeAddonItem($otherOrder, $otherParent, $product, $addon);

        $response = $this->actingAs($admin)
            ->from(route('admin.customers.show', $customer))
            ->post(route('admin.customers.services.invoice-selected', $customer), [
                'addon_ids' => [$otherAddonItem->id],
            ]);

        $response->assertRedirect(route('admin.customers.show', $customer));
        $response->assertSessionHasErrors('error');
        $this->assertSame(0, Invoice::count());
    }

    public function test_service_and_its_addon_together_produce_exactly_one_invoice(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $account = $this->makeHostingAccount($customer, $product, $order, 'svc_combo');
        $parent = $order->items()->whereNull('product_addon_id')->sole();
        $addon = $this->makeProductAddon($product, 'Extra Storage', 50.00);
        $addonItem = $this->makeAddonItem($order, $parent, $product, $addon);
        $order->update(['total' => 150.00]);

        $response = $this->actingAs($admin)
            ->from(route('admin.customers.show', $customer))
            ->post(route('admin.customers.services.invoice-selected', $customer), [
                'service_ids' => [$account->id],
                'addon_ids' => [$addonItem->id],
            ]);

        $response->assertRedirect(route('admin.customers.show', $customer));
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');

        $this->assertSame(1, Invoice::count());
        $invoice = Invoice::sole();
        $this->assertSame($order->id, $invoice->order_id);
        $this->assertSame(Invoice::STATUS_DRAFT, $invoice->status);
    }

    public function test_existing_live_invoice_blocks_addon_only_invoice(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $parent = $order->items()->whereNull('product_addon_id')->sole();
        $addon = $this->makeProductAddon($product, 'Extra Storage', 50.00);
        $addonItem = $this->makeAddonItem($order, $parent, $product, $addon);

        $existingInvoice = Invoice::create([
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'invoice_no' => 'INV-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'amount' => 100.00,
            'total' => 100.00,
            'status' => Invoice::STATUS_SENT,
            'due_date' => now()->addDays(7),
        ]);

        $response = $this->actingAs($admin)
            ->from(route('admin.customers.show', $customer))
            ->post(route('admin.customers.services.invoice-selected', $customer), [
                'addon_ids' => [$addonItem->id],
            ]);

        $response->assertRedirect(route('admin.customers.show', $customer));
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');
        $flash = (string) $response->getSession()->get('success');
        $this->assertStringContainsString('Invoices:', $flash);
        $this->assertStringContainsString('0 created, 1 existing', $flash);
        $this->assertStringContainsString($existingInvoice->invoice_no, $flash);
        $this->assertStringContainsString('Unpaid', $flash);

        $this->assertSame(1, Invoice::count());
        $this->assertSame(Invoice::STATUS_SENT, Invoice::sole()->status);
    }

    public function test_paid_invoices_only_do_not_block_next_cycle_invoice(): void
    {
        // WHMCS semantics: paid (settled) history must not block the next cycle;
        // only an unpaid invoice reuses. Paid invoices are settled history, not
        // an open renewal, so invoicing must proceed to a new draft invoice.
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $account = $this->makeHostingAccount($customer, $product, $order, 'svc_paid_history');

        Invoice::create([
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'invoice_no' => 'INV-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'amount' => 100.00,
            'total' => 100.00,
            'status' => Invoice::STATUS_PAID,
            'due_date' => now()->subDays(5),
        ]);

        $response = $this->actingAs($admin)
            ->from(route('admin.customers.show', $customer))
            ->post(route('admin.customers.services.invoice-selected', $customer), [
                'service_ids' => [$account->id],
            ]);

        $response->assertRedirect(route('admin.customers.show', $customer));
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');
        $flash = (string) $response->getSession()->get('success');
        $this->assertStringContainsString('Invoices:', $flash);
        $this->assertStringContainsString('1 created, 0 existing, 0 skipped, 0 failed', $flash);

        $this->assertSame(2, Invoice::count());
        $this->assertSame(1, Invoice::where('order_id', $order->id)->where('status', Invoice::STATUS_PAID)->count());
        $this->assertSame(1, Invoice::where('order_id', $order->id)->where('status', Invoice::STATUS_DRAFT)->count());
    }

    private function adminUser(): User
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);

        foreach (['customers.view', 'invoices.create', 'invoices.view'] as $permissionName) {
            $permission = Permission::firstOrCreate(
                ['name' => $permissionName],
                ['label' => ucwords(str_replace('.', ' ', $permissionName))]
            );
            $adminRole->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->assignRole('admin');

        return $user;
    }

    private function makeCustomer(string $company = 'Selection Test Corp'): Customer
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        return Customer::create([
            'user_id' => $user->id,
            'company' => $company,
            'status' => 'active',
        ]);
    }

    private function makeProduct(): Product
    {
        return Product::create([
            'name' => 'Shared Hosting',
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'status' => 'active',
        ]);
    }

    private function makeOrder(Customer $customer, Product $product): Order
    {
        $order = Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 100.00,
            'status' => Order::STATUS_PENDING,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 100.00,
            'total' => 100.00,
        ]);

        return $order;
    }

    private function makeHostingAccount(Customer $customer, Product $product, Order $order, string $username): HostingAccount
    {
        return HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_id' => $order->id,
            'username' => $username,
            'status' => 'active',
        ]);
    }

    private function makeProductAddon(Product $product, string $name, float $price): ProductAddon
    {
        return ProductAddon::create([
            'product_id' => $product->id,
            'name' => $name,
            'billing_cycle' => 'monthly',
            'price' => $price,
            'setup_fee' => 0,
            'status' => 'active',
        ]);
    }

    private function makeAddonItem(Order $order, OrderItem $parent, Product $product, ProductAddon $addon): OrderItem
    {
        return OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $addon->name,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => (float) $addon->price,
            'total' => (float) $addon->price,
            'next_billing_date' => now()->addMonth()->toDateString(),
            'parent_item_id' => $parent->id,
            'product_addon_id' => $addon->id,
        ]);
    }

    private function makeSetupFeeItem(Order $order, OrderItem $parent, Product $product, ProductAddon $addon): OrderItem
    {
        return OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $addon->name.' — Setup Fee',
            'billing_cycle' => 'one_time',
            'quantity' => 1,
            'unit_price' => 25.00,
            'total' => 25.00,
            'next_billing_date' => null,
            'parent_item_id' => $parent->id,
            'product_addon_id' => $addon->id,
        ]);
    }
}
