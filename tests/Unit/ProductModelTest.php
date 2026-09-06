<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductOptionGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plan task 4 — product-type-to-groups-options model layer.
 *
 * products.type / quota_* are gone: bundle-ness is the products.is_bundle
 * flag, and option groups attach through the product_option_group_product
 * pivot (many-to-many).
 */
class ProductModelTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Test Product',
            'price' => 0,
            'billing_cycle' => 'monthly',
            'show_in_order' => true,
            'only_admin' => false,
            'status' => 'active',
        ], $attributes));
    }

    public function test_is_bundle_returns_true_when_flag_is_true(): void
    {
        $this->assertTrue($this->makeProduct(['is_bundle' => true])->isBundle());
    }

    public function test_is_bundle_returns_false_when_flag_is_false(): void
    {
        $this->assertFalse($this->makeProduct(['is_bundle' => false])->isBundle());
        $this->assertFalse((new Product)->isBundle());
    }

    public function test_options_returns_only_pivot_attached_groups(): void
    {
        $product = $this->makeProduct();
        $other = $this->makeProduct(['name' => 'Other Product']);

        $a = ProductOptionGroup::create(['name' => 'Operating System', 'type' => 'dropdown']);
        $b = ProductOptionGroup::create(['name' => 'Control Panel', 'type' => 'radio']);
        $unrelated = ProductOptionGroup::create(['name' => 'Disk Size', 'type' => 'quantity']);

        $product->options()->attach([$a->id, $b->id]);

        $this->assertCount(2, $product->options()->get());
        $this->assertEqualsCanonicalizing(
            [$a->id, $b->id],
            $product->options()->pluck('product_option_groups.id')->all()
        );

        // A group attached to another product must not leak into this one.
        $other->options()->attach($unrelated->id);
        $this->assertCount(2, $product->options()->get());

        $product->options()->detach();
        $this->assertCount(0, $product->refresh()->options);
    }

    public function test_option_types_constant_contains_exactly_seven_values(): void
    {
        $this->assertCount(7, ProductOptionGroup::OPTION_TYPES);
        $this->assertSame(
            ['dropdown', 'radio', 'quantity', 'text', 'number', 'slider', 'checkbox'],
            ProductOptionGroup::OPTION_TYPES
        );
    }
}
