<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\HostingAccount;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\SslCertificate;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Search\AbstractSearchProvider;
use App\Services\Search\GlobalSearchService;
use App\Services\Search\LikePattern;
use App\Services\Search\Providers\CustomerSearchProvider;
use App\Services\Search\Providers\HostingAccountSearchProvider;
use App\Services\Search\Providers\ServerSearchProvider;
use App\Services\Search\Providers\ServiceInstanceSearchProvider;
use App\Services\Search\Providers\SslCertificateSearchProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\CreatesChatUsers;
use Tests\TestCase;

/**
 * The shared foundation every provider is built on: escaping, ranking,
 * permission gating and the query budget.
 *
 * The stubs at the bottom stand in for the 17 production providers so the
 * contract stays provable while those classes land.
 */
class GlobalSearchServiceTest extends TestCase
{
    use CreatesChatUsers {
        chatUser as panelUserWith;
    }
    use RefreshDatabase;

    /**
     * Every distinct permission the 17 providers gate on, so the whole
     * registry is admitted: transactions and quotes share `invoices.view`,
     * the hosting trio shares `hosting.view`, and contacts share
     * `customers.view`.
     */
    private const ALL_PROVIDER_PERMISSIONS = [
        'customers.view',
        'service-instances.view',
        'hosting.view',
        'domains.view',
        'orders.view',
        'invoices.view',
        'payments.view',
        'tickets.view',
        'kb.view',
        'catalog-products.view',
        'products.view',
        'users.view',
    ];

    // --- LikePattern ------------------------------------------------------

    public function test_like_pattern_escapes_percent_underscore_and_the_escape_character(): void
    {
        $this->assertSame('%100!%!_!!%', LikePattern::contains('100%_!'));
    }

    public function test_like_pattern_turns_a_lone_wildcard_into_a_literal_match(): void
    {
        // `q=%` must search for a literal percent sign, not "everything".
        $this->assertSame('%!%%', LikePattern::contains('%'));
    }

    // --- groups() ---------------------------------------------------------

    public function test_a_single_stub_provider_yields_one_group_within_the_query_budget(): void
    {
        $user = $this->panelUserWith('customers.view');
        $this->makeCustomer('acme-client@example.com', 'Acme Hosting');

        $service = new GlobalSearchService([StubCustomerSearchProvider::class]);

        DB::enableQueryLog();

        $groups = $service->groups($service->permissionNames($user), 'acme', 5);
        $queries = DB::getQueryLog();

        DB::disableQueryLog();

        $this->assertCount(1, $groups);
        $this->assertSame('customers', $groups[0]['key']);
        $this->assertSame('Customers', $groups[0]['label']);
        $this->assertCount(1, $groups[0]['results']);
        $this->assertSame('Acme Hosting', $groups[0]['results'][0]['label']);
        $this->assertSame('acme-client@example.com', $groups[0]['results'][0]['subtitle']);
        $this->assertStringContainsString('/admin/customers/', $groups[0]['results'][0]['url']);
        $this->assertSame(route('admin.customers.index', ['search' => 'acme']), $groups[0]['list_url']);
        $this->assertFalse($groups[0]['has_more']);

        // 1 permission pluck + 1 provider query + 1 eager load. Never assumed:
        // the budget is read off the query log.
        $this->assertLessThanOrEqual(
            3,
            count($queries),
            'SQL run: '.implode(' | ', array_column($queries, 'query')),
        );
    }

    public function test_a_provider_is_skipped_when_the_user_lacks_its_permission(): void
    {
        $user = $this->panelUserWith('tickets.view');
        $this->makeCustomer('acme-client@example.com', 'Acme Hosting');

        $service = new GlobalSearchService([StubCustomerSearchProvider::class]);

        $this->assertSame([], $service->groups($service->permissionNames($user), 'acme', 5));
    }

    public function test_a_provider_class_that_does_not_exist_yet_is_skipped_without_throwing(): void
    {
        $user = $this->panelUserWith('customers.view');
        $this->makeCustomer('acme-client@example.com', 'Acme Hosting');

        $service = new GlobalSearchService([
            'App\\Services\\Search\\Providers\\NotCreatedYetSearchProvider',
            StubCustomerSearchProvider::class,
        ]);

        $groups = $service->groups($service->permissionNames($user), 'acme', 5);

        $this->assertCount(1, $groups);
        $this->assertSame('customers', $groups[0]['key']);
    }

    public function test_a_provider_with_a_missing_route_is_skipped_and_logs_once(): void
    {
        Log::spy();

        $provider = new StubMissingRouteSearchProvider;

        $this->assertTrue($provider->query('acme', 5)->isEmpty());
        $this->assertTrue($provider->query('acme', 5)->isEmpty());

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'route'));
    }

    public function test_groups_fetch_one_extra_row_to_report_a_capped_count_without_a_count_query(): void
    {
        $user = $this->panelUserWith('customers.view');
        $this->makeCustomer('acme-1@example.com', 'Acme One');
        $this->makeCustomer('acme-2@example.com', 'Acme Two');
        $this->makeCustomer('acme-3@example.com', 'Acme Three');

        $service = new GlobalSearchService([StubCustomerSearchProvider::class]);

        DB::enableQueryLog();
        $groups = $service->groups($service->permissionNames($user), 'acme', 2);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $groups);
        $this->assertCount(2, $groups[0]['results']);
        $this->assertTrue($groups[0]['has_more']);
        $this->assertStringNotContainsString('count(*)', strtolower(implode(' | ', array_column($queries, 'query'))));
    }

    public function test_a_blank_term_returns_no_groups_and_runs_no_provider_query(): void
    {
        $user = $this->panelUserWith('customers.view');
        $this->makeCustomer('acme-client@example.com', 'Acme Hosting');

        $service = new GlobalSearchService([StubCustomerSearchProvider::class]);

        DB::enableQueryLog();
        $groups = $service->groups($service->permissionNames($user), '   ', 5);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], $groups);
        // The permission pluck is the only query; no provider was asked.
        $this->assertLessThanOrEqual(1, count($queries));
    }

    // --- escaping + ranking -----------------------------------------------

    public function test_provider_sql_uses_the_escape_clause_and_percent_matches_only_a_literal_percent(): void
    {
        $this->makeTicket('TKT-50%', 'Literal percent sign');
        $this->makeTicket('TKT-200', 'Ordinary row');

        DB::enableQueryLog();
        $rows = (new StubTicketSearchProvider)->query('%', 10);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $sql = $queries[0]['query'];

        $this->assertStringContainsString("ESCAPE '!'", $sql);
        $this->assertContains('%!%%', $queries[0]['bindings']);
        $this->assertCount(1, $rows);
        $this->assertSame('TKT-50%', $rows->first()->ticket_no);
    }

    public function test_ranking_is_applied_before_the_limit_so_an_exact_match_is_never_cut_off(): void
    {
        // Created first, so `id DESC` alone would rank it last.
        $exact = $this->makeTicket('TKT-ACME', 'Exact match');
        $this->makeTicket('TKT-ACME-EXTRA', 'Longer identifier');
        $this->makeTicket('TKT-ACME-MORE', 'Longer identifier again');

        $rows = (new StubTicketSearchProvider)->query('TKT-ACME', 1);

        $this->assertCount(1, $rows);
        $this->assertSame($exact->id, $rows->first()->id);
    }

    public function test_exact_matches_rank_before_partial_matches(): void
    {
        $this->makeTicket('TKT-ACME', 'Partial one');
        $this->makeTicket('TKT-ACME-EXTRA', 'Partial two');

        $rows = (new StubTicketSearchProvider)->query('TKT-ACME', 10);

        $this->assertSame(['TKT-ACME', 'TKT-ACME-EXTRA'], $rows->pluck('ticket_no')->all());
    }

    // --- permission-aware queryFor ----------------------------------------

    public function test_query_for_passes_the_permission_set_to_the_base_query_hook_and_returns_its_constrained_rows(): void
    {
        $this->makeTicket('TKT-ACME-OPEN', 'Acme open ticket');
        $this->makeTicket('TKT-ACME-CLOSED', 'Acme closed ticket', 'closed');

        $provider = new StubPermissionAwareSearchProvider;

        $rows = $provider->queryFor(['customers.view'], 'acme', 5);

        // The exact array is asserted, not merely "a call happened".
        $this->assertSame(['customers.view'], $provider->receivedPermissionNames);
        $this->assertSame(['TKT-ACME-OPEN'], $rows->pluck('ticket_no')->all());
    }

    public function test_query_without_permissions_keeps_the_default_base_query(): void
    {
        $this->makeTicket('TKT-ACME-OPEN', 'Acme open ticket');
        $this->makeTicket('TKT-ACME-CLOSED', 'Acme closed ticket', 'closed');

        $provider = new StubPermissionAwareSearchProvider;

        $rows = $provider->query('acme', 5);

        $this->assertSame([], $provider->receivedPermissionNames);
        $this->assertSame(['TKT-ACME-CLOSED', 'TKT-ACME-OPEN'], $rows->pluck('ticket_no')->all());
    }

    public function test_groups_passes_the_callers_permission_set_to_the_provider(): void
    {
        $user = $this->panelUserWith('tickets.view');
        $this->makeTicket('TKT-ACME', 'Acme ticket');

        $provider = new StubPermissionAwareSearchProvider;
        $this->app->instance(StubPermissionAwareSearchProvider::class, $provider);

        $service = new GlobalSearchService([StubPermissionAwareSearchProvider::class]);
        $permissionNames = $service->permissionNames($user);

        $groups = $service->groups($permissionNames, 'acme', 5);

        $this->assertContains('tickets.view', $permissionNames);
        $this->assertSame($permissionNames, $provider->receivedPermissionNames);
        $this->assertCount(1, $groups);
        $this->assertSame('tickets', $groups[0]['key']);
    }

    // --- registry-wide query budget (FU-2) --------------------------------

    /**
     * The real 17-provider registry, zero matching rows: one data query per
     * admitted provider + one permission pluck, and nothing else.
     *
     * The ceiling is MEASURED off DB::getQueryLog(), never assumed. Empty
     * result sets fire no eager load, so 17 + 1 = 18 is the whole budget. A
     * per-provider permission check would add 1-2 queries per provider and
     * blow this ceiling, which is the regression this test exists to catch.
     */
    public function test_the_full_registry_stays_within_the_query_budget_with_no_matching_rows(): void
    {
        $user = $this->panelUserWith(...self::ALL_PROVIDER_PERMISSIONS);

        $service = new GlobalSearchService();

        // A shrinking registry must fail loudly here rather than quietly
        // lowering the budget the assertions below measure.
        $this->assertCount(17, $service->providers());

        DB::enableQueryLog();
        $groups = $service->groups($service->permissionNames($user), 'zzz-no-match-zzz', 5);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], $groups);

        $sql = implode(' | ', array_column($queries, 'query'));

        $this->assertLessThanOrEqual(
            18,
            count($queries),
            'Query budget exceeded (17 provider queries + 1 permission pluck): '.$sql,
        );

        $this->assertStringNotContainsString('count(*)', strtolower($sql));
    }

    /**
     * The same registry with rows in two providers, both of which eager load.
     *
     * Budget: 15 zero-match providers x 1 + customer data 1 + `with('user')` 1
     * + contact data 1 + `with('customer')` 1 + permission pluck 1 = 20.
     *
     * Non-vacuity (why the rows matter): a dropped eager load becomes one lazy
     * query PER ROW inside toResult(), so 3 customers would cost 3 instead of 1
     * (22 total) and 2 contacts would cost 2 instead of 1 (21 total) - both
     * above the 20 ceiling. With a single row the count would be identical to
     * the eager-load budget and the assertion could not fail.
     */
    public function test_the_full_registry_stays_within_the_query_budget_with_rows_and_eager_loads(): void
    {
        $user = $this->panelUserWith(...self::ALL_PROVIDER_PERMISSIONS);

        $parent = $this->makeCustomer('acme-1@example.com', 'Acme One');
        $this->makeCustomer('acme-2@example.com', 'Acme Two');
        $this->makeCustomer('acme-3@example.com', 'Acme Three');
        $this->makeContact($parent, 'Jane', 'Doe', 'jane.acme@example.com');
        $this->makeContact($parent, 'John', 'Roe', 'john.acme@example.com');

        $service = new GlobalSearchService();

        $this->assertCount(17, $service->providers());

        DB::enableQueryLog();
        $groups = $service->groups($service->permissionNames($user), 'acme', 5);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Exactly the two seeded groups: every other provider is row-less.
        $this->assertSame(['customers', 'contacts'], array_column($groups, 'key'));

        $sql = implode(' | ', array_column($queries, 'query'));

        $this->assertLessThanOrEqual(
            20,
            count($queries),
            'Query budget exceeded (15 zero-match providers + 2 data queries + 2 eager loads + 1 pluck): '.$sql,
        );

        $this->assertStringNotContainsString('count(*)', strtolower($sql));
    }

    // --- manage implies view (FU-1) ---------------------------------------

    public function test_a_service_instances_manage_only_viewer_gets_the_service_instances_group(): void
    {
        // `service-instances.manage` is a real catalogue permission whose screens
        // are gated on `service-instances.view`, which PermissionMiddleware
        // admits via the manage-implies-view fallback.
        $user = $this->panelUserWith('service-instances.manage');
        $this->makeServiceInstance('svc-acme-0001', 'acme.com');

        $service = new GlobalSearchService([ServiceInstanceSearchProvider::class]);

        $groups = $service->groups($service->permissionNames($user), 'acme', 5);

        $this->assertCount(1, $groups);
        $this->assertSame('service-instances', $groups[0]['key']);
    }

    public function test_a_hosting_manage_only_viewer_gets_the_hosting_server_and_ssl_groups(): void
    {
        // `hosting.manage` implies `hosting.view`, which gates three providers.
        $user = $this->panelUserWith('hosting.manage');
        $customer = $this->makeCustomer('acme-client@example.com', 'Acme Hosting');
        $this->makeHostingAccount($customer, 'acme-host', 'acmeuser', 'acme.com');
        $this->makeServer('Acme Hyper-V', '10.0.0.9');
        $this->makeSslCertificate($customer, 'acme.com');

        $service = new GlobalSearchService([
            HostingAccountSearchProvider::class,
            ServerSearchProvider::class,
            SslCertificateSearchProvider::class,
        ]);

        $this->assertSame(
            ['hosting', 'servers', 'ssl'],
            array_column($service->groups($service->permissionNames($user), 'acme', 5), 'key'),
        );
    }

    public function test_the_manage_expansion_does_not_leak_across_domains(): void
    {
        $this->makeCustomer('acme-client@example.com', 'Acme Hosting');
        $this->makeServer('Acme Hyper-V', '10.0.0.9');

        // A catalog-products manager cannot search customers...
        $catalogManager = $this->panelUserWith('catalog-products.manage');
        $customers = new GlobalSearchService([CustomerSearchProvider::class]);
        $this->assertSame([], $customers->groups($customers->permissionNames($catalogManager), 'acme', 5));

        // ...and a customers viewer gains no hosting group from the expansion.
        $hostingViewer = $this->panelUserWith('customers.view');
        $hosting = new GlobalSearchService([ServerSearchProvider::class]);
        $this->assertSame([], $hosting->groups($hosting->permissionNames($hostingViewer), 'acme', 5));
    }

    public function test_the_manage_expansion_stays_one_query_and_preserves_exact_names(): void
    {
        $user = $this->panelUserWith('hosting.manage', 'customers.view');
        $service = new GlobalSearchService();

        DB::enableQueryLog();
        $names = $service->permissionNames($user);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Still ONE query: the expansion is pure string work on the plucked set.
        $this->assertCount(1, $queries);
        $this->assertCount(3, $names);
        $this->assertContains('hosting.manage', $names);
        $this->assertContains('hosting.view', $names);
        $this->assertContains('customers.view', $names);

        // A set with no `.manage` name comes back untouched.
        $viewer = $this->panelUserWith('customers.view');
        $this->assertSame(['customers.view'], $service->permissionNames($viewer));
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

    private function makeTicket(string $ticketNo, string $subject, string $status = 'open'): Ticket
    {
        return Ticket::create([
            'ticket_no' => $ticketNo,
            'subject' => $subject,
            'priority' => 'medium',
            'status' => $status,
            'department' => 'support',
        ]);
    }

    private function makeContact(Customer $customer, string $firstName, string $lastName, string $email): CustomerContact
    {
        return CustomerContact::create([
            'customer_id' => $customer->id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'status' => 'active',
        ]);
    }

    private function makeServiceInstance(string $serviceTag, string $domain): ServiceInstance
    {
        $customer = $this->makeCustomer($serviceTag.'@example.com', 'Acme Hosting');
        $product = CatalogProduct::create([
            'name' => 'Basic Shared Hosting',
            'sku' => 'HOST-'.strtoupper($serviceTag),
            'status' => 'active',
        ]);

        return ServiceInstance::create([
            'customer_id' => $customer->id,
            'catalog_product_id' => $product->id,
            'service_tag' => $serviceTag,
            'service_type' => 'shared',
            'username' => 'acme',
            'domain' => $domain,
            'status' => 'active',
        ]);
    }

    private function makeHostingAccount(Customer $customer, string $hostName, string $username, string $domain): HostingAccount
    {
        return HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => 1,
            'username' => $username,
            'domain' => $domain,
            'host_name' => $hostName,
            'status' => 'active',
        ]);
    }

    private function makeServer(string $name, string $ipAddress): Server
    {
        return Server::create([
            'name' => $name,
            'ip_address' => $ipAddress,
            'server_type' => 'cpanel',
            'status' => 'active',
        ]);
    }

    private function makeSslCertificate(Customer $customer, string $domainName): SslCertificate
    {
        return SslCertificate::create([
            'customer_id' => $customer->id,
            'domain_name' => $domainName,
            'provider' => 'letsencrypt',
            'status' => 'active',
        ]);
    }
}

/**
 * Customer-backed provider proving the shared foundation without depending on
 * the production provider classes.
 */
class StubCustomerSearchProvider extends AbstractSearchProvider
{
    public function key(): string
    {
        return 'customers';
    }

    public function label(): string
    {
        return 'Customers';
    }

    public function icon(): string
    {
        return 'bi bi-people';
    }

    public function permission(): string
    {
        return 'customers.view';
    }

    public function showRoute(): string
    {
        return 'admin.customers.show';
    }

    public function listRoute(): ?string
    {
        return 'admin.customers.index';
    }

    protected function baseQuery(): Builder
    {
        return Customer::query()->with('user');
    }

    protected function searchableColumns(): array
    {
        return ['company'];
    }

    protected function searchableRelations(): array
    {
        return ['user' => ['email', 'first_name', 'last_name']];
    }

    public function toResult(Model $model): array
    {
        /** @var Customer $model */
        return $this->resultRow($model, (string) $model->company, $model->user?->email);
    }
}

/**
 * Ticket-backed provider: plain columns only, which is what the escaping and
 * ranking tests need.
 */
class StubTicketSearchProvider extends AbstractSearchProvider
{
    public function key(): string
    {
        return 'tickets';
    }

    public function label(): string
    {
        return 'Tickets';
    }

    public function icon(): string
    {
        return 'bi bi-life-preserver';
    }

    public function permission(): string
    {
        return 'tickets.view';
    }

    public function showRoute(): string
    {
        return 'admin.tickets.show';
    }

    public function listRoute(): ?string
    {
        return 'admin.tickets.index';
    }

    protected function baseQuery(): Builder
    {
        return Ticket::query();
    }

    protected function searchableColumns(): array
    {
        return ['ticket_no', 'subject'];
    }

    public function toResult(Model $model): array
    {
        /** @var Ticket $model */
        return $this->resultRow($model, (string) $model->ticket_no, $model->subject);
    }
}

/**
 * A provider whose destination screen is not installed.
 */
class StubMissingRouteSearchProvider extends StubTicketSearchProvider
{
    public function showRoute(): string
    {
        return 'admin.search.missing-route-stub';
    }
}

/**
 * Permission-aware provider: records the permission set `queryFor()` hands to
 * `baseQueryFor()` and narrows the base query whenever a permission is
 * supplied — the stand-in for a real provider whose scope depends on the
 * viewer (e.g. KB published-only unless the viewer may edit). With no
 * permissions (the legacy `query()` path) the default base query is kept.
 */
class StubPermissionAwareSearchProvider extends StubTicketSearchProvider
{
    /** @var list<string> */
    public array $receivedPermissionNames = [];

    protected function baseQueryFor(array $permissionNames): Builder
    {
        $this->receivedPermissionNames = $permissionNames;

        $query = parent::baseQueryFor($permissionNames);

        if ($permissionNames !== []) {
            $query->where('status', '!=', 'closed');
        }

        return $query;
    }
}
