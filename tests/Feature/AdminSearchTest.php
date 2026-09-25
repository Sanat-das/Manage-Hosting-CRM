<?php

namespace Tests\Feature;

use App\Http\Middleware\PermissionMiddleware;
use App\Models\CatalogProduct;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceInstance;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\Concerns\CreatesChatUsers;
use Tests\TestCase;

class AdminSearchTest extends TestCase
{
    use CreatesChatUsers;
    use RefreshDatabase;

    /**
     * An admin who may reach the results page AND see every group the existing
     * contracts assert. The page route is gated on `search` (the group itself
     * checks each provider's own permission), so the fixture has to hold both
     * the route gate and the per-provider gates its assertions rely on.
     */
    private function actingAsAdmin()
    {
        $user = User::factory()->create();

        // Seed the admin role and search-view permissions in test DB
        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        foreach ([
            'search',
            'dashboard.view',
            'customers.view',
            'invoices.view',
            'service-instances.view',
            'tickets.view',
            'kb.view',
            'catalog-products.view',
        ] as $permName) {
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

    // --- malformed input (F2 fix: shared 2/100 contract on the page) --------

    public function test_an_array_q_is_rejected_with_a_redirect_and_a_validation_error(): void
    {
        // `?q[]=a` must never reach the controller's string cast (that cast
        // would emit an "Array to string conversion" warning and 500). A
        // browser-style request gets Laravel's standard redirect-back.
        $response = $this->actingAsAdmin()->get('/admin/search?q[]=a');

        $response->assertStatus(302);
        $response->assertSessionHasErrors('q');
    }

    public function test_an_array_q_returns_422_for_a_json_request(): void
    {
        $this->actingAsAdmin()
            ->getJson('/admin/search?q[]=a')
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');
    }

    public function test_a_query_longer_than_100_characters_is_rejected(): void
    {
        $this->actingAsAdmin()
            ->getJson('/admin/search?q='.str_repeat('a', 101))
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');
    }

    public function test_a_query_of_exactly_100_characters_is_accepted(): void
    {
        // The boundary itself is valid: max is inclusive on both surfaces.
        $this->actingAsAdmin()
            ->get('/admin/search?q='.str_repeat('a', 100))
            ->assertStatus(200);
    }

    public function test_an_overlong_q_is_rejected_with_a_redirect_and_a_validation_error_for_a_browser_request(): void
    {
        $response = $this->actingAsAdmin()
            ->from('/admin/search?q=acme')
            ->get('/admin/search?q='.str_repeat('a', 101));

        $response->assertStatus(302);
        $response->assertSessionHasErrors('q');
    }

    public function test_the_results_page_surfaces_the_q_validation_error_after_a_redirect_back(): void
    {
        // Exactly what a browser does: the rejected request 302s back to the
        // results page and the followed page renders the flashed error. This is
        // the user-visible proof; without the slot the page looked unchanged.
        $page = $this->actingAsAdmin()
            ->followingRedirects()
            ->from('/admin/search?q=acme')
            ->get('/admin/search?q='.str_repeat('a', 101));

        $page->assertOk();
        $page->assertSee('data-search-error', false);
        $page->assertSee('is-invalid', false);
        // The exact message the validator produced, not a copy of it.
        $page->assertSee(__('validation.max.string', ['attribute' => 'q', 'max' => 100]));

        // A clean request (no flashed error) carries no error slot at all.
        $this->get('/admin/search?q=acme')
            ->assertOk()
            ->assertDontSee('data-search-error', false);
    }

    // --- grouped results page (todo 9) -------------------------------------

    private function makeCustomer(string $email, string $company): Customer
    {
        $clientUser = User::factory()->create(['email' => $email]);
        $clientUser->assignRole('client');

        return Customer::create([
            'user_id' => $clientUser->id,
            'company' => $company,
            'status' => 'active',
        ]);
    }

    public function test_a_seeded_acme_request_shows_a_customers_group_with_a_view_all_link(): void
    {
        $customer = $this->makeCustomer('acme-client@example.com', 'Acme Hosting');

        $html = $this->actingAsAdmin()->get('/admin/search?q=acme')->assertOk()->getContent();

        // Group header: label and the count of the rows actually rendered.
        $this->assertStringContainsString('Customers (1)', $html);

        // Result row deep-links to the server-resolved show URL.
        $this->assertStringContainsString('href="'.route('admin.customers.show', $customer).'"', $html);

        // "View all" is the pagination path: the customers list, pre-filtered
        // with the same term.
        $this->assertStringContainsString('View all', $html);
        $this->assertStringContainsString(
            'href="'.route('admin.customers.index', ['search' => 'acme']).'"',
            $html
        );
    }

    public function test_a_group_capped_at_ten_results_is_flagged_with_a_plus_count(): void
    {
        for ($i = 1; $i <= 11; $i++) {
            $this->makeCustomer("acme-{$i}@example.com", "Acme Hosting {$i}");
        }

        $html = $this->actingAsAdmin()->get('/admin/search?q=acme')->assertOk()->getContent();

        // `limit + 1` was fetched, so the truncated group reports "10+".
        $this->assertStringContainsString('Customers (10+)', $html);

        // Exactly ten rows render — the LIMIT, never a COUNT(*).
        $this->assertSame(10, substr_count($html, 'data-search-result'));
    }

    public function test_a_viewer_without_customers_view_sees_no_customers_group(): void
    {
        $customer = $this->makeCustomer('acme-client@example.com', 'Acme Hosting');

        // Holds `search` (so the page is reachable) but NOT `customers.view`.
        $html = $this->actingAs($this->chatUser('search'))
            ->get('/admin/search?q=acme')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Acme Hosting', $html);
        $this->assertStringNotContainsString(route('admin.customers.index'), $html);
        $this->assertStringNotContainsString(route('admin.customers.show', $customer), $html);

        // Non-vacuous: the page really ran the search and found nothing.
        $this->assertStringContainsString('No results found', $html);
    }

    public function test_a_panel_user_without_the_search_permission_is_forbidden_from_the_results_page(): void
    {
        $this->makeCustomer('acme-client@example.com', 'Acme Hosting');

        // Reachable at the door (dashboard.view) but missing the page's gate.
        $this->actingAs($this->chatUser('dashboard.view'))
            ->get('/admin/search?q=acme')
            ->assertForbidden();
    }

    public function test_the_results_page_route_carries_the_search_permission_and_dedicated_throttle(): void
    {
        $route = app('router')->getRoutes()->getByName('admin.search.index');

        $this->assertNotNull($route, 'Route [admin.search.index] must exist.');

        $middleware = app('router')->gatherRouteMiddleware($route);

        $this->assertContains(PermissionMiddleware::class.':search', $middleware);
        $this->assertContains(ThrottleRequests::class.':search', $middleware);
        $this->assertNotContains(
            ThrottleRequests::class.':admin',
            $middleware,
            'The results page must opt out of `throttle:admin` so one request is charged to a single bucket.'
        );
    }
}
