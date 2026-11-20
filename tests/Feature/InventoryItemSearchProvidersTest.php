<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceType;
use App\Models\InventoryItem;
use App\Models\User;
use App\Services\Search\GlobalSearchService;
use App\Services\Search\Providers\InventoryItemSearchProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesChatUsers;
use Tests\TestCase;

/**
 * The inventory-items provider group.
 *
 * Mirrors the sibling devices provider suite: each test drives
 * `GlobalSearchService::groups()` with an explicit one-provider registry, so a
 * presence or absence assertion cannot be masked by an unrelated provider. The
 * gate is the real seeded `devices.view` permission — the same one the
 * inventory routes carry (`routes/admin/inventory.php`).
 */
class InventoryItemSearchProvidersTest extends TestCase
{
    use CreatesChatUsers {
        chatUser as panelUserWith;
    }
    use RefreshDatabase;

    public function test_an_inventory_item_is_found_by_its_serial_number_and_links_to_its_show_page(): void
    {
        $device = $this->makeDevice();

        $item = InventoryItem::create([
            'device_id' => $device->id,
            'name' => 'Power Supply A',
            'serial_number' => 'SN-ITEM-0001',
            'status' => 'installed',
        ]);
        InventoryItem::create([
            'device_id' => $device->id,
            'name' => 'Riser Card B',
            'status' => 'installed',
        ]);

        $user = $this->panelUserWith('devices.view');
        $groups = $this->groupsFor($user, 'SN-ITEM-0001');

        $group = $groups['inventory-items'] ?? null;

        $this->assertNotNull($group);
        $this->assertSame('Inventory Items', $group['label']);
        $this->assertCount(1, $group['results']);

        $result = $group['results'][0];

        $this->assertSame($item->id, $result['id']);
        $this->assertSame('Power Supply A', $result['label']);
        $this->assertStringContainsString('Item · SN SN-ITEM-0001', (string) $result['subtitle']);
        $this->assertSame(route('admin.inventory-items.show', $item), $result['url']);
    }

    public function test_the_inventory_items_group_is_absent_for_a_viewer_without_inventory_view(): void
    {
        $device = $this->makeDevice();

        InventoryItem::create([
            'device_id' => $device->id,
            'name' => 'Power Supply A',
            'serial_number' => 'SN-ITEM-0002',
            'status' => 'installed',
        ]);

        $user = $this->panelUserWith('products.view');
        $groups = $this->groupsFor($user, 'SN-ITEM-0002');

        $this->assertArrayNotHasKey('inventory-items', $groups);
        $this->assertSame([], $groups);
    }

    // --- helpers ------------------------------------------------------------

    /**
     * @return array<string, array<string, mixed>>
     */
    private function groupsFor(User $user, string $term, int $limit = 5): array
    {
        $service = new GlobalSearchService([InventoryItemSearchProvider::class]);

        return collect($service->groups($service->permissionNames($user), $term, $limit))
            ->keyBy('key')
            ->all();
    }

    private function makeDevice(array $overrides = []): Device
    {
        return Device::create(array_merge([
            'asset_tag' => 'DEV-SEARCH-'.Str::upper(Str::random(6)),
            'device_type_id' => (int) DeviceType::factory()->create(['category' => 'server'])->id,
        ], $overrides));
    }
}
