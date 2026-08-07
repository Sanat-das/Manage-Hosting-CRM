<?php

namespace Tests\Unit;

use App\Models\Product;
use Tests\TestCase;

class ProductRequiresIpTest extends TestCase
{
    public function test_returns_true_for_vps(): void
    {
        $this->assertTrue((new Product(['type' => 'vps']))->requiresIp());
    }

    public function test_returns_true_for_dedicated(): void
    {
        $this->assertTrue((new Product(['type' => 'dedicated']))->requiresIp());
    }

    public function test_returns_false_for_shared_hosting(): void
    {
        $this->assertFalse((new Product(['type' => 'shared_hosting']))->requiresIp());
    }

    public function test_returns_false_for_domain(): void
    {
        $this->assertFalse((new Product(['type' => 'domain']))->requiresIp());
        $this->assertFalse((new Product(['type' => 'addon']))->requiresIp());
    }
}
