<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\ProductAddonPricing;
use App\Models\ProductPricing;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Add-on picker UI (T1.5): the storefront product page and the admin order
 * form expose the orderable add-ons with the field names the order-time
 * entry points validate.
 */
class AddOnPickerUiTest extends TestCase
{
    use RefreshDatabase;

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
            'setup_fee' => 50.00,
            'status' => 'active',
        ], $attributes));
    }

    private function makeCustomerUser(): User
    {
        $user = User::factory()->create();

        Customer::create([
            'user_id' => $user->id,
            'company' => 'Addon Picker Corp',
            'status' => 'active',
        ]);

        return $user;
    }

    private function addPricingRow(ProductAddon $addon, string $cycle, float $price, float $setupFee = 0.0): ProductAddonPricing
    {
        return ProductAddonPricing::create([
            'product_addon_id' => $addon->id,
            'billing_cycle' => $cycle,
            'price' => $price,
            'setup_fee' => $setupFee,
        ]);
    }

    /**
     * The rendered price fragment of one add-on's label. Page-level negative
     * assertions cannot be used for the label text: the inline script that
     * re-renders it necessarily contains the same words.
     */
    private function addonLabelHtml(string $html, int $addonId): string
    {
        $rowStart = strpos($html, '<div class="product-addon-row mb-2" data-addon-id="'.$addonId.'"');
        $this->assertNotFalse($rowStart, 'Add-on row was not rendered.');

        $priceStart = strpos($html, 'class="product-addon-price"', $rowStart);
        $labelEnd = strpos($html, '</label>', $priceStart);

        return substr($html, $priceStart, $labelEnd - $priceStart);
    }

    /**
     * Decode one JSON payload embedded in a page's inline script. The
     * rendered JSON is not searchable as text (Blade may escape its quotes),
     * so assertions decode it instead.
     *
     * @return array<mixed>
     */
    private function embeddedJson(string $html, string $marker): array
    {
        $this->assertStringContainsString($marker, $html);

        $start = strpos($html, $marker) + strlen($marker);
        $end = strpos($html, ';', $start);

        $decoded = json_decode(substr($html, $start, $end - $start), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function adminUser(): User
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);

        foreach (['orders.view', 'orders.create', 'orders.edit'] as $permissionName) {
            $permission = Permission::firstOrCreate(
                ['name' => $permissionName],
                ['label' => ucwords(str_replace('.', ' ', $permissionName))]
            );
            $adminRole->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->assignRole('admin');

        return $user;
    }

    public function test_storefront_product_page_renders_addon_picker(): void
    {
        $product = $this->makeProduct();
        $addon = $this->makeAddon($product);
        $user = $this->makeCustomerUser();

        $response = $this->actingAs($user)->get(route('client.store.show', $product));

        $response->assertOk();
        $response->assertSee('id="product-addons"', false);
        $response->assertSee('addons[0][addon_id]', false);
        $response->assertSee('addons[0][quantity]', false);
        $response->assertSee($addon->name);
        $response->assertSee(route('client.store.cart.add'), false);
    }

    public function test_storefront_product_page_omits_addon_picker_without_addons(): void
    {
        $product = $this->makeProduct();
        $user = $this->makeCustomerUser();

        $response = $this->actingAs($user)->get(route('client.store.show', $product));

        $response->assertOk();
        $response->assertDontSee('id="product-addons"', false);
        $response->assertDontSee('addons[0][addon_id]', false);
    }

    public function test_admin_order_form_exposes_addon_data_for_product(): void
    {
        $admin = $this->adminUser();
        $product = $this->makeProduct();
        $addon = $this->makeAddon($product);
        $this->makeAddon($product, ['name' => 'Retired Addon', 'status' => 'inactive']);

        $response = $this->actingAs($admin)->get(route('admin.orders.create'));

        $response->assertOk();
        $response->assertSee('orderAddons', false);
        $response->assertSee('line-addons', false);
        $response->assertSee($addon->name);
        $response->assertDontSee('Retired Addon');
    }

    public function test_storefront_addon_price_uses_the_matrix_row_for_the_selected_cycle(): void
    {
        $product = $this->makeProduct();
        $addon = $this->makeAddon($product);
        $this->addPricingRow($addon, 'monthly', 40.00, 60.00);
        $user = $this->makeCustomerUser();

        $response = $this->actingAs($user)->get(route('client.store.show', $product));

        $response->assertOk();
        $response->assertSee($addon->name);
        $response->assertSee('storeAddons', false);

        $html = (string) $response->getContent();

        // The product's default cycle is monthly and prices the add-on there,
        // so the row price (and row setup fee) replaces the base display.
        $label = $this->addonLabelHtml($html, $addon->id);
        $this->assertStringContainsString('₹40.00', $label);
        $this->assertStringContainsString('billed with product cycle', $label);
        $this->assertStringContainsString('₹60.00', $label);
        $this->assertStringNotContainsString('₹25.00', $label);
        $this->assertStringNotContainsString('(Monthly)', $label);

        // The embedded matrix lets the labels follow a cycle switch.
        $matrix = $this->embeddedJson($html, 'window.storeAddons = ');
        $this->assertSame(40.0, (float) $matrix[$addon->id]['monthly']['price']);
        $this->assertSame(60.0, (float) $matrix[$addon->id]['monthly']['setup_fee']);
    }

    public function test_storefront_addon_price_follows_the_products_own_cycle_in_a_pricing_ladder(): void
    {
        $product = $this->makeProduct(['billing_cycle' => 'annual']);
        ProductPricing::create(['product_id' => $product->id, 'billing_cycle' => 'monthly', 'price' => 100.00]);
        ProductPricing::create(['product_id' => $product->id, 'billing_cycle' => 'annual', 'price' => 1000.00]);

        $addon = $this->makeAddon($product);
        $this->addPricingRow($addon, 'annual', 300.00);
        $user = $this->makeCustomerUser();

        $response = $this->actingAs($user)->get(route('client.store.show', $product));

        $response->assertOk();

        // The annual tier is the product's own cycle, so it is the selected
        // radio of the ladder: the annual matrix row prices the add-on.
        $label = $this->addonLabelHtml((string) $response->getContent(), $addon->id);
        $this->assertStringContainsString('₹300.00', $label);
        $this->assertStringContainsString('billed with product cycle', $label);
        $this->assertStringNotContainsString('₹25.00', $label);
        $this->assertStringNotContainsString('(Monthly)', $label);
    }

    public function test_storefront_addon_without_matrix_rows_keeps_its_base_price(): void
    {
        $product = $this->makeProduct();
        $addon = $this->makeAddon($product);
        $user = $this->makeCustomerUser();

        $response = $this->actingAs($user)->get(route('client.store.show', $product));

        $response->assertOk();
        $response->assertSee($addon->name);

        $label = $this->addonLabelHtml((string) $response->getContent(), $addon->id);
        $this->assertStringContainsString('₹25.00', $label);
        $this->assertStringContainsString('(Monthly)', $label);
        $this->assertStringContainsString('₹50.00', $label);
        $this->assertStringNotContainsString('billed with product cycle', $label);
    }

    public function test_admin_order_form_exposes_addon_pricing_matrix(): void
    {
        $admin = $this->adminUser();
        $product = $this->makeProduct();
        $addon = $this->makeAddon($product);
        $this->addPricingRow($addon, 'monthly', 40.00, 60.00);
        $this->addPricingRow($addon, 'annual', 400.00);

        $response = $this->actingAs($admin)->get(route('admin.orders.create'));

        $response->assertOk();

        $map = $this->embeddedJson((string) $response->getContent(), 'window.orderAddons = ');
        $this->assertArrayHasKey((string) $product->id, $map);

        $entries = $map[(string) $product->id];
        $this->assertCount(1, $entries);
        $this->assertSame($addon->id, (int) $entries[0]['id']);
        $this->assertSame(40.0, (float) $entries[0]['pricing']['monthly']['price']);
        $this->assertSame(60.0, (float) $entries[0]['pricing']['monthly']['setup_fee']);
        $this->assertSame(400.0, (float) $entries[0]['pricing']['annual']['price']);
        $this->assertSame(0.0, (float) $entries[0]['pricing']['annual']['setup_fee']);
    }
}
