<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\ProductAddonPricing;
use App\Models\User;
use App\Services\Billing\AddOnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class AddOnServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_materialize_creates_single_recurring_row_without_setup_fee(): void
    {
        [$order, $parent] = $this->makeOrderWithParent();
        $addon = $this->makeAddon($parent->product_id, ['price' => 500, 'setup_fee' => 0, 'billing_cycle' => 'monthly']);

        $rows = app(AddOnService::class)->materialize($order, $parent, [
            ['addon_id' => $addon->id, 'quantity' => 1],
        ]);

        $this->assertCount(1, $rows);

        $row = $rows[0];
        $this->assertSame($order->id, (int) $row->order_id);
        $this->assertSame((int) $parent->product_id, (int) $row->product_id);
        $this->assertSame($addon->name, $row->product_name);
        $this->assertSame($parent->id, (int) $row->parent_item_id);
        $this->assertSame($addon->id, (int) $row->product_addon_id);
        $this->assertSame('monthly', $row->billing_cycle);
        $this->assertSame(1, (int) $row->quantity);
        $this->assertSame(500.00, (float) $row->unit_price);
        $this->assertSame(500.00, (float) $row->total);
        $this->assertSame(0, (int) $row->recurring_cycles_limit);
        $this->assertNull($row->next_billing_date);
        $this->assertSame(1, (int) $row->billing_cycles_count);
    }

    public function test_materialize_creates_setup_row_when_setup_fee_present(): void
    {
        [$order, $parent] = $this->makeOrderWithParent();
        $addon = $this->makeAddon($parent->product_id, ['price' => 500, 'setup_fee' => 250, 'billing_cycle' => 'monthly']);

        $rows = app(AddOnService::class)->materialize($order, $parent, [
            ['addon_id' => $addon->id, 'quantity' => 1],
        ]);

        $this->assertCount(2, $rows);

        $setup = $rows[1];
        $this->assertSame($order->id, (int) $setup->order_id);
        $this->assertSame((int) $parent->product_id, (int) $setup->product_id);
        $this->assertSame("{$addon->name} — Setup Fee", $setup->product_name);
        $this->assertSame($parent->id, (int) $setup->parent_item_id);
        $this->assertSame($addon->id, (int) $setup->product_addon_id);
        $this->assertSame('one_time', $setup->billing_cycle);
        $this->assertSame(1, (int) $setup->quantity);
        $this->assertSame(250.00, (float) $setup->unit_price);
        $this->assertSame(250.00, (float) $setup->total);
        $this->assertSame(0, (int) $setup->recurring_cycles_limit);
        $this->assertNull($setup->next_billing_date);
        $this->assertSame(0, (int) $setup->billing_cycles_count);
    }

    public function test_materialize_scales_recurring_total_with_quantity(): void
    {
        [$order, $parent] = $this->makeOrderWithParent();
        $addon = $this->makeAddon($parent->product_id, ['price' => 500, 'setup_fee' => 0, 'billing_cycle' => 'monthly']);

        $rows = app(AddOnService::class)->materialize($order, $parent, [
            ['addon_id' => $addon->id, 'quantity' => 3],
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame(3, (int) $rows[0]->quantity);
        $this->assertSame(1500.00, (float) $rows[0]->total);
    }

    public function test_materialize_rejects_inactive_addon(): void
    {
        [$order, $parent] = $this->makeOrderWithParent();
        $addon = $this->makeAddon($parent->product_id, ['status' => 'inactive']);

        $this->expectException(InvalidArgumentException::class);

        app(AddOnService::class)->materialize($order, $parent, [
            ['addon_id' => $addon->id, 'quantity' => 1],
        ]);
    }

    public function test_materialize_rejects_wrong_product_addon(): void
    {
        [$order, $parent] = $this->makeOrderWithParent();
        $other = $this->makeProduct(['name' => 'Other Product']);
        $addon = $this->makeAddon($other->id);

        $this->expectException(InvalidArgumentException::class);

        app(AddOnService::class)->materialize($order, $parent, [
            ['addon_id' => $addon->id, 'quantity' => 1],
        ]);
    }

    public function test_materialize_rejects_unknown_addon(): void
    {
        [$order, $parent] = $this->makeOrderWithParent();

        $this->expectException(InvalidArgumentException::class);

        app(AddOnService::class)->materialize($order, $parent, [
            ['addon_id' => 999999, 'quantity' => 1],
        ]);
    }

    public function test_materialize_rejects_parent_that_is_an_addon(): void
    {
        [$order, $parent] = $this->makeOrderWithParent();
        $addon = $this->makeAddon($parent->product_id);

        $rows = app(AddOnService::class)->materialize($order, $parent, [
            ['addon_id' => $addon->id, 'quantity' => 1],
        ]);

        $this->expectException(InvalidArgumentException::class);

        app(AddOnService::class)->materialize($order, $rows[0], [
            ['addon_id' => $addon->id, 'quantity' => 1],
        ]);
    }

    public function test_materialize_rejects_order_parent_mismatch(): void
    {
        [$order, $parent] = $this->makeOrderWithParent();
        $otherOrder = $this->makeOrder($parent->product_id);
        $addon = $this->makeAddon($parent->product_id);

        $this->expectException(InvalidArgumentException::class);

        app(AddOnService::class)->materialize($otherOrder, $parent, [
            ['addon_id' => $addon->id, 'quantity' => 1],
        ]);

        $this->assertSame($order->id, (int) $parent->order_id);
    }

    public function test_applicable_for_returns_scoped_and_global_active_addons(): void
    {
        $product = $this->makeProduct();
        $other = $this->makeProduct(['name' => 'Other Product']);

        $scoped = $this->makeAddon($product->id, ['name' => 'Scoped Addon']);
        $global = $this->makeAddon(null, ['name' => 'Global Addon']);
        $inactive = $this->makeAddon($product->id, ['name' => 'Inactive Addon', 'status' => 'inactive']);
        $otherProduct = $this->makeAddon($other->id, ['name' => 'Other Addon']);

        $names = app(AddOnService::class)->applicableFor($product)->pluck('name')->all();

        $this->assertContains($scoped->name, $names);
        $this->assertContains($global->name, $names);
        $this->assertNotContains($inactive->name, $names);
        $this->assertNotContains($otherProduct->name, $names);
    }

    public function test_for_parent_returns_only_the_parents_addon_rows(): void
    {
        [$order, $parent] = $this->makeOrderWithParent();
        $sibling = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $parent->product_id,
            'product_name' => 'Sibling Service',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 100.00,
            'total' => 100.00,
        ]);

        $addon = $this->makeAddon($parent->product_id);
        $otherAddon = $this->makeAddon($parent->product_id, ['name' => 'Other Addon']);

        $rows = app(AddOnService::class)->materialize($order, $parent, [
            ['addon_id' => $addon->id, 'quantity' => 1],
        ]);
        $siblingRows = app(AddOnService::class)->materialize($order, $sibling, [
            ['addon_id' => $otherAddon->id, 'quantity' => 1],
        ]);

        $forParent = app(AddOnService::class)->forParent($parent);

        $this->assertEqualsCanonicalizing(
            collect($rows)->map->id->all(),
            $forParent->pluck('id')->all()
        );
        $this->assertNotContains($siblingRows[0]->id, $forParent->pluck('id')->all());
    }

    public function test_resolve_cycle_and_price_prefers_the_matrix_row_for_the_parent_cycle(): void
    {
        $addon = $this->makeAddon(null, ['price' => 500, 'setup_fee' => 10, 'billing_cycle' => 'monthly']);
        ProductAddonPricing::create([
            'product_addon_id' => $addon->id,
            'billing_cycle' => 'annual',
            'price' => 5000.00,
            'setup_fee' => 250.00,
        ]);

        $resolved = app(AddOnService::class)->resolveCycleAndPrice($addon, 'annual');

        $this->assertSame('annual', $resolved['cycle']);
        $this->assertSame(5000.00, $resolved['unit_price']);
        $this->assertSame(250.00, $resolved['setup_fee']);
    }

    public function test_resolve_cycle_and_price_falls_back_to_the_addon_defaults(): void
    {
        $addon = $this->makeAddon(null, ['price' => 500, 'setup_fee' => 10, 'billing_cycle' => 'monthly']);
        ProductAddonPricing::create([
            'product_addon_id' => $addon->id,
            'billing_cycle' => 'annual',
            'price' => 5000.00,
            'setup_fee' => 250.00,
        ]);

        $resolved = app(AddOnService::class)->resolveCycleAndPrice($addon, 'quarterly');

        $this->assertSame('monthly', $resolved['cycle']);
        $this->assertSame(500.00, $resolved['unit_price']);
        $this->assertSame(10.00, $resolved['setup_fee']);
    }

    public function test_materialize_uses_the_matrix_row_when_the_parent_cycle_is_priced(): void
    {
        [$order, $parent] = $this->makeOrderWithParent('annual');
        $addon = $this->makeAddon($parent->product_id, ['price' => 500, 'setup_fee' => 10, 'billing_cycle' => 'monthly']);
        ProductAddonPricing::create([
            'product_addon_id' => $addon->id,
            'billing_cycle' => 'annual',
            'price' => 5000.00,
            'setup_fee' => 250.00,
        ]);

        $rows = app(AddOnService::class)->materialize($order, $parent, [
            ['addon_id' => $addon->id, 'quantity' => 2],
        ]);

        $this->assertCount(2, $rows);

        $recurring = $rows[0];
        $this->assertSame($parent->id, (int) $recurring->parent_item_id);
        $this->assertSame($addon->id, (int) $recurring->product_addon_id);
        $this->assertSame('annual', $recurring->billing_cycle);
        $this->assertSame(2, (int) $recurring->quantity);
        $this->assertSame(5000.00, (float) $recurring->unit_price);
        $this->assertSame(10000.00, (float) $recurring->total);
        $this->assertSame(1, (int) $recurring->billing_cycles_count);

        // The setup row uses the matrix row's setup fee, not the add-on base.
        $setup = $rows[1];
        $this->assertSame("{$addon->name} — Setup Fee", $setup->product_name);
        $this->assertSame('one_time', $setup->billing_cycle);
        $this->assertSame(250.00, (float) $setup->unit_price);
        $this->assertSame(250.00, (float) $setup->total);
        $this->assertSame(0, (int) $setup->billing_cycles_count);
    }

    /**
     * @return array{Order, OrderItem}
     */
    private function makeOrderWithParent(string $cycle = 'monthly'): array
    {
        $product = $this->makeProduct();
        $order = $this->makeOrder($product->id);

        $parent = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'billing_cycle' => $cycle,
            'quantity' => 1,
            'unit_price' => 499.00,
            'total' => 499.00,
        ]);

        return [$order->fresh(), $parent->fresh()];
    }

    private function makeOrder(int $productId): Order
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        $customer = Customer::create([
            'user_id' => $user->id,
            'company' => 'AddOn Test Corp',
            'status' => 'active',
        ]);

        return Order::create([
            'customer_id' => $customer->id,
            'product_id' => $productId,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 499.00,
            'status' => Order::STATUS_PENDING,
        ]);
    }

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Shared Hosting',
            'price' => 499.00,
            'billing_cycle' => 'monthly',
            'status' => 'active',
        ], $attributes));
    }

    /**
     * @param  int|null  $productId  Null for a global add-on.
     */
    private function makeAddon(?int $productId, array $attributes = []): ProductAddon
    {
        return ProductAddon::create(array_merge([
            'product_id' => $productId,
            'name' => 'Extra Storage',
            'billing_cycle' => 'monthly',
            'price' => 500.00,
            'setup_fee' => 0,
            'status' => 'active',
        ], $attributes));
    }
}
