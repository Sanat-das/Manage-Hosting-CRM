<?php

namespace Tests\Feature;

use App\Models\DomainPricing;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\DomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DomainSearchAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private function service(): DomainService
    {
        return app(DomainService::class);
    }

    private function actingAsAdmin(): self
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $view = Permission::firstOrCreate(['name' => 'domains.view'], ['label' => 'View Domains']);
        $manage = Permission::firstOrCreate(['name' => 'domains.manage'], ['label' => 'Manage Domains']);
        $adminRole->permissions()->syncWithoutDetaching([$view->id, $manage->id]);

        $user->assignRole('admin');

        return $this->actingAs($user);
    }

    public function test_full_domain_routes_through_rdap(): void
    {
        // RDAP reports the domain as unregistered (404) → available.
        Http::fake([
            'https://rdap.org/domain/*' => Http::response('', 404),
        ]);

        $result = $this->service()->searchAvailability('example.com');

        $this->assertTrue($result['valid']);
        $this->assertNull($result['error']);
        $this->assertCount(1, $result['results']);
        $this->assertTrue($result['results'][0]['available']);
        $this->assertSame('example.com', $result['results'][0]['domain']);
    }

    public function test_rdap_404_means_available(): void
    {
        Http::fake(['https://rdap.org/domain/*' => Http::response('', 404)]);

        $this->assertTrue($this->service()->isAvailable('free-brand.dev'));
    }

    public function test_rdap_200_means_taken(): void
    {
        Http::fake([
            'https://rdap.org/domain/*' => Http::response('{"status":["active"]}', 200),
        ]);

        $this->assertFalse($this->service()->isAvailable('taken-brand.com'));
    }

    public function test_rdap_500_is_unknown_not_fabricated(): void
    {
        // 5xx → unknown (null), never fabricated availability.
        Http::fake([
            'https://rdap.org/domain/*' => Http::response('', 500),
        ]);

        $this->assertNull($this->service()->isAvailable('example.org'));
    }

    public function test_network_failure_is_unknown(): void
    {
        // Connection refused → Http throws → unknown (null).
        Http::fake([
            'https://rdap.org/domain/*' => fn () => throw new ConnectionException('boom'),
        ]);

        $this->assertNull($this->service()->isAvailable('example.net'));
    }

    public function test_unknown_result_keeps_default_price_but_null_availability(): void
    {
        Http::fake([
            'https://rdap.org/domain/*' => fn () => throw new ConnectionException('boom'),
        ]);

        $result = $this->service()->searchAvailability('fallback.co');

        $this->assertCount(1, $result['results']);
        $this->assertNull($result['results'][0]['available']);
        // Deterministic default price table fallback (price > 0), never a guess at availability.
        $this->assertGreaterThan(0, $result['results'][0]['price']);
    }

    public function test_bare_label_expands_across_default_tlds(): void
    {
        Http::fake([
            'https://rdap.org/domain/*' => Http::response('', 404),
        ]);

        $result = $this->service()->searchAvailability('acme');

        // A bare label isn't a full FQDN, but still expands to candidate TLDs.
        $this->assertFalse($result['valid']);
        $this->assertNull($result['error']); // candidates exist → no error message
        $this->assertGreaterThan(1, count($result['results']));
        $this->assertSame('acme.com', $result['results'][0]['domain']);
        $this->assertFalse($result['results'][0]['premium']); // 4-char label → not premium
    }

    public function test_invalid_query_is_rejected(): void
    {
        Http::fake(['https://rdap.org/domain/*' => Http::response('', 404)]);

        $result = $this->service()->searchAvailability('not a valid !!domain');

        $this->assertFalse($result['valid']);
        $this->assertNotNull($result['error']);
    }

    public function test_price_reads_from_domain_pricing_table(): void
    {
        DomainPricing::create([
            'tld' => 'com',
            'register_price' => 1234.00,
            'renew_price' => 1100.00,
            'transfer_price' => 1000.00,
            'currency' => 'INR',
            'enabled' => true,
        ]);

        Http::fake([
            'https://rdap.org/domain/*' => Http::response('', 404),
        ]);

        $result = $this->service()->searchAvailability('example.com');

        $this->assertCount(1, $result['results']);
        $this->assertSame(1234.00, $result['results'][0]['price']);
        $this->assertSame('INR', $result['results'][0]['currency']);
    }

    public function test_search_page_renders_results_for_admin(): void
    {
        // Regression: the controller previously passed the whole search
        // envelope to the view, crashing on $result['domain'] (offset on string).
        Http::fake([
            'https://rdap.org/domain/*' => Http::response('', 404),
        ]);

        $response = $this->actingAsAdmin()
            ->get(route('admin.domains.search', ['q' => 'example.com']));

        $response->assertOk();
        $response->assertSee('example.com');
        $response->assertSee('Available');
        $response->assertSee('Register (coming soon)');
    }

    public function test_search_page_shows_error_for_invalid_query(): void
    {
        Http::fake([
            'https://rdap.org/domain/*' => Http::response('', 404),
        ]);

        $response = $this->actingAsAdmin()
            ->get(route('admin.domains.search', ['q' => 'not a valid !!domain']));

        $response->assertOk();
        $response->assertSee('Enter a valid domain name');
        $response->assertDontSee('Register (coming soon)');
    }
}
