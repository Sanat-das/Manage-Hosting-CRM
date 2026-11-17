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
        $response->assertSee('Details');
        $response->assertSee('Server 1');
        $response->assertSee('Next billing');
        $response->assertSeeText('No domains yet.');
        $response->assertSeeText('Domains you register or add appear here.');
        $response->assertSeeText('No add-ons on your services yet.');
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

    public function test_a_shared_hosting_service_on_a_plain_server_shows_no_vm_surface(): void
    {
        $customer = $this->makeCustomerUser();
        $product = $this->makeProduct('Shared Hosting Basic');
        $server = $this->makeServer('Server 1');

        $account = HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'server_id' => $server->id,
            'username' => 'hostuser',
            'domain' => 'example.com',
            'status' => 'active',
        ]);

        $response = $this->actingAs($customer->user)
            ->get(route('client.hosting.show', $account->id))
            ->assertOk();

        // The host is a plain shared server, not a compute host — the detail
        // page must not offer VM controls the service cannot use.
        $response->assertDontSee('Start VM');
        $response->assertDontSee('Provision VM');
        $response->assertDontSee('Reset Administrator password');
        $response->assertSee('Next steps');
        $response->assertSee('Contact support');
    }
}
