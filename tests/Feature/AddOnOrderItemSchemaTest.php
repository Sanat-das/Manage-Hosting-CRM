<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Additive add-on link columns on `order_items` (`parent_item_id`,
 * `product_addon_id`) plus the Eloquent relations on OrderItem/ProductAddon.
 */
class AddOnOrderItemSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_addon_columns_exist_and_are_nullable(): void
    {
        $this->assertTrue(Schema::hasColumns('order_items', ['parent_item_id', 'product_addon_id']));

        $item = $this->makeItem();

        $this->assertNull($item->parent_item_id);
        $this->assertNull($item->product_addon_id);
    }

    public function test_plain_item_is_not_an_addon(): void
    {
        $item = $this->makeItem();

        $this->assertFalse($item->isAddon());
    }

    public function test_item_with_product_addon_id_is_an_addon(): void
    {
        $addon = $this->makeAddon();
        $item = $this->makeItem(['product_addon_id' => $addon->id]);

        $this->assertTrue($item->isAddon());
        $this->assertTrue($item->addon->is($addon));
    }

    public function test_parent_item_and_child_addons_resolve(): void
    {
        $parent = $this->makeItem();
        $addon = $this->makeAddon();
        $child = $this->makeItem([
            'order_id' => $parent->order_id,
            'parent_item_id' => $parent->id,
            'product_addon_id' => $addon->id,
        ]);

        $this->assertTrue($child->parentItem->is($parent));
        $this->assertTrue($parent->childAddons->contains($child));
        $this->assertTrue($child->addon->is($addon));
        $this->assertTrue($addon->orderItems->contains($child));
    }

    public function test_applicable_to_returns_scoped_and_global_active_addons(): void
    {
        $product = $this->makeProduct();
        $other = $this->makeProduct(['name' => 'Other Product']);

        $scoped = $this->makeAddon(['product_id' => $product->id, 'name' => 'Scoped']);
        $global = $this->makeAddon(['product_id' => null, 'name' => 'Global']);
        $inactive = $this->makeAddon(['product_id' => $product->id, 'name' => 'Inactive', 'status' => 'inactive']);
        $otherProduct = $this->makeAddon(['product_id' => $other->id, 'name' => 'Other']);

        $ids = ProductAddon::active()->applicableTo($product->id)->pluck('id')->all();

        $this->assertContains($scoped->id, $ids);
        $this->assertContains($global->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
        $this->assertNotContains($otherProduct->id, $ids);
    }

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Shared Hosting Basic',
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'show_in_order' => true,
            'status' => 'active',
            'require_domain' => false,
        ], $attributes));
    }

    private function makeAddon(array $attributes = []): ProductAddon
    {
        return ProductAddon::create(array_merge([
            'name' => 'Extra Storage',
            'billing_cycle' => 'monthly',
            'price' => 10.00,
            'status' => 'active',
        ], $attributes));
    }

    private function makeItem(array $attributes = []): OrderItem
    {
        $customer = Customer::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'active',
        ]);
        $product = $this->makeProduct();

        $order = Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.str_pad((string) ((int) Order::max('id') + 1), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 100.00,
            'status' => Order::STATUS_PENDING,
        ]);

        return OrderItem::create(array_merge([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 100.00,
            'total' => 100.00,
        ], $attributes));
    }
}
