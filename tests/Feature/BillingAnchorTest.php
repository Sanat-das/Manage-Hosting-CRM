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
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The billing anniversary belongs to the purchase date.
 *
 * An order bought on the 1st and activated on the 20th must renew on the
 * 1st: anchoring the schedule on the activation date handed the customer a
 * free period every time activation slipped, and it is how a renewal landed
 * eleven days after the first year had been paid.
 */
class BillingAnchorTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_anniversary_is_anchored_on_the_purchase_date_not_the_activation_date(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();

        $purchasedAt = now()->subDays(40)->startOfDay();

        $order = $this->makeOrder($customer, $product);

        // The order was bought 40 days ago; it is activated today.
        Order::where('id', $order->id)->update(['created_at' => $purchasedAt]);
        $order->refresh();

        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_price' => (float) $product->price,
            'total' => (float) $product->price,
            'billing_cycle' => 'monthly',
        ]);

        $service = app(OrderService::class);
        $service->markPaid($order);
        $service->markProvisioning($order->fresh());
        $service->activate($order->fresh());

        $expected = $purchasedAt->copy()->addMonth()->toDateString();

        $this->assertSame($expected, $item->fresh()->next_billing_date->toDateString());
        $this->assertNotSame(
            now()->addMonth()->toDateString(),
            $item->fresh()->next_billing_date->toDateString(),
            'The anniversary must follow the purchase date, not the activation date.'
        );
    }

    public function test_the_admin_billing_edit_refuses_a_date_before_the_last_billing_date(): void
    {
        [$account, $order] = $this->makeAccount();

        $response = $this->actingAsAdmin()->put(route('admin.hosting.update-billing', $account), [
            'billing_cycle' => 'monthly',
            'next_billing_date' => now()->subYear()->toDateString(),
        ]);

        $response->assertSessionHasErrors('next_billing_date');

        $this->assertSame(
            $order->next_billing_date->toDateString(),
            $order->fresh()->next_billing_date->toDateString(),
            'A refused date must leave the schedule untouched.'
        );
    }

    public function test_the_admin_billing_edit_keeps_the_schedule_when_the_date_is_not_posted(): void
    {
        [$account, $order] = $this->makeAccount();

        $before = $order->next_billing_date->toDateString();

        $response = $this->actingAsAdmin()->put(route('admin.hosting.update-billing', $account), [
            'payment_method' => 'bank_transfer',
        ]);

        $response->assertRedirect();

        $this->assertSame($before, $order->fresh()->next_billing_date->toDateString());
        $this->assertSame('bank_transfer', $order->fresh()->payment_method);
    }

    public function test_the_admin_billing_edit_clears_the_schedule_when_the_date_is_posted_empty(): void
    {
        [$account, $order] = $this->makeAccount();

        $this->actingAsAdmin()->put(route('admin.hosting.update-billing', $account), [
            'billing_cycle' => 'monthly',
            'next_billing_date' => '',
        ])->assertRedirect();

        $this->assertNull($order->fresh()->next_billing_date);
    }

    private function actingAsAdmin(): self
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        foreach (['hosting.view', 'hosting.edit'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => ucfirst($name)]);
            $adminRole->permissions()->syncWithoutDetaching($permission->id);
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
            'company' => 'Anchor Corp',
            'status' => 'active',
        ]);
    }

    private function makeProduct(): Product
    {
        return Product::create([
            'name' => 'Anchor Hosting',
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'status' => 'active',
        ]);
    }

    private function makeOrder(Customer $customer, Product $product): Order
    {
        return Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => (float) $product->price,
            'status' => Order::STATUS_PENDING,
        ]);
    }

    /**
     * A live service whose order was billed once already.
     *
     * @return array{0: HostingAccount, 1: Order}
     */
    private function makeAccount(): array
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();

        $order = Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => (float) $product->price,
            'status' => Order::STATUS_ACTIVE,
            'next_billing_date' => now()->addMonth()->toDateString(),
            'last_billing_date' => now()->subMonth()->toDateString(),
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
            'last_billing_date' => now()->subMonth()->toDateString(),
        ]);

        $account = HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_id' => $order->id,
            'domain' => 'anchor.example',
            'status' => 'active',
        ]);

        return [$account, $order];
    }
}
