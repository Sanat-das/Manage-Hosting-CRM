<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\ProductAddonPricing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Customer-initiated add-on purchase: a client buys an add-on for a service
 * they already own, and the add-on shows under that service.
 *
 * The add-on hangs off the service's EXISTING order line — a new order
 * holding only an add-on would provision a duplicate hosting account when it
 * activates — so the purchase is priced on the service's own cycle.
 */
class ClientAddonPurchaseTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Addon Client Corp',
            'status' => 'active',
        ]);
    }

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Shared Hosting Basic',
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'show_in_order' => true,
            'only_admin' => false,
            'status' => 'active',
        ], $attributes));
    }

    private function makeAddon(Product $product, array $attributes = []): ProductAddon
    {
        return ProductAddon::create(array_merge([
            'product_id' => $product->id,
            'name' => 'Extra Storage',
            'billing_cycle' => 'monthly',
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
        ]);
    }

    private function makeServiceLine(Order $order, Product $product): OrderItem
    {
        return OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 100.00,
            'total' => 100.00,
            'billing_cycles_count' => 1,
        ]);
    }

    private function makeAccount(Customer $customer, Product $product, Order $order): HostingAccount
    {
        return HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_id' => $order->id,
            'username' => 'hostuser',
            'domain' => 'example.com',
            'status' => 'active',
        ]);
    }

    public function test_customer_buys_an_addon_for_a_service_they_own(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $serviceLine = $this->makeServiceLine($order, $product);
        $account = $this->makeAccount($customer, $product, $order);
        $addon = $this->makeAddon($product, ['price' => 25.00, 'setup_fee' => 50.00]);

        $response = $this->actingAs($customer->user)
            ->post(route('client.hosting.addons.store', $account->id), [
                'addon_id' => $addon->id,
                'quantity' => 2,
            ]);

        $response->assertRedirect(route('client.hosting.index'));
        $response->assertSessionHas('success');

        // The add-on row hangs off the service's own line and carries the
        // service's product.
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => 'Extra Storage',
            'parent_item_id' => $serviceLine->id,
            'product_addon_id' => $addon->id,
            'billing_cycle' => 'monthly',
            'quantity' => 2,
            'unit_price' => 25.00,
            'total' => 50.00,
        ]);

        // The setup fee is its own one_time line.
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_name' => 'Extra Storage — Setup Fee',
            'parent_item_id' => $serviceLine->id,
            'product_addon_id' => $addon->id,
            'billing_cycle' => 'one_time',
            'quantity' => 1,
            'unit_price' => 50.00,
            'total' => 50.00,
        ]);

        // The order total follows the sum of its lines.
        $this->assertSame(200.00, round((float) $order->fresh()->total, 2));
    }

    public function test_the_addon_purchase_raises_one_sent_invoice_for_the_charge(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $this->makeServiceLine($order, $product);
        $account = $this->makeAccount($customer, $product, $order);
        $addon = $this->makeAddon($product, ['price' => 25.00]);

        $response = $this->actingAs($customer->user)
            ->post(route('client.hosting.addons.store', $account->id), [
                'addon_id' => $addon->id,
                'quantity' => 2,
            ]);

        $invoice = Invoice::where('order_id', $order->id)->sole();

        $this->assertSame(Invoice::STATUS_SENT, $invoice->status);
        $this->assertSame(50.00, round((float) $invoice->amount, 2));

        $this->assertDatabaseHas('invoice_items', [
            'invoice_id' => $invoice->id,
            'description' => 'Extra Storage — Monthly',
            'quantity' => 2,
            'unit_price' => 25.00,
            'total' => 50.00,
        ]);

        $response->assertRedirect(route('client.hosting.index'))
            // The success message names the add-on and the invoice it was
            // billed on.
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'Extra Storage')
                && str_contains($message, (string) $invoice->invoice_no));
    }

    public function test_the_addon_is_charged_the_matrix_price_for_the_services_cycle(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $serviceLine = $this->makeServiceLine($order, $product);
        $account = $this->makeAccount($customer, $product, $order);
        $addon = $this->makeAddon($product, ['billing_cycle' => 'annual', 'price' => 250.00]);

        ProductAddonPricing::create([
            'product_addon_id' => $addon->id,
            'billing_cycle' => 'monthly',
            'price' => 30.00,
            'setup_fee' => 0,
        ]);

        $this->actingAs($customer->user)
            ->post(route('client.hosting.addons.store', $account->id), [
                'addon_id' => $addon->id,
                'quantity' => 1,
            ])
            ->assertRedirect(route('client.hosting.index'));

        // The service's monthly cycle wins over the add-on's own annual
        // cycle / ₹250 price — the customer pays the matrix price.
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'parent_item_id' => $serviceLine->id,
            'product_addon_id' => $addon->id,
            'billing_cycle' => 'monthly',
            'unit_price' => 30.00,
            'total' => 30.00,
        ]);

        $this->assertSame(1, OrderItem::where('parent_item_id', $serviceLine->id)->count());
    }

    public function test_an_inactive_addon_is_rejected(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $this->makeServiceLine($order, $product);
        $account = $this->makeAccount($customer, $product, $order);
        $addon = $this->makeAddon($product, ['status' => 'inactive']);

        $this->actingAs($customer->user)
            ->post(route('client.hosting.addons.store', $account->id), [
                'addon_id' => $addon->id,
                'quantity' => 1,
            ])
            ->assertSessionHasErrors('addon_id');

        $this->assertSame(1, OrderItem::where('order_id', $order->id)->count());
        $this->assertSame(0, Invoice::where('order_id', $order->id)->count());
    }

    public function test_an_addon_scoped_to_another_product_is_rejected(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $other = $this->makeProduct(['name' => 'VPS Starter', 'price' => 500.00]);
        $order = $this->makeOrder($customer, $product);
        $this->makeServiceLine($order, $product);
        $account = $this->makeAccount($customer, $product, $order);
        $addon = $this->makeAddon($other);

        $this->actingAs($customer->user)
            ->post(route('client.hosting.addons.store', $account->id), [
                'addon_id' => $addon->id,
                'quantity' => 1,
            ])
            ->assertSessionHasErrors('addon_id');

        $this->assertSame(1, OrderItem::where('order_id', $order->id)->count());
        $this->assertSame(0, Invoice::where('order_id', $order->id)->count());
    }

    public function test_a_duplicate_live_addon_on_the_same_service_is_rejected(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $serviceLine = $this->makeServiceLine($order, $product);
        $account = $this->makeAccount($customer, $product, $order);
        $addon = $this->makeAddon($product);

        $this->actingAs($customer->user)
            ->post(route('client.hosting.addons.store', $account->id), [
                'addon_id' => $addon->id,
                'quantity' => 1,
            ])
            ->assertRedirect(route('client.hosting.index'));

        $this->actingAs($customer->user)
            ->post(route('client.hosting.addons.store', $account->id), [
                'addon_id' => $addon->id,
                'quantity' => 1,
            ])
            ->assertSessionHasErrors('addon_id');

        $this->assertSame(1, OrderItem::where('parent_item_id', $serviceLine->id)->count());
        $this->assertSame(1, Invoice::where('order_id', $order->id)->count());
    }

    public function test_a_client_cannot_buy_an_addon_for_another_customers_service(): void
    {
        $customer = $this->makeCustomer();
        $other = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($other, $product);
        $this->makeServiceLine($order, $product);
        $account = $this->makeAccount($other, $product, $order);
        $addon = $this->makeAddon($product);

        $this->actingAs($customer->user)
            ->post(route('client.hosting.addons.store', $account->id), [
                'addon_id' => $addon->id,
                'quantity' => 1,
            ])
            ->assertNotFound();

        $this->assertSame(1, OrderItem::where('order_id', $order->id)->count());
        $this->assertSame(0, Invoice::where('order_id', $order->id)->count());
    }

    public function test_the_service_page_shows_the_service_amount_not_the_addon_amount(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $this->makeServiceLine($order, $product);
        $account = $this->makeAccount($customer, $product, $order);
        $addon = $this->makeAddon($product, ['price' => 25.00]);

        // An add-on row already hangs off the same order.
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $addon->name,
            'parent_item_id' => $order->items()->whereNull('product_addon_id')->value('id'),
            'product_addon_id' => $addon->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 25.00,
            'total' => 25.00,
            'billing_cycles_count' => 1,
            'next_billing_date' => '2026-12-01',
        ]);

        $response = $this->actingAs($customer->user)
            ->get(route('client.hosting.show', $account->id))
            ->assertOk();

        // The Billing card is the one carrying the "Amount" row — the add-on
        // breakdown sits in the other column and must never be its source.
        $billingRow = Str::between((string) $response->getContent(), 'Amount</th>', '</tr>');

        $this->assertStringContainsString('₹100.00', $billingRow);
        $this->assertStringNotContainsString('₹25.00', $billingRow);
    }

    public function test_the_products_page_lists_the_addons_and_offers_the_purchase_form(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $serviceLine = $this->makeServiceLine($order, $product);
        $account = $this->makeAccount($customer, $product, $order);
        $addon = $this->makeAddon($product, ['price' => 25.00]);
        $buyable = $this->makeAddon($product, ['name' => 'Extra Bandwidth', 'price' => 15.00]);

        // An add-on row already hangs off the same order.
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $addon->name,
            'parent_item_id' => $serviceLine->id,
            'product_addon_id' => $addon->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 25.00,
            'total' => 25.00,
            'billing_cycles_count' => 1,
            'next_billing_date' => '2026-12-01',
        ]);

        $response = $this->actingAs($customer->user)
            ->get(route('client.hosting.index'))
            ->assertOk();

        // The add-on breakdown and the purchase form.
        $response->assertSee('Extra Storage');
        $response->assertSee('Buy add-on');
        $response->assertSee(route('client.hosting.addons.store', $account->id), false);
        $response->assertSee('Add-ons');
        // The add-on the service already has is listed as owned, not offered
        // for purchase again; the one it does not have is offered.
        $response->assertSee('Extra Bandwidth — ₹15.00 / Monthly');
        $response->assertDontSee('Extra Storage —');

        // The detail page no longer carries the card — buying happens on the list.
        $this->actingAs($customer->user)
            ->get(route('client.hosting.show', $account->id))
            ->assertOk()
            ->assertDontSee('Buy add-on');
    }

    public function test_no_purchase_form_is_offered_for_a_service_the_server_would_refuse(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product, Order::STATUS_TERMINATED);
        $this->makeServiceLine($order, $product);
        $account = $this->makeAccount($customer, $product, $order);
        $this->makeAddon($product);

        $response = $this->actingAs($customer->user)
            ->get(route('client.hosting.index'))
            ->assertOk();

        // The purchase is refused on a terminated order, so the page must not
        // offer one.
        $response->assertDontSee('Buy add-on');
        $response->assertSeeText('Add-ons can be added to active services only.');
    }

    public function test_a_suspended_service_can_still_take_an_addon(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product, Order::STATUS_SUSPENDED);
        $this->makeServiceLine($order, $product);
        $account = $this->makeAccount($customer, $product, $order);
        $this->makeAddon($product);

        $response = $this->actingAs($customer->user)
            ->get(route('client.hosting.index'))
            ->assertOk();

        $response->assertSee('Buy add-on');
    }

    public function test_the_offered_addon_belongs_to_the_product_the_service_line_carries(): void
    {
        $customer = $this->makeCustomer();
        $oldProduct = $this->makeProduct(['name' => 'Shared Hosting Basic']);
        $newProduct = $this->makeProduct(['name' => 'Shared Hosting Pro']);
        $order = $this->makeOrder($customer, $newProduct);
        $serviceLine = $this->makeServiceLine($order, $newProduct);
        // The account still points at the old product — an upgrade moves the
        // order line, not the account.
        $account = $this->makeAccount($customer, $oldProduct, $order);
        $addon = $this->makeAddon($newProduct, ['name' => 'Pro Backup']);

        $response = $this->actingAs($customer->user)
            ->get(route('client.hosting.index'))
            ->assertOk();

        $response->assertSee('Pro Backup — ₹25.00 / Monthly');

        $this->actingAs($customer->user)
            ->post(route('client.hosting.addons.store', $account->id), [
                'addon_id' => $addon->id,
                'quantity' => 1,
            ])
            ->assertRedirect(route('client.hosting.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'parent_item_id' => $serviceLine->id,
            'product_addon_id' => $addon->id,
        ]);
    }

    public function test_every_service_gets_a_purchase_form_not_only_the_ones_on_the_page(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $this->makeAddon($product, ['price' => 25.00]);

        $accounts = [];

        for ($i = 0; $i < 16; $i++) {
            $order = $this->makeOrder($customer, $product);
            $this->makeServiceLine($order, $product);
            $accounts[] = $this->makeAccount($customer, $product, $order);
        }

        // The page shows 15 services at a time, newest first — the first
        // account created is the one beyond the first page.
        $sixteenthAccount = $accounts[0];

        $response = $this->actingAs($customer->user)
            ->get(route('client.hosting.index'))
            ->assertOk();

        $response->assertSee(route('client.hosting.addons.store', $sixteenthAccount->id), false);
    }

    public function test_the_offered_option_states_the_one_time_setup_fee(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $this->makeServiceLine($order, $product);
        $account = $this->makeAccount($customer, $product, $order);
        $this->makeAddon($product, ['price' => 25.00, 'setup_fee' => 50.00]);

        $response = $this->actingAs($customer->user)
            ->get(route('client.hosting.index'))
            ->assertOk();

        // The setup fee is charged once — the offer must say so.
        $response->assertSee('+ ₹50.00 once');
    }

    public function test_a_failed_purchase_names_the_service(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $other = $this->makeProduct(['name' => 'VPS Starter', 'price' => 500.00]);
        $order = $this->makeOrder($customer, $product);
        $this->makeServiceLine($order, $product);
        $account = $this->makeAccount($customer, $product, $order);
        $addon = $this->makeAddon($other);

        $response = $this->actingAs($customer->user)
            ->post(route('client.hosting.addons.store', $account->id), [
                'addon_id' => $addon->id,
                'quantity' => 1,
            ])
            ->assertSessionHasErrors('addon_id');

        // The page offers a form per service, so the failure must say which
        // service it belongs to.
        $this->assertStringContainsString(
            'Service #'.$account->id,
            (string) session('errors')->first('addon_id')
        );
    }

    public function test_an_addon_without_a_next_billing_date_shows_as_cancelled(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $serviceLine = $this->makeServiceLine($order, $product);
        $account = $this->makeAccount($customer, $product, $order);
        $addon = $this->makeAddon($product);

        // A cancelled add-on: the schedule has stopped, so there is no next
        // billing date left.
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $addon->name,
            'parent_item_id' => $serviceLine->id,
            'product_addon_id' => $addon->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 25.00,
            'total' => 25.00,
            'billing_cycles_count' => 1,
            'next_billing_date' => null,
        ]);

        $response = $this->actingAs($customer->user)
            ->get(route('client.hosting.index'))
            ->assertOk();

        $response->assertSee('Cancelled');
    }
}
