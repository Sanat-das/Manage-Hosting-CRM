<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\HostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HostingBillingEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_billing_persists_account_order_and_item(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeActiveOrder($customer, $product);
        $account = HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_id' => $order->id,
            'username' => 'billing_acct',
            'status' => HostingService::STATUS_ACTIVE,
        ]);

        $response = $this->actingAsAdmin()
            ->put(route('admin.hosting.update-billing', $account), [
                'billing_cycle' => 'annual',
                'next_due_date' => '2027-06-01',
                'next_billing_date' => '2027-06-15',
                'payment_method' => 'credit_card',
                'subscription_id' => 'sub_12345',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // Account-level next due date persisted.
        $this->assertDatabaseHas('hosting_accounts', [
            'id' => $account->id,
            'next_due_date' => '2027-06-01 00:00:00',
        ]);

        // Order-level billing fields persisted.
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'billing_cycle' => 'annual',
            'next_billing_date' => '2027-06-15 00:00:00',
            'payment_method' => 'credit_card',
            'subscription_id' => 'sub_12345',
        ]);

        // The authoritative order item schedule is kept in sync so the
        // recurring billing engine picks up the change.
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'billing_cycle' => 'annual',
            'next_billing_date' => '2027-06-15 00:00:00',
        ]);
    }

    public function test_update_billing_without_order_only_updates_account(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $account = HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'username' => 'no_order_acct',
        ]);

        $response = $this->actingAsAdmin()
            ->put(route('admin.hosting.update-billing', $account), [
                'next_due_date' => '2027-06-01',
                'billing_cycle' => 'annual',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // Account field updated; no order exists, so nothing to sync.
        $this->assertDatabaseHas('hosting_accounts', [
            'id' => $account->id,
            'next_due_date' => '2027-06-01 00:00:00',
        ]);
    }

    public function test_update_billing_rejects_invalid_cycle(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeActiveOrder($customer, $product);
        $account = HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_id' => $order->id,
            'username' => 'bad_cycle_acct',
        ]);

        $response = $this->actingAsAdmin()
            ->put(route('admin.hosting.update-billing', $account), [
                'billing_cycle' => 'fortnightly',
            ]);

        $response->assertSessionHasErrors('billing_cycle');
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'billing_cycle' => 'monthly', // unchanged
        ]);
    }

    public function test_update_billing_requires_hosting_manage_permission(): void
    {
        $user = User::factory()->create(['role' => 'support']);

        $account = HostingAccount::create([
            'customer_id' => 1,
            'product_id' => 1,
            'username' => 'no_perm_acct',
        ]);

        $this->actingAs($user)
            ->put(route('admin.hosting.update-billing', $account), ['next_due_date' => '2027-06-01'])
            ->assertForbidden();
    }

    public function test_edit_page_contains_billing_tab(): void
    {
        $account = HostingAccount::create([
            'customer_id' => 1,
            'product_id' => 1,
            'username' => 'edit_page_acct',
        ]);

        $this->actingAsAdmin()
            ->get(route('admin.hosting.edit', $account))
            ->assertOk()
            ->assertSee('data-bs-target="#billing"', false)
            ->assertSee('Update Billing Info');
    }

    public function test_show_page_billing_tab_links_to_edit(): void
    {
        $account = HostingAccount::create([
            'customer_id' => 1,
            'product_id' => 1,
            'username' => 'show_page_acct',
        ]);

        $this->actingAsAdmin()
            ->get(route('admin.hosting.show', $account))
            ->assertOk()
            ->assertSee('Edit billing info');
    }

    // ---------------------------------------------------------- helpers

    private function actingAsAdmin(): self
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        foreach (['hosting.view', 'hosting.manage'] as $permName) {
            $perm = Permission::firstOrCreate(['name' => $permName], ['label' => ucfirst($permName)]);
            $adminRole->permissions()->syncWithoutDetaching($perm->id);
        }

        $user->assignRole('admin');

        return $this->actingAs($user);
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Billing Test Corp',
            'status' => 'active',
        ]);
    }

    private function makeProduct(): Product
    {
        return Product::create([
            'name' => 'Billing Test Hosting',
            'price' => 499.00,
            'billing_cycle' => 'monthly',
            'status' => 'active',
        ]);
    }

    private function makeActiveOrder(Customer $customer, Product $product): Order
    {
        $order = Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => (float) $product->price,
            'status' => Order::STATUS_ACTIVE,
            'next_billing_date' => now()->addMonth()->toDateString(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_price' => (float) $product->price,
            'total' => (float) $product->price,
            'billing_cycle' => 'monthly',
            'next_billing_date' => now()->addMonth()->toDateString(),
        ]);

        return $order;
    }
}