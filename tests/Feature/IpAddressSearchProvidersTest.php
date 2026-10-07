<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IpAddress;
use App\Models\IpSubnet;
use App\Models\User;
use App\Services\Search\GlobalSearchService;
use App\Services\Search\Providers\IpAddressSearchProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesChatUsers;
use Tests\TestCase;

/**
 * The ip-addresses provider group.
 *
 * Mirrors the sibling inventory/hosting provider suites: each test drives
 * `GlobalSearchService::groups()` with an explicit one-provider registry, so a
 * presence or absence assertion cannot be masked by an unrelated provider. The
 * gate is the real seeded `ip-addresses.view` permission — the same one the IP
 * address routes carry (`routes/admin/enterprise.php`).
 */
class IpAddressSearchProvidersTest extends TestCase
{
    use CreatesChatUsers {
        chatUser as panelUserWith;
    }
    use RefreshDatabase;

    // --- identifier lookup + exact URL --------------------------------------

    public function test_an_ip_address_is_found_by_its_address_and_links_to_its_show_page(): void
    {
        $subnet = $this->makeSubnet();
        $ip = $this->makeIp($subnet, '10.95.30.14');

        $user = $this->panelUserWith('ip-addresses.view');
        $groups = $this->groupsFor($user, '10.95.30.14');

        $group = $groups['ip-addresses'] ?? null;

        $this->assertNotNull($group);
        $this->assertSame('IP Addresses', $group['label']);
        $this->assertCount(1, $group['results']);

        $result = $group['results'][0];

        $this->assertSame($ip->id, $result['id']);
        $this->assertSame('10.95.30.14', $result['label']);
        $this->assertSame(route('admin.ip-addresses.show', $ip), $result['url']);
    }

    /**
     * The provider mirrors the standalone picker: a term may match the owning
     * subnet's name or CIDR through the address's own belongsTo relation.
     */
    public function test_an_ip_address_is_found_by_its_subnet_name_and_cidr(): void
    {
        $subnet = $this->makeSubnet('Northwind Subnet', '10.95.31.0/24');
        $ip = $this->makeIp($subnet, '10.95.31.9');

        $user = $this->panelUserWith('ip-addresses.view');

        $byName = $this->groupsFor($user, 'Northwind');

        $this->assertArrayHasKey('ip-addresses', $byName);
        $this->assertSame($ip->id, $byName['ip-addresses']['results'][0]['id']);

        $byCidr = $this->groupsFor($user, '10.95.31.0');

        $this->assertArrayHasKey('ip-addresses', $byCidr);
        $this->assertSame($ip->id, $byCidr['ip-addresses']['results'][0]['id']);
    }

    // --- permission gating --------------------------------------------------

    public function test_the_ip_address_group_is_absent_for_a_viewer_without_ip_addresses_view(): void
    {
        $subnet = $this->makeSubnet();
        $this->makeIp($subnet, '10.95.30.15');

        $user = $this->panelUserWith('products.view');
        $groups = $this->groupsFor($user, '10.95.30');

        $this->assertArrayNotHasKey('ip-addresses', $groups);
        $this->assertSame([], $groups);
    }

    // --- helpers ------------------------------------------------------------

    /**
     * @return array<string, array<string, mixed>>
     */
    private function groupsFor(User $user, string $term, int $limit = 5): array
    {
        $service = new GlobalSearchService([IpAddressSearchProvider::class]);

        return collect($service->groups($service->permissionNames($user), $term, $limit))
            ->keyBy('key')
            ->all();
    }

    private function makeSubnet(string $name = 'Edge Subnet', string $cidr = '10.95.30.0/24'): IpSubnet
    {
        return IpSubnet::create([
            'name' => $name,
            'subnet_cidr' => $cidr,
            'network_type' => 'private',
        ]);
    }

    private function makeIp(IpSubnet $subnet, string $address): IpAddress
    {
        return IpAddress::create([
            'subnet_id' => $subnet->id,
            'ip_address' => $address,
        ]);
    }
}
