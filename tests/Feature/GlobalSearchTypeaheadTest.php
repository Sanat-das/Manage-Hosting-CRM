<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\CreatesChatUsers;
use Tests\TestCase;

/**
 * The JSON typeahead endpoint: the exact payload contract, the short-query
 * 200-empty envelope, permission filtering, wildcard escaping and the
 * dedicated `throttle:search` budget exercised on the REAL route.
 */
class GlobalSearchTypeaheadTest extends TestCase
{
    use CreatesChatUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Rate-limiter counters live in the cache (array store under phpunit);
        // clear them so a re-run or a previous test cannot leak hits into the
        // 301-request throttle assertion.
        Cache::flush();
        RateLimiter::clear('search');
    }

    // --- helpers ----------------------------------------------------------

    private function makeCustomer(string $email, string $company): Customer
    {
        $user = User::factory()->create(['email' => $email, 'role' => 'client']);

        return Customer::create([
            'user_id' => $user->id,
            'company' => $company,
            'status' => 'active',
        ]);
    }

    private function makeTicket(string $ticketNo, string $subject): Ticket
    {
        return Ticket::create([
            'ticket_no' => $ticketNo,
            'subject' => $subject,
            'priority' => 'medium',
            'status' => 'open',
            'department' => 'support',
        ]);
    }

    // --- exact schema -----------------------------------------------------

    public function test_the_payload_matches_the_exact_json_schema_for_a_seeded_match(): void
    {
        $customer = $this->makeCustomer('acme-client@example.com', 'Acme Hosting');
        $user = $this->chatUser('search', 'customers.view');

        $response = $this->actingAs($user)
            ->getJson(route('admin.search.typeahead', ['q' => 'acme']))
            ->assertOk();

        $payload = $response->json();

        // Top level: exactly these three keys, in this order.
        $this->assertSame(['query', 'groups', 'total'], array_keys($payload));
        $this->assertSame('acme', $payload['query']);
        $this->assertSame(1, $payload['total']);
        $this->assertCount(1, $payload['groups']);

        // Group: exactly these four keys - the service's internal
        // `has_more`/`list_url` are full-page concerns and must not leak.
        $group = $payload['groups'][0];
        $this->assertSame(['key', 'label', 'icon', 'results'], array_keys($group));
        $this->assertSame('customers', $group['key']);
        $this->assertSame('Customers', $group['label']);
        $this->assertSame('bi bi-people', $group['icon']);
        $this->assertCount(1, $group['results']);

        // Result: exactly these four keys, with a server-resolved URL.
        $result = $group['results'][0];
        $this->assertSame(['id', 'label', 'subtitle', 'url'], array_keys($result));
        $this->assertSame($customer->id, $result['id']);
        $this->assertSame('Acme Hosting', $result['label']);
        $this->assertSame('acme-client@example.com', $result['subtitle']);
        $this->assertSame(route('admin.customers.show', $customer), $result['url']);

        $encoded = (string) json_encode($payload);
        $this->assertStringNotContainsString('has_more', $encoded);
        $this->assertStringNotContainsString('list_url', $encoded);
    }

    // --- short query: 200 empty envelope, never 422 -----------------------

    public function test_a_query_shorter_than_two_characters_returns_the_empty_envelope_with_http_200(): void
    {
        // Non-vacuity: a row the query WOULD match if it were searched.
        $this->makeCustomer('acme-client@example.com', 'Acme Hosting');

        $user = $this->chatUser('search', 'customers.view');

        foreach (['a', '', ' '] as $short) {
            $this->actingAs($user)
                ->getJson(route('admin.search.typeahead', ['q' => $short]))
                ->assertOk()
                ->assertExactJson(['query' => '', 'groups' => [], 'total' => 0]);
        }

        // A missing `q` entirely is the same envelope, not a validation error.
        $this->actingAs($user)
            ->getJson(route('admin.search.typeahead'))
            ->assertOk()
            ->assertExactJson(['query' => '', 'groups' => [], 'total' => 0]);
    }

    // --- overlong query: 422 ----------------------------------------------

    public function test_a_query_longer_than_100_characters_returns_422(): void
    {
        $this->actingAs($this->chatUser('search'))
            ->getJson(route('admin.search.typeahead', ['q' => str_repeat('a', 101)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');
    }

    public function test_an_array_q_is_rejected_with_422(): void
    {
        $this->actingAs($this->chatUser('search'))
            ->getJson(route('admin.search.typeahead', ['q' => ['a']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');
    }

    // --- auth gates -------------------------------------------------------

    public function test_a_guest_is_redirected_to_the_login_page(): void
    {
        // Plain browser-style GET (no JSON Accept): the `auth` middleware
        // redirects, proving the route is registered and gated.
        $this->get(route('admin.search.typeahead', ['q' => 'acme']))
            ->assertRedirect(route('admin.login'));
    }

    public function test_a_guest_asking_for_json_is_still_redirected_to_login(): void
    {
        // `shouldRenderJsonWhen()` is scoped to `api/*` in bootstrap/app.php,
        // so the framework's unauthenticated handler answers 302 here even for
        // a JSON Accept header. The plan contract asks for the 302 redirect;
        // the security-relevant property is that the route is never reachable
        // without a session.
        $this->getJson(route('admin.search.typeahead', ['q' => 'acme']))
            ->assertRedirect(route('admin.login'));
    }

    public function test_an_authenticated_panel_user_without_the_search_permission_is_forbidden(): void
    {
        $this->makeCustomer('acme-client@example.com', 'Acme Hosting');

        $this->actingAs($this->chatUser('customers.view'))
            ->getJson(route('admin.search.typeahead', ['q' => 'acme']))
            ->assertForbidden();
    }

    // --- permission filtering ---------------------------------------------

    public function test_a_customers_only_viewer_gets_no_other_entity_groups(): void
    {
        $this->makeCustomer('acme-client@example.com', 'Acme Hosting');
        $this->makeTicket('TKT-ACME-1', 'Acme is down');
        CatalogProduct::create([
            'name' => 'Acme Hosting Plan',
            'sku' => 'ACME-HOST-1',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->chatUser('search', 'customers.view'))
            ->getJson(route('admin.search.typeahead', ['q' => 'acme']))
            ->assertOk();

        $this->assertSame(['customers'], array_column($response->json('groups'), 'key'));
        $this->assertSame(1, $response->json('total'));
    }

    public function test_an_invoices_only_viewer_gets_zero_groups_when_only_non_billing_rows_match(): void
    {
        $this->makeCustomer('acme-client@example.com', 'Acme Hosting');
        $this->makeTicket('TKT-ACME-1', 'Acme is down');

        $response = $this->actingAs($this->chatUser('search', 'invoices.view'))
            ->getJson(route('admin.search.typeahead', ['q' => 'acme']))
            ->assertOk();

        $this->assertSame([], $response->json('groups'));
        $this->assertSame(0, $response->json('total'));
    }

    // --- wildcard escaping -------------------------------------------------

    public function test_wildcard_characters_are_matched_literally_and_a_lone_percent_is_short_circuited(): void
    {
        $percent = $this->makeCustomer('percent@example.com', 'Acme 100% Uptime');
        $this->makeCustomer('hundred-x@example.com', 'Acme 100x Uptime');
        $underscore = $this->makeCustomer('underscore@example.com', 'Acme 0_ Legacy');
        $this->makeCustomer('zero-x@example.com', 'Acme 0X Legacy');

        $user = $this->chatUser('search', 'customers.view');

        // `q=100%`: escaped, only the literal-percent row matches. An
        // unescaped `%100%%` would also return "Acme 100x Uptime".
        $response = $this->actingAs($user)
            ->getJson(route('admin.search.typeahead', ['q' => '100%']))
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame(['Acme 100% Uptime'], array_column($response->json('groups.0.results'), 'label'));
        $this->assertSame(route('admin.customers.show', $percent), $response->json('groups.0.results.0.url'));

        // `q=0_`: escaped, only the literal-underscore row matches. An
        // unescaped `%0_%` would also return "Acme 0X Legacy".
        $response = $this->actingAs($user)
            ->getJson(route('admin.search.typeahead', ['q' => '0_']))
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame(['Acme 0_ Legacy'], array_column($response->json('groups.0.results'), 'label'));
        $this->assertSame(route('admin.customers.show', $underscore), $response->json('groups.0.results.0.url'));

        // A lone `%` is 1 character, so it takes the 200-empty envelope
        // rather than degenerating into a full-table dump.
        $this->actingAs($user)
            ->getJson(route('admin.search.typeahead', ['q' => '%']))
            ->assertOk()
            ->assertExactJson(['query' => '', 'groups' => [], 'total' => 0]);
    }

    public function test_a_sql_injection_attempt_is_escaped_and_matches_nothing(): void
    {
        $this->makeCustomer('acme-client@example.com', 'Acme Hosting');

        $this->actingAs($this->chatUser('search', 'customers.view'))
            ->getJson(route('admin.search.typeahead', ['q' => "' OR 1=1 --"]))
            ->assertOk()
            ->assertJsonPath('groups', [])
            ->assertJsonPath('total', 0);

        $this->assertDatabaseCount('customers', 1);
    }

    // --- throttle on the real route ----------------------------------------

    public function test_the_real_typeahead_route_allows_300_requests_and_rejects_the_301st(): void
    {
        $this->actingAs($this->chatUser('search'));

        // `q=a` takes the short-query fast path, so the loop measures the
        // throttle, not the search itself.
        for ($i = 1; $i <= 300; $i++) {
            $response = $this->getJson(route('admin.search.typeahead', ['q' => 'a']));

            $this->assertSame(200, $response->getStatusCode(), "Request #{$i} must be allowed (200).");
        }

        $this->getJson(route('admin.search.typeahead', ['q' => 'a']))
            ->assertStatus(429);
    }
}
