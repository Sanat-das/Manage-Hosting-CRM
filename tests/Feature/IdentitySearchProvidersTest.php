<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\User;
use App\Services\Search\GlobalSearchService;
use App\Services\Search\Providers\ContactSearchProvider;
use App\Services\Search\Providers\CustomerSearchProvider;
use App\Services\Search\Providers\StaffUserSearchProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesChatUsers;
use Tests\TestCase;

/**
 * The identity provider group: customers, customer contacts and staff users.
 *
 * Each case drives the real `GlobalSearchService` with an explicit provider
 * list and real fixture rows, so what is asserted is the shipped pipeline
 * (permission gate, escaped LIKE, SQL-side ranking, server-resolved URL), not
 * a provider in isolation. Query counts are read off `DB::getQueryLog()` —
 * never assumed.
 */
class IdentitySearchProvidersTest extends TestCase
{
    use CreatesChatUsers {
        chatUser as panelUserWith;
    }
    use RefreshDatabase;

    // --- customers ---------------------------------------------------------

    public function test_customer_is_found_by_the_linked_user_email(): void
    {
        $viewer = $this->panelUserWith('customers.view');
        $customer = $this->makeCustomer('acme-client@example.com', 'Acme Hosting');

        $groups = $this->groupsFor($viewer, [CustomerSearchProvider::class], 'acme-client');

        $this->assertCount(1, $groups);
        $this->assertSame('customers', $groups[0]['key']);
        $this->assertSame('Customers', $groups[0]['label']);
        $this->assertCount(1, $groups[0]['results']);
        $this->assertSame($customer->id, $groups[0]['results'][0]['id']);
        $this->assertSame('Acme Hosting', $groups[0]['results'][0]['label']);
        $this->assertSame('acme-client@example.com', $groups[0]['results'][0]['subtitle']);
        $this->assertSame(route('admin.customers.show', $customer), $groups[0]['results'][0]['url']);
        $this->assertSame(
            route('admin.customers.index', ['search' => 'acme-client']),
            $groups[0]['list_url'],
        );
    }

    public function test_customer_is_found_by_company(): void
    {
        $viewer = $this->panelUserWith('customers.view');
        $customer = $this->makeCustomer('owner@example.com', 'Acme Hosting');

        $groups = $this->groupsFor($viewer, [CustomerSearchProvider::class], 'Acme Hosting');

        $this->assertCount(1, $groups);
        $this->assertSame($customer->id, $groups[0]['results'][0]['id']);
        $this->assertSame('Acme Hosting', $groups[0]['results'][0]['label']);
    }

    public function test_customer_is_absent_for_a_viewer_without_customers_view(): void
    {
        $viewer = $this->panelUserWith('tickets.view');
        $this->makeCustomer('acme-client@example.com', 'Acme Hosting');

        DB::enableQueryLog();
        $groups = $this->groupsFor($viewer, [CustomerSearchProvider::class], 'acme');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], $groups);
        // Only the single permission pluck ran; the provider was never asked.
        $this->assertLessThanOrEqual(
            1,
            count($queries),
            'SQL run: '.implode(' | ', array_column($queries, 'query')),
        );
    }

    // --- contacts ----------------------------------------------------------

    public function test_contact_is_found_by_email_and_by_name(): void
    {
        $viewer = $this->panelUserWith('customers.view');
        $parent = $this->makeCustomer('parent@example.com', 'Parent Co');
        $contact = $this->makeContact($parent, 'Jane', 'Doe', 'jane.doe@example.com');

        foreach (['jane.doe@example.com', 'Doe'] as $term) {
            $groups = $this->groupsFor($viewer, [ContactSearchProvider::class], $term);

            $this->assertCount(1, $groups, "term: {$term}");
            $this->assertSame($contact->id, $groups[0]['results'][0]['id'], "term: {$term}");
        }
    }

    public function test_contact_result_url_is_the_parent_customer_show_page(): void
    {
        $viewer = $this->panelUserWith('customers.view');
        $this->makeCustomer('other@example.com', 'Other Co');
        $parent = $this->makeCustomer('parent@example.com', 'Parent Co');
        $contact = $this->makeContact($parent, 'Jane', 'Doe', 'jane.doe@example.com');

        $groups = $this->groupsFor($viewer, [ContactSearchProvider::class], 'jane.doe');

        $this->assertCount(1, $groups);
        $this->assertSame('contacts', $groups[0]['key']);
        $this->assertSame('Contacts', $groups[0]['label']);
        $this->assertSame($contact->id, $groups[0]['results'][0]['id']);
        $this->assertSame('Jane Doe', $groups[0]['results'][0]['label']);
        $this->assertSame('jane.doe@example.com', $groups[0]['results'][0]['subtitle']);

        // Exact string, and specifically NOT the contact's own key bound into
        // the customer route (a wrong id would 404 or open another customer).
        $this->assertSame(route('admin.customers.show', $parent), $groups[0]['results'][0]['url']);
        $this->assertNotSame(route('admin.customers.show', $contact), $groups[0]['results'][0]['url']);
        $this->assertSame(
            route('admin.customers.index', ['search' => 'jane.doe']),
            $groups[0]['list_url'],
        );
    }

    public function test_contact_without_a_loaded_parent_falls_back_to_the_customer_list(): void
    {
        $parent = $this->makeCustomer('parent@example.com', 'Parent Co');
        $contact = $this->makeContact($parent, 'Jane', 'Doe', 'jane.doe@example.com');
        $contact->setRelation('customer', null);

        $result = (new ContactSearchProvider)->toResult($contact);

        $this->assertSame(route('admin.customers.index'), $result['url']);
        $this->assertSame('Jane Doe', $result['label']);
        $this->assertSame('jane.doe@example.com', $result['subtitle']);
    }

    public function test_contact_group_eager_loads_the_customer_relation_for_its_url(): void
    {
        $viewer = $this->panelUserWith('customers.view');
        $parent = $this->makeCustomer('parent@example.com', 'Parent Co');
        $this->makeContact($parent, 'Jane', 'Doe', 'jane.doe@example.com');

        $service = new GlobalSearchService([ContactSearchProvider::class]);

        DB::enableQueryLog();
        $groups = $service->groups($service->permissionNames($viewer), 'jane', 5);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $groups);

        // 1 permission pluck + 1 contact query + 1 eager load of customer.
        // A lazy `$model->customer` inside toResult() would add a query per row.
        $this->assertLessThanOrEqual(
            3,
            count($queries),
            'SQL run: '.implode(' | ', array_column($queries, 'query')),
        );
    }

    public function test_contact_ranking_prefers_the_email_column(): void
    {
        $viewer = $this->panelUserWith('customers.view');
        $parent = $this->makeCustomer('owner@example.com', 'Ranking Co');

        // Exact first-name match, but its email is not the identifier.
        $this->makeContact($parent, 'acme', 'Zeta', 'unrelated@example.com');
        // No exact column match; the email is what the term identifies.
        $emailMatch = $this->makeContact($parent, 'acmex', 'Alpha', 'acme@example.com');

        $groups = $this->groupsFor($viewer, [ContactSearchProvider::class], 'acme', 1);

        $this->assertCount(1, $groups);
        $this->assertSame($emailMatch->id, $groups[0]['results'][0]['id']);
    }

    // --- staff -------------------------------------------------------------

    public function test_staff_user_is_found_by_email_and_by_name(): void
    {
        $viewer = $this->panelUserWith('users.view');
        $staff = User::factory()->create([
            'email' => 'alice.nguyen@example.com',
            'first_name' => 'Alice',
            'last_name' => 'Nguyentest',
            'role' => 'staff',
        ]);

        $byEmail = $this->groupsFor($viewer, [StaffUserSearchProvider::class], 'alice.nguyen@example.com');

        $this->assertCount(1, $byEmail);
        $this->assertSame('staff', $byEmail[0]['key']);
        $this->assertSame('Staff', $byEmail[0]['label']);
        $this->assertSame($staff->id, $byEmail[0]['results'][0]['id']);
        $this->assertSame('Alice Nguyentest', $byEmail[0]['results'][0]['label']);
        $this->assertSame('alice.nguyen@example.com', $byEmail[0]['results'][0]['subtitle']);
        $this->assertSame(route('admin.users.show', $staff), $byEmail[0]['results'][0]['url']);
        $this->assertSame(
            route('admin.users.index', ['search' => 'alice.nguyen@example.com']),
            $byEmail[0]['list_url'],
        );

        $byName = $this->groupsFor($viewer, [StaffUserSearchProvider::class], 'Nguyentest');

        $this->assertCount(1, $byName);
        $this->assertSame($staff->id, $byName[0]['results'][0]['id']);
    }

    public function test_client_accounts_are_excluded_from_the_staff_provider(): void
    {
        $viewer = $this->panelUserWith('users.view');

        User::factory()->create([
            'email' => 'client-acme-identity@example.com',
            'first_name' => 'Client',
            'last_name' => 'Account',
            'role' => 'client',
        ]);
        $staff = User::factory()->create([
            'email' => 'staff-acme-identity@example.com',
            'first_name' => 'Staff',
            'last_name' => 'Member',
            'role' => 'staff',
        ]);

        $groups = $this->groupsFor($viewer, [StaffUserSearchProvider::class], 'acme-identity');

        // Exactly the staff row: the client row with an equally matching email
        // is filtered out in SQL, and the staff row proves matching still runs.
        $this->assertCount(1, $groups);
        $this->assertCount(1, $groups[0]['results']);
        $this->assertSame($staff->id, $groups[0]['results'][0]['id']);
        $this->assertSame('staff-acme-identity@example.com', $groups[0]['results'][0]['subtitle']);
    }

    // --- permission leak + wildcard escaping -------------------------------

    public function test_a_viewer_with_only_tickets_view_gets_no_identity_groups(): void
    {
        $viewer = $this->panelUserWith('tickets.view');

        $customer = $this->makeCustomer('acme-client@example.com', 'Acme Hosting');
        $this->makeContact($customer, 'Jane', 'Doe', 'jane.doe@acme.example');
        User::factory()->create([
            'email' => 'staff-acme@example.com',
            'first_name' => 'Alice',
            'last_name' => 'Nguyentest',
            'role' => 'staff',
        ]);

        DB::enableQueryLog();
        $groups = $this->groupsFor($viewer, [
            CustomerSearchProvider::class,
            ContactSearchProvider::class,
            StaffUserSearchProvider::class,
        ], 'acme');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], $groups);
        // Only the single permission pluck ran; no provider was asked at all.
        $this->assertLessThanOrEqual(
            1,
            count($queries),
            'SQL run: '.implode(' | ', array_column($queries, 'query')),
        );
    }

    public function test_a_literal_percent_is_matched_by_q_percent_without_a_full_table_dump(): void
    {
        $viewer = $this->panelUserWith('customers.view');
        $literal = $this->makeCustomer('literal@example.com', 'Save 50% Now');
        $this->makeCustomer('plain@example.com', 'No Discount Here');

        DB::enableQueryLog();
        $rows = (new CustomerSearchProvider)->query('%', 10);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $rows);
        $this->assertSame($literal->id, $rows->first()->id);
        $this->assertStringContainsString("ESCAPE '!'", $queries[0]['query']);
        $this->assertContains('%!%%', $queries[0]['bindings']);

        // The same through the registry: exactly the literal-percent row, no
        // others — `q=%` must never degenerate into "return everything".
        $groups = $this->groupsFor($viewer, [CustomerSearchProvider::class], '%');

        $this->assertCount(1, $groups);
        $this->assertCount(1, $groups[0]['results']);
        $this->assertSame($literal->id, $groups[0]['results'][0]['id']);
        $this->assertSame('Save 50% Now', $groups[0]['results'][0]['label']);
    }

    // --- helpers -----------------------------------------------------------

    /**
     * @param  list<class-string>  $providerClasses
     * @return list<array{key: string, label: string, icon: string, results: list<array{id: int|string, label: string, subtitle: string|null, url: string}>, has_more: bool, list_url: string|null}>
     */
    private function groupsFor(User $viewer, array $providerClasses, string $term, int $limit = 5): array
    {
        $service = new GlobalSearchService($providerClasses);

        return $service->groups($service->permissionNames($viewer), $term, $limit);
    }

    private function makeCustomer(string $email, string $company): Customer
    {
        $user = User::factory()->create(['email' => $email, 'role' => 'client']);

        return Customer::create([
            'user_id' => $user->id,
            'company' => $company,
            'status' => 'active',
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
}
