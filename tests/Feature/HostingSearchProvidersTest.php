<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Domain;
use App\Models\HostingAccount;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\SslCertificate;
use App\Models\User;
use App\Services\Search\GlobalSearchService;
use App\Services\Search\Providers\DomainSearchProvider;
use App\Services\Search\Providers\HostingAccountSearchProvider;
use App\Services\Search\Providers\ServerSearchProvider;
use App\Services\Search\Providers\ServiceInstanceSearchProvider;
use App\Services\Search\Providers\SslCertificateSearchProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The hosting/infrastructure provider group.
 *
 * The role grants exercised here are the REAL seeded ones: TestCase::setUp()
 * seeds AdminLteRbacSeeder, and `staff` deliberately holds `domains.view` but
 * NOT `hosting.view` (database/seeders/AdminLteRbacSeeder.php), while
 * `support` holds `hosting.view`. The critical contract is that a staff
 * viewer receives no hosting-account / server / SSL group while still
 * receiving the domains group.
 */
class HostingSearchProvidersTest extends TestCase
{
    use RefreshDatabase;

    // --- permission gating -------------------------------------------------

    public function test_staff_without_hosting_view_gets_no_hosting_server_or_ssl_groups_but_does_get_domains(): void
    {
        $customer = $this->makeCustomer();
        $this->makeHostingAccount($customer, 'host-acme-01', 'acmehost', 'acme.example');
        $this->makeServer('acme-node-1', '10.0.0.1');
        $this->makeSslCertificate($customer, 'acme.example');
        $this->makeDomain($customer, 'acme.example');

        $user = $this->panelUser('staff');
        $service = $this->service();
        $permissions = $service->permissionNames($user);

        // Preconditions read off the real seeded role matrix.
        $this->assertContains('domains.view', $permissions);
        $this->assertNotContains('hosting.view', $permissions);
        $this->assertNotContains('service-instances.view', $permissions);

        $groups = $service->groups($permissions, 'acme', 5);
        $keys = array_column($groups, 'key');

        $this->assertContains('domains', $keys);
        $this->assertNotContains('hosting', $keys, 'Hosting accounts leaked to a viewer without hosting.view');
        $this->assertNotContains('servers', $keys, 'Servers leaked to a viewer without hosting.view');
        $this->assertNotContains('ssl', $keys, 'SSL certificates leaked to a viewer without hosting.view');
        $this->assertNotContains('service-instances', $keys);
    }

    public function test_support_holding_hosting_view_gets_the_server_group(): void
    {
        $this->makeServer('acme-node-1', '10.0.0.1');

        $user = $this->panelUser('support');
        $service = $this->service();
        $permissions = $service->permissionNames($user);

        $this->assertContains('hosting.view', $permissions);

        $groups = $service->groups($permissions, 'acme', 5);

        $this->assertContains('servers', array_column($groups, 'key'));
    }

    // --- identifier lookup + exact URL --------------------------------------

    public function test_a_server_is_found_by_its_ip_address_and_links_to_its_show_page(): void
    {
        $server = $this->makeServer('acme-node-1', '10.0.0.1');

        $user = $this->panelUser('support');
        $service = $this->service();
        $groups = $service->groups($service->permissionNames($user), '10.0.0.1', 5);

        $group = $this->group($groups, 'servers');

        $this->assertNotNull($group);
        $this->assertCount(1, $group['results']);
        $this->assertSame('acme-node-1', $group['results'][0]['label']);
        $this->assertSame(route('admin.servers.show', $server), $group['results'][0]['url']);
    }

    public function test_a_service_instance_is_found_by_its_service_tag_and_links_to_its_show_page(): void
    {
        $customer = $this->makeCustomer();
        $instance = ServiceInstance::create([
            'customer_id' => $customer->id,
            'service_tag' => 'svc-acme-0001',
            'username' => 'acme',
            'domain' => 'acme.example',
            'status' => 'active',
        ]);

        // `admin` is the seeded role that holds service-instances.view.
        $user = $this->panelUser('admin');
        $service = $this->service();
        $permissions = $service->permissionNames($user);

        $this->assertContains('service-instances.view', $permissions);

        $groups = $service->groups($permissions, 'svc-acme-0001', 5);
        $group = $this->group($groups, 'service-instances');

        $this->assertNotNull($group);
        $this->assertCount(1, $group['results']);
        $this->assertSame('svc-acme-0001', $group['results'][0]['label']);
        $this->assertSame(route('admin.service-instances.show', $instance), $group['results'][0]['url']);
    }

    // --- wildcard escaping --------------------------------------------------

    public function test_a_literal_percent_in_a_hosting_identifier_is_matched_as_a_literal_not_a_wildcard(): void
    {
        $customer = $this->makeCustomer();
        $literal = $this->makeHostingAccount($customer, 'host-50%', 'percentuser', 'percent.example');
        $this->makeHostingAccount($customer, 'host-501', 'ordinaryuser', 'ordinary.example');

        $user = $this->panelUser('support');
        $service = $this->service();

        DB::enableQueryLog();
        $groups = $service->groups($service->permissionNames($user), '%', 5);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $group = $this->group($groups, 'hosting');

        $this->assertNotNull($group);
        $this->assertCount(1, $group['results']);
        $this->assertSame('host-50%', $group['results'][0]['label']);
        $this->assertSame(route('admin.hosting.show', $literal), $group['results'][0]['url']);

        $hostingQuery = collect($queries)->first(
            fn (array $query): bool => str_contains($query['query'], 'hosting_accounts'),
        );

        $this->assertNotNull($hostingQuery, 'No hosting_accounts query was run.');
        $this->assertStringContainsString("ESCAPE '!'", $hostingQuery['query']);
        $this->assertContains('%!%%', $hostingQuery['bindings']);
    }

    // --- helpers ------------------------------------------------------------

    private function service(): GlobalSearchService
    {
        return new GlobalSearchService([
            ServiceInstanceSearchProvider::class,
            HostingAccountSearchProvider::class,
            ServerSearchProvider::class,
            DomainSearchProvider::class,
            SslCertificateSearchProvider::class,
        ]);
    }

    /**
     * A panel user whose access comes from the seeded role of that name.
     */
    private function panelUser(string $role): User
    {
        return User::factory()->create(['role' => $role])->fresh();
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @return array<string, mixed>|null
     */
    private function group(array $groups, string $key): ?array
    {
        foreach ($groups as $group) {
            if ($group['key'] === $key) {
                return $group;
            }
        }

        return null;
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create(['role' => 'client']);

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Acme Hosting',
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

    private function makeDomain(Customer $customer, string $name): Domain
    {
        return Domain::create([
            'customer_id' => $customer->id,
            'name' => $name,
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
