<?php

namespace Tests\Unit;

use App\Models\Product;
use Tests\TestCase;

/**
 * Product::requiresIp() is flag-driven: the per-product require_public_ip /
 * require_private_ip checkboxes (product edit page) declare whether
 * provisioning needs an IP — any product type can require one. The legacy
 * type-based rule (vps/dedicated) is replaced by the migration backfilling
 * require_public_ip on existing vps/dedicated rows.
 */
class ProductRequiresIpTest extends TestCase
{
    public function test_returns_true_when_public_ip_required(): void
    {
        $product = new Product(['require_public_ip' => true]);

        $this->assertTrue($product->requiresIp());
        $this->assertTrue($product->requiresPublicIp());
        $this->assertFalse($product->requiresPrivateIp());
    }

    public function test_returns_true_when_private_ip_required(): void
    {
        $product = new Product(['require_private_ip' => true]);

        $this->assertTrue($product->requiresIp());
        $this->assertTrue($product->requiresPrivateIp());
        $this->assertFalse($product->requiresPublicIp());
    }

    public function test_returns_true_when_both_ips_required(): void
    {
        $product = new Product(['require_public_ip' => true, 'require_private_ip' => true]);

        $this->assertTrue($product->requiresIp());
        $this->assertTrue($product->requiresPublicIp());
        $this->assertTrue($product->requiresPrivateIp());
    }

    public function test_returns_false_when_no_ip_flags_set(): void
    {
        // No IP flags on any product — regardless of the other attributes that
        // used to be carried by the removed products.type column — means no IP
        // is required. IP leasing is driven purely by the two flags.
        $this->assertFalse((new Product([]))->requiresIp());
        $this->assertFalse((new Product(['name' => 'VPS']))->requiresIp());
        $this->assertFalse((new Product(['is_bundle' => true]))->requiresIp());
        $this->assertFalse((new Product(['status' => 'active', 'quantity_behaviour' => 'none']))->requiresIp());
        $this->assertFalse((new Product(['show_in_order' => true, 'price' => 9.99]))->requiresIp());
    }
}
