<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\ProductAddonPricing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Purchased add-ons on the client's order pages.
 *
 * An add-on bought with a product is its own order_items row carrying
 * product_addon_id, and it shares the order's product. On the client's pages
 * it was indistinguishable from a service line, and the order list had no way
 * to tell the two apart: Order::items() carries no orderBy, so the row the
 * driver hands back first is not guaranteed to be the service line.
 */
class OrderAddonDisplayTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCT_NAME = 'Shared Hosting Basic';

    private const ADDON_NAME = 'Extra Storage';

    private const BADGE = '<span class="badge text-bg-warning ms-1">Add-on</span>';

    public function test_the_order_detail_page_marks_the_addon_line_and_only_the_addon_line(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $addon = $this->makeAddon($product);
        $this->makeAddonPricing($addon);
        $order = $this->makeOrder($customer, $product);
        $this->makeServiceItem($order, $product);
        $this->makeAddonItem($order, $product, $addon);

        $html = $this->actingAs($customer->user)
            ->get(route('client.orders.show', $order->id))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<strong>'.preg_quote(self::ADDON_NAME, '/').'<\/strong>\s*'.preg_quote(self::BADGE, '/').'/',
            $html,
            'The add-on line carries no badge on the order detail page.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<strong>'.preg_quote(self::PRODUCT_NAME, '/').'<\/strong>\s*'.preg_quote(self::BADGE, '/').'/',
            $html,
            'The service line is badged as an add-on.'
        );
        $this->assertSame(1, substr_count($html, self::BADGE));
    }

    public function test_the_order_list_names_the_service_and_not_the_addon(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $addon = $this->makeAddon($product);
        $this->makeAddonPricing($addon);
        $order = $this->makeOrder($customer, $product);
        $this->makeServiceItem($order, $product);
        $this->makeAddonItem($order, $product, $addon);

        $response = $this->actingAs($customer->user)
            ->get(route('client.orders.index'))
            ->assertOk();

        $html = $response->getContent();

        $this->assertStringContainsString(self::PRODUCT_NAME, $html);
        $this->assertStringNotContainsString(self::ADDON_NAME, $html);
        $this->assertStringContainsString('+1 more', $html);

        // The list loads its items with a column list, so the add-on marker
        // has to be part of it: without it every item looks like a service
        // line and the list cannot tell the two apart.
        $addonItems = $response->viewData('orders')->first()->items
            ->filter(fn (OrderItem $item) => $item->product_addon_id !== null);

        $this->assertCount(1, $addonItems, 'The order list did not load the add-on marker on its items.');
        $this->assertSame($addon->id, $addonItems->first()->product_addon_id);
    }

    public function test_the_order_list_names_the_service_even_when_the_addon_row_comes_first(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $addon = $this->makeAddon($product);
        $this->makeAddonPricing($addon);
        $order = $this->makeOrder($customer, $product);
        $serviceItem = $this->makeServiceItem($order, $product);
        $addonItem = $this->makeAddonItem($order, $product, $addon, $serviceItem);

        // Order::items() carries no orderBy, so the add-on row may be the one
        // the driver hands back first.
        $order->setRelation('items', collect([$addonItem, $serviceItem]));

        $html = view('client.orders.index', [
            'orders' => new LengthAwarePaginator(collect([$order]), 1, 15, 1),
            'search' => '',
            'status' => null,
        ])->render();

        $this->assertStringContainsString(self::PRODUCT_NAME, $html);
        $this->assertStringNotContainsString(self::ADDON_NAME, $html);
    }

    public function test_an_order_with_only_an_addon_line_still_shows_a_name(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $addon = $this->makeAddon($product);
        $this->makeAddonPricing($addon);
        $order = $this->makeOrder($customer, $product);

        $this->makeAddonItem($order, $product, $addon);

        $html = $this->actingAs($customer->user)
            ->get(route('client.orders.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(self::ADDON_NAME, $html);
        $this->assertStringNotContainsString(self::PRODUCT_NAME, $html);
    }

    public function test_a_plain_service_order_reads_exactly_as_before(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);

        $this->makeServiceItem($order, $product);

        $list = $this->actingAs($customer->user)
            ->get(route('client.orders.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(self::PRODUCT_NAME, $list);
        $this->assertStringNotContainsString('Add-on', $list);
        $this->assertStringNotContainsString('+1 more', $list);

        $detail = $this->actingAs($customer->user)
            ->get(route('client.orders.show', $order->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(self::PRODUCT_NAME, $detail);
        $this->assertStringNotContainsString('Add-on', $detail);
    }

    // ─────────────────────────────── helpers ───────────────────────────────

    private function makeProduct(string $name = self::PRODUCT_NAME, float $price = 100.00): Product
    {
        return Product::create([
            'name' => $name,
            'price' => $price,
            'billing_cycle' => 'monthly',
            'show_in_order' => true,
            'only_admin' => false,
            'status' => 'active',
        ]);
    }

    private function makeAddon(Product $product, array $attributes = []): ProductAddon
    {
        return ProductAddon::create(array_merge([
            'product_id' => $product->id,
            'name' => self::ADDON_NAME,
            'billing_cycle' => 'monthly',
            'price' => 25.00,
            'setup_fee' => 0.00,
            'status' => 'active',
        ], $attributes));
    }

    private function makeAddonPricing(ProductAddon $addon, string $cycle = 'monthly', float $price = 25.00): ProductAddonPricing
    {
        return ProductAddonPricing::create([
            'product_addon_id' => $addon->id,
            'billing_cycle' => $cycle,
            'price' => $price,
            'setup_fee' => 0.00,
        ]);
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create();

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Addon Client',
            'status' => 'active',
        ]);
    }

    private function makeOrder(Customer $customer, Product $product): Order
    {
        return Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.Str::upper(Str::random(8)),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 100.00,
            'status' => Order::STATUS_PENDING,
        ]);
    }

    private function makeServiceItem(Order $order, Product $product, string $name = self::PRODUCT_NAME): OrderItem
    {
        return OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $name,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 100.00,
            'total' => 100.00,
        ]);
    }

    /**
     * The add-on line: its own row, sharing the service's product, marked by
     * product_addon_id and hung off the service line.
     */
    private function makeAddonItem(Order $order, Product $product, ProductAddon $addon, ?OrderItem $serviceItem = null, string $name = self::ADDON_NAME): OrderItem
    {
        return OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $name,
            'billing_cycle' => 'monthly',
            'parent_item_id' => $serviceItem?->id,
            'product_addon_id' => $addon->id,
            'quantity' => 1,
            'unit_price' => 25.00,
            'total' => 25.00,
        ]);
    }
}
