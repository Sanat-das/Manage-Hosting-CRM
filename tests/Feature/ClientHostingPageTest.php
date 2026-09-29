<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\Product;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientHostingPageTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomerUser(): Customer
    {
        $user = User::factory()->create();

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Hosting Corp',
            'status' => 'active',
        ]);
    }

    private function makeProduct(string $name = 'Shared Hosting Basic'): Product
    {
        return Product::create([
            'name' => $name,
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'show_in_order' => true,
            'only_admin' => false,
            'status' => 'active',
        ]);
    }

    private function makeServer(string $name = 'Server 1'): Server
    {
        return Server::create([
            'name' => $name,
            'ip_address' => '192.168.1.1',
            'panel_type' => 'custom',
            'status' => 'active',
        ]);
    }

    public function test_customer_sees_products_services_listing_with_their_account(): void
    {
        $customer = $this->makeCustomerUser();
        $product = $this->makeProduct('Shared Hosting Basic');
        $server = $this->makeServer('Server 1');

        HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'server_id' => $server->id,
            'username' => 'hostuser',
            'domain' => 'example.com',
            'status' => 'active',
        ]);

        $response = $this->actingAs($customer->user)
            ->get(route('client.hosting.index'))
            ->assertOk();

        $response->assertSee('Products/Services');
        $response->assertSee('Shared Hosting Basic');
        $response->assertSee('example.com');
        // The shipped client listing has no Server column (Product / Domain /
        // IP / Billing / Next Due / Status / Details); the row's Details
        // action proves the account rendered.
        $response->assertSee('Details');
    }

    public function test_customer_with_no_hosting_sees_empty_state(): void
    {
        $customer = $this->makeCustomerUser();

        $response = $this->actingAs($customer->user)
            ->get(route('client.hosting.index'))
            ->assertOk();

        $response->assertSee('Products/Services');
        // Shipped unfiltered empty state: friendly copy plus store CTA.
        // assertSeeText escapes the needle and strips tags from the response, so
        // the apostrophe matches under both encodings — previously this used a
        // raw needle, which only worked while the copy was literal template text
        // rather than a component prop escaped by Blade (ENT_QUOTES).
        $response->assertSeeText("You don't have any products or services yet.");
        $response->assertSee('Browse the Store');
    }
}
