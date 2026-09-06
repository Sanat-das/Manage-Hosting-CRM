<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductBundle;
use App\Models\ProductPricing;
use App\Models\ProductUpgradePath;
use App\Models\Role;
use App\Models\User;
use App\Services\ProductBundlePricingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tier 4.4 — product bundles + upgrade paths (TDD).
 *
 * Bundle pricing flows ONLY through ProductBundlePricingService::priceFor().
 * Non-bundle cart/order math must remain byte-for-byte identical to today.
 */
class ProductBundleTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(string $name, bool $isBundle = false, float $price = 0): Product
    {
        return Product::create([
            'name' => $name,
            'is_bundle' => $isBundle,
            'price' => $price,
            'billing_cycle' => 'monthly',
            'show_in_order' => true,
            'only_admin' => false,
            'status' => 'active',
        ]);
    }

    private function price(Product $product, float $price, string $cycle = 'monthly'): ProductPricing
    {
        return $product->pricing()->create([
            'billing_cycle' => $cycle,
            'price' => $price,
            'setup_fee' => 0,
        ]);
    }

    private function addComponent(
        Product $bundle,
        Product $component,
        int $quantity = 1,
        string $discountType = 'percent',
        float $discountValue = 0,
        int $sortOrder = 0,
    ): ProductBundle {
        return ProductBundle::create([
            'bundle_product_id' => $bundle->id,
            'component_product_id' => $component->id,
            'quantity' => $quantity,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'sort_order' => $sortOrder,
        ]);
    }

    private function makeBundle(): Product
    {
        $bundle = $this->makeProduct('Hosting Combo', true);
        $a = $this->makeProduct('Shared Hosting', false, 100);
        $b = $this->makeProduct('Add-on Email', false, 50);
        $this->price($a, 100);
        $this->price($b, 50);

        $this->addComponent($bundle, $a, 1, 'percent', 10, 0);
        $this->addComponent($bundle, $b, 1, 'percent', 0, 1);

        return $bundle;
    }

    private function actingAsAdmin(): self
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $create = Permission::firstOrCreate(['name' => 'orders.create'], ['label' => 'Create Orders']);
        $adminRole->permissions()->syncWithoutDetaching([$create->id]);

        $user->assignRole('admin');

        return $this->actingAs($user);
    }

    private function makeCustomerUser(): Customer
    {
        $user = User::factory()->create();

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Bundle Corp',
            'status' => 'active',
        ]);
    }

    // ─────────────────────────── Pricing service ───────────────────────────

    public function test_price_for_bundle_returns_sum_of_parts_minus_discount(): void
    {
        $bundle = $this->makeBundle();

        $quote = app(ProductBundlePricingService::class)->priceFor($bundle, 'monthly');

        $this->assertNotNull($quote);
        $this->assertSame('monthly', $quote['cycle']);
        $this->assertCount(2, $quote['line_items']);
        $this->assertSame(150.0, $quote['subtotal']);
        $this->assertSame(10.0, $quote['discount']);
        $this->assertSame(140.0, $quote['total']);

        $this->assertSame(90.0, $quote['line_items'][0]['total']);
        $this->assertSame('Shared Hosting', $quote['line_items'][0]['name']);
        $this->assertSame(50.0, $quote['line_items'][1]['total']);
        $this->assertSame('Add-on Email', $quote['line_items'][1]['name']);
    }

    public function test_price_for_scales_percent_discount_with_component_quantity(): void
    {
        $bundle = $this->makeProduct('Volume Bundle', true);
        $c = $this->makeProduct('Licence Seat', false, 100);
        $this->price($c, 100);
        $this->addComponent($bundle, $c, 2, 'percent', 10, 0);

        $quote = app(ProductBundlePricingService::class)->priceFor($bundle, 'monthly');

        $this->assertSame(200.0, $quote['subtotal']);
        $this->assertSame(20.0, $quote['discount']);
        $this->assertSame(180.0, $quote['total']);
        $this->assertSame(180.0, $quote['line_items'][0]['total']);
    }

    public function test_price_for_fixed_discount_subtracts_flat_and_floors_at_zero(): void
    {
        $bundle = $this->makeProduct('Fixed Bundle', true);
        $c = $this->makeProduct('Widget', false, 100);
        $this->price($c, 100);
        $this->addComponent($bundle, $c, 1, 'fixed', 15, 0);

        $quote = app(ProductBundlePricingService::class)->priceFor($bundle, 'monthly');

        $this->assertSame(100.0, $quote['subtotal']);
        $this->assertSame(15.0, $quote['discount']);
        $this->assertSame(85.0, $quote['total']);

        $d = $this->makeProduct('Widget XL', false, 100);
        $this->price($d, 100);
        $this->addComponent($bundle, $d, 1, 'fixed', 500, 1);

        $floorQuote = app(ProductBundlePricingService::class)->priceFor($bundle, 'monthly');

        $this->assertSame(200.0, $floorQuote['subtotal']);
        $this->assertSame(115.0, $floorQuote['discount']);
        $this->assertSame(85.0, $floorQuote['total']);
    }

    public function test_price_for_returns_null_for_non_bundle_product(): void
    {
        $plain = $this->makeProduct('Shared Hosting Basic', false, 100);
        $this->price($plain, 100);

        $this->assertNull(app(ProductBundlePricingService::class)->priceFor($plain, 'monthly'));
    }

    public function test_price_for_returns_null_when_component_lacks_pricing_for_cycle(): void
    {
        $bundle = $this->makeProduct('Half Priced Bundle', true);
        $a = $this->makeProduct('Priced', false, 100);
        $b = $this->makeProduct('Unpriced', false, 100);
        $this->price($a, 100, 'monthly');
        // $b intentionally has no pricing row.

        $this->addComponent($bundle, $a, 1, 'percent', 0, 0);
        $this->addComponent($bundle, $b, 1, 'percent', 0, 1);

        $this->assertNull(app(ProductBundlePricingService::class)->priceFor($bundle, 'monthly'));
    }

    // ─────────────────────────── Upgrade paths ───────────────────────────

    public function test_upgrade_path_from_to_pair_is_unique(): void
    {
        $from = $this->makeProduct('Shared Hosting');
        $to = $this->makeProduct('VPS Starter');

        ProductUpgradePath::create(['from_product_id' => $from->id, 'to_product_id' => $to->id]);

        $this->expectException(QueryException::class);

        ProductUpgradePath::create(['from_product_id' => $from->id, 'to_product_id' => $to->id]);
    }

    public function test_upgradeable_to_returns_only_enabled_paths(): void
    {
        $from = $this->makeProduct('Shared Hosting');
        $enabled = $this->makeProduct('VPS Starter');
        $disabled = $this->makeProduct('Dedicated Box');

        ProductUpgradePath::create(['from_product_id' => $from->id, 'to_product_id' => $enabled->id, 'enabled' => true]);
        ProductUpgradePath::create(['from_product_id' => $from->id, 'to_product_id' => $disabled->id, 'enabled' => false]);

        $this->assertCount(2, $from->upgradePaths);
        $this->assertCount(1, $from->upgradeableTo);
        $this->assertSame($enabled->id, $from->upgradeableTo->first()->to_product_id);
    }

    // ─────────────────────────── Bundle model ───────────────────────────

    public function test_bundle_children_relation_and_is_bundle_helper(): void
    {
        $bundle = $this->makeProduct('Hosting Combo', true);
        $a = $this->makeProduct('Shared Hosting');
        $b = $this->makeProduct('Add-on Email');

        $this->addComponent($bundle, $a, 1, 'percent', 10, 0);
        $this->addComponent($bundle, $b, 1, 'percent', 0, 1);

        $this->assertTrue($bundle->isBundle());
        $this->assertFalse($a->isBundle());
        $this->assertCount(2, $bundle->bundleChildren);
        $this->assertSame('Shared Hosting', $bundle->bundleChildren->first()->component->name);
    }

    // ─────────────────────────── Cart expansion ───────────────────────────

    public function test_admin_cart_expands_bundle_into_component_line_items_with_bundle_price(): void
    {
        $this->actingAsAdmin();

        $bundle = $this->makeBundle();

        $this->post(route('admin.cart.add'), ['product_id' => $bundle->id]);

        $cart = session('cart');
        $this->assertCount(2, $cart);
        $this->assertSame(90.0, (float) $cart[0]['total']);
        $this->assertSame(50.0, (float) $cart[1]['total']);
        $this->assertSame(140.0, round(array_sum(array_column($cart, 'total')), 2));
    }

    public function test_admin_cart_bundle_place_order_creates_component_orders_at_bundle_price(): void
    {
        $this->actingAsAdmin();

        $bundle = $this->makeBundle();
        $customer = $this->makeCustomerUser();

        session()->put('cart', [
            [
                'product_id' => $bundle->bundleChildren->first()->component_product_id,
                'billing_cycle' => 'monthly',
                'domain' => null,
                'quantity' => 1,
                'unit_price' => 90.0,
                'total' => 90.0,
                'bundle_id' => $bundle->id,
            ],
            [
                'product_id' => $bundle->bundleChildren->last()->component_product_id,
                'billing_cycle' => 'monthly',
                'domain' => null,
                'quantity' => 1,
                'unit_price' => 50.0,
                'total' => 50.0,
                'bundle_id' => $bundle->id,
            ],
        ]);

        $this->post(route('admin.cart.place-order'), ['customer_id' => $customer->id]);

        $this->assertSame(2, Order::count());
        $this->assertSame(2, OrderItem::count());
        $this->assertSame(140.0, round((float) Order::sum('total'), 2));

        $components = $bundle->bundleChildren->sortBy('sort_order')->pluck('component_product_id');
        $this->assertEqualsCanonicalizing($components->all(), Order::pluck('product_id')->all());
    }

    public function test_admin_cart_non_bundle_purchase_is_unchanged(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeProduct('Shared Hosting Basic', false, 100);

        $this->post(route('admin.cart.add'), ['product_id' => $product->id, 'billing_cycle' => 'monthly']);

        $cart = session('cart');
        $this->assertCount(1, $cart);
        $this->assertSame($product->id, $cart[0]['product_id']);
        $this->assertSame('monthly', $cart[0]['billing_cycle']);
        $this->assertArrayNotHasKey('unit_price', $cart[0]);
        $this->assertArrayNotHasKey('total', $cart[0]);
        $this->assertArrayNotHasKey('bundle_id', $cart[0]);
    }

    public function test_client_store_expands_bundle_into_component_line_items(): void
    {
        $customer = $this->makeCustomerUser();

        $bundle = $this->makeBundle();

        $this->actingAs($customer->user)
            ->post(route('client.store.cart.add'), [
                'product_id' => $bundle->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
            ]);

        $cart = session('cart');
        $this->assertCount(2, $cart);
        $this->assertSame(90.0, (float) $cart[0]['total']);
        $this->assertSame(50.0, (float) $cart[1]['total']);
        $this->assertSame(140.0, round(array_sum(array_column($cart, 'total')), 2));
    }

    public function test_client_store_bundle_place_order_creates_component_orders(): void
    {
        $customer = $this->makeCustomerUser();

        $bundle = $this->makeBundle();

        session()->put('cart', [
            [
                'product_id' => $bundle->bundleChildren->first()->component_product_id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'domain' => null,
                'unit_price' => 90.0,
                'total' => 90.0,
                'bundle_id' => $bundle->id,
            ],
            [
                'product_id' => $bundle->bundleChildren->last()->component_product_id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'domain' => null,
                'unit_price' => 50.0,
                'total' => 50.0,
                'bundle_id' => $bundle->id,
            ],
        ]);

        $this->actingAs($customer->user)
            ->post(route('client.store.checkout.post'));

        $this->assertSame(2, Order::count());
        $this->assertSame(140.0, round((float) Order::sum('total'), 2));

        $components = $bundle->bundleChildren->sortBy('sort_order')->pluck('component_product_id');
        $this->assertEqualsCanonicalizing($components->all(), Order::pluck('product_id')->all());
    }

    public function test_client_store_non_bundle_purchase_is_unchanged(): void
    {
        $customer = $this->makeCustomerUser();

        $product = $this->makeProduct('Shared Hosting Basic', false, 100);

        $this->actingAs($customer->user)
            ->post(route('client.store.cart.add'), [
                'product_id' => $product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 2,
            ]);

        $cart = session('cart');
        $this->assertCount(1, $cart);
        $this->assertSame($product->id, $cart[0]['product_id']);
        $this->assertSame(2, $cart[0]['quantity']);
        $this->assertArrayNotHasKey('unit_price', $cart[0]);
        $this->assertArrayNotHasKey('total', $cart[0]);
        $this->assertArrayNotHasKey('bundle_id', $cart[0]);
    }
}
