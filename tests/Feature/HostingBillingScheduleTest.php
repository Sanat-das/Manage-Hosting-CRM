<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\HostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The billing schedule the engine reads, and what the admin is shown.
 *
 * Two claims that were false:
 *
 *  - editing one service's billing date wrote the ORDER's date, which moved
 *    every other service on that order (the renewal engine bills per item);
 *  - a customer profile showed a bare "next billing" date, so a cycle that
 *    was never invoiced — or invoiced and voided — looked settled.
 */
class HostingBillingScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_editing_a_service_date_moves_that_item_and_the_order_summary_follows_the_items(): void
    {
        [$account, $order] = $this->makeServiceWithAddon();

        $this->actingAsAdmin()->put(route('admin.hosting.update-billing', $account), [
            'billing_cycle' => 'annual',
            'next_billing_date' => '2028-01-15',
        ])->assertRedirect();

        $hostingItem = $order->items()->where('product_id', $account->product_id)->first();
        $addonItem = $order->items()->where('product_id', $this->addonProduct->id)->first();

        // The service the admin edited moved.
        $this->assertSame('2028-01-15', $hostingItem->fresh()->next_billing_date->toDateString());

        // The other service on the same order did NOT move — writing the
        // order's date from the form moved it.
        $this->assertSame('2027-11-30', $addonItem->fresh()->next_billing_date->toDateString());

        // And the order's summary is derived from the items (the earliest),
        // not from the field that was posted.
        $this->assertSame('2027-11-30', $order->fresh()->next_billing_date->toDateString());
    }

    public function test_saving_another_field_leaves_the_schedule_untouched(): void
    {
        [$account, $order] = $this->makeServiceWithAddon();

        $this->actingAsAdmin()->put(route('admin.hosting.update-billing', $account), [
            'payment_method' => 'bank_transfer',
        ])->assertRedirect();

        $hostingItem = $order->items()->where('product_id', $account->product_id)->first();
        $addonItem = $order->items()->where('product_id', $this->addonProduct->id)->first();

        $this->assertSame('2027-10-29', $hostingItem->fresh()->next_billing_date->toDateString());
        $this->assertSame('2027-11-30', $addonItem->fresh()->next_billing_date->toDateString());
        $this->assertSame('2027-10-29', $order->fresh()->next_billing_date->toDateString());
        $this->assertSame('bank_transfer', $order->fresh()->payment_method);
    }

    public function test_the_customer_profile_says_whether_the_cycle_was_invoiced_and_paid(): void
    {
        [$account, $order] = $this->makeServiceWithAddon();

        $invoice = Invoice::create([
            'customer_id' => $account->customer_id,
            'order_id' => $order->id,
            'invoice_no' => 'INV-2026-CYCLE1',
            'amount' => 100.00,
            'tax' => 0,
            'total' => 100.00,
            'status' => Invoice::STATUS_SENT,
            'due_date' => now()->addDays(7),
        ]);

        $response = $this->actingAsAdmin()->get(route('admin.customers.show', $account->customer_id));

        $response->assertOk();
        $response->assertSee('INV-2026-CYCLE1', false);
        $response->assertSee('unpaid', false);
    }

    public function test_the_customer_profile_says_a_cycle_that_was_never_invoiced(): void
    {
        [$account] = $this->makeServiceWithAddon();

        $response = $this->actingAsAdmin()->get(route('admin.customers.show', $account->customer_id));

        $response->assertOk();
        $response->assertSee('not invoiced yet', false);
    }

    private Product $addonProduct;

    /**
     * A customer with one hosting service and one recurring add-on on the
     * same order, each on its own schedule.
     *
     * @return array{0: HostingAccount, 1: Order}
     */
    private function makeServiceWithAddon(): array
    {
        $customer = Customer::create([
            'user_id' => User::factory()->create()->id,
            'company' => 'Schedule Corp',
            'status' => 'active',
        ]);

        $product = Product::create([
            'name' => 'Schedule Hosting',
            'price' => 100.00,
            'billing_cycle' => 'annual',
            'status' => 'active',
        ]);

        $this->addonProduct = Product::create([
            'name' => 'Schedule Addon',
            'price' => 50.00,
            'billing_cycle' => 'annual',
            'status' => 'active',
        ]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'annual',
            'quantity' => 1,
            'total' => 150.00,
            'status' => Order::STATUS_ACTIVE,
            'next_billing_date' => '2027-10-29',
            'last_billing_date' => '2026-10-29',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'billing_cycle' => 'annual',
            'quantity' => 1,
            'unit_price' => 100.00,
            'total' => 100.00,
            'next_billing_date' => '2027-10-29',
            'last_billing_date' => '2026-10-29',
            'billing_cycles_count' => 1,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->addonProduct->id,
            'product_name' => $this->addonProduct->name,
            'billing_cycle' => 'annual',
            'quantity' => 1,
            'unit_price' => 50.00,
            'total' => 50.00,
            'next_billing_date' => '2027-11-30',
            'last_billing_date' => '2026-11-30',
            'billing_cycles_count' => 1,
        ]);

        $account = HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_id' => $order->id,
            'domain' => 'schedule.example',
            'status' => HostingService::STATUS_ACTIVE,
        ]);

        return [$account, $order];
    }

    private function actingAsAdmin(): self
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        foreach (['hosting.view', 'hosting.edit', 'customers.view', 'invoices.view'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => ucfirst($name)]);
            $adminRole->permissions()->syncWithoutDetaching($permission->id);
        }

        $user->assignRole('admin');

        return $this->actingAs($user);
    }
}
