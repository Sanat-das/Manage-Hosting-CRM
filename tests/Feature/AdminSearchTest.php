<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceInstance;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSearchTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin()
    {
        $user = User::factory()->create();

        // Seed the admin role and search-view permissions in test DB
        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        foreach (['dashboard.view', 'tickets.view', 'kb.view'] as $permName) {
            $perm = Permission::firstOrCreate(['name' => $permName], ['label' => ucfirst($permName)]);
            $adminRole->permissions()->syncWithoutDetaching($perm->id);
        }

        $user->assignRole('admin');

        return $this->actingAs($user);
    }

    public function test_search_requires_auth(): void
    {
        $response = $this->get('/admin/search?q=acme');
        $response->assertRedirect();
    }

    public function test_search_page_loads_for_admin(): void
    {
        $response = $this->actingAsAdmin()->get('/admin/search?q=acme');
        $response->assertStatus(200);
        $response->assertSee('Search Results');
    }

    public function test_search_finds_customer_by_email(): void
    {
        $clientUser = User::factory()->create(['email' => 'acme-client@example.com']);
        $clientUser->assignRole('client');
        Customer::create(['user_id' => $clientUser->id, 'company' => 'Acme Hosting', 'status' => 'active']);

        $response = $this->actingAsAdmin()->get('/admin/search?q=acme-client');
        $response->assertStatus(200);
        $response->assertSee('acme-client@example.com');
    }

    public function test_search_finds_ticket_and_resolves_show_link(): void
    {
        $adminUser = User::factory()->create();
        $adminUser->assignRole('client');
        $customer = Customer::create(['user_id' => $adminUser->id, 'status' => 'active']);

        $ticket = Ticket::create([
            'customer_id' => $customer->id,
            'ticket_no' => 'TKT-2026-00042',
            'subject' => 'DNS propagation issue for acme.com',
            'priority' => 'medium',
            'status' => 'open',
            'department' => 'support',
        ]);

        $response = $this->actingAsAdmin()->get('/admin/search?q=propagation');
        $response->assertStatus(200);
        $response->assertSee('TKT-2026-00042');
        $response->assertSee(route('admin.tickets.show', $ticket));
    }

    public function test_search_finds_invoice_by_number(): void
    {
        $user = User::factory()->create();
        $user->assignRole('client');
        $customer = Customer::create(['user_id' => $user->id, 'status' => 'active']);

        Invoice::create([
            'customer_id' => $customer->id,
            'invoice_no' => 'INV-2026-00007',
            'amount' => 250.00,
            'tax' => 0,
            'total' => 250.00,
            'status' => 'sent',
            'due_date' => now()->addDays(7),
        ]);

        $response = $this->actingAsAdmin()->get('/admin/search?q=INV-2026-00007');
        $response->assertStatus(200);
        $response->assertSee('INV-2026-00007');
    }

    public function test_search_finds_service_instance(): void
    {
        $user = User::factory()->create();
        $user->assignRole('client');
        $customer = Customer::create(['user_id' => $user->id, 'status' => 'active']);

        $product = CatalogProduct::create([
            'name' => 'Basic Shared Hosting',
            'sku' => 'HOST-SHARED-01',
            'status' => 'active',
        ]);

        ServiceInstance::create([
            'customer_id' => $customer->id,
            'catalog_product_id' => $product->id,
            'service_tag' => 'svc-acme-0001',
            'service_type' => 'shared',
            'username' => 'acme',
            'domain' => 'acme.com',
            'status' => 'active',
        ]);

        $response = $this->actingAsAdmin()->get('/admin/search?q=acme.com');
        $response->assertStatus(200);
        $response->assertSee('acme.com');
    }

    public function test_search_finds_catalog_product(): void
    {
        CatalogProduct::create([
            'name' => 'Basic Shared Hosting',
            'sku' => 'HOST-SHARED-01',
            'status' => 'active',
        ]);

        $response = $this->actingAsAdmin()->get('/admin/search?q=HOST-SHARED-01');
        $response->assertStatus(200);
        $response->assertSee('HOST-SHARED-01');
    }

    public function test_search_short_query_returns_empty_results(): void
    {
        $response = $this->actingAsAdmin()->get('/admin/search?q=a');
        $response->assertStatus(200);
        // The results section (and its empty-state message) must not render
        // for queries shorter than 2 characters.
        $response->assertDontSee('No results found for');
    }
}
