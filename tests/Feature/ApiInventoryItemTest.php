<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceType;
use App\Models\InventoryItem;
use App\Models\Manufacturer;
use App\Models\User;
use App\Services\Inventory\InventoryChangeLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Inventory items REST API (Api\InventoryItemController).
 *
 * Mirrors the ApiInventoryAssetTest harness: real seeded RBAC (admin holds
 * devices.view/manage, client does not), Sanctum bearer tokens, and the
 * soft-deletes-aware unique rules on live rows only.
 */
class ApiInventoryItemTest extends TestCase
{
    use RefreshDatabase;

    private int $deviceSequence = 0;

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/inventory-items')->assertStatus(401);
    }

    public function test_client_token_is_forbidden_from_index(): void
    {
        $this->actingAsApi($this->clientUser())
            ->getJson('/api/inventory-items')
            ->assertForbidden();
    }

    public function test_index_returns_paginated_envelope(): void
    {
        $device = $this->makeDevice();

        foreach (['Alpha', 'Bravo', 'Charlie', 'Delta'] as $name) {
            $this->makeItem(['device_id' => $device->id, 'name' => $name]);
        }

        $response = $this->actingAsApi($this->apiUser())->getJson('/api/inventory-items?per_page=3&page=2');

        $response->assertOk();
        $response->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('meta.total', 4);
        $response->assertJsonPath('meta.current_page', 2);
        $response->assertJsonPath('meta.last_page', 2);
        $response->assertJsonPath('meta.per_page', 3);
    }

    public function test_index_caps_per_page_at_100(): void
    {
        $this->makeItem();

        $response = $this->actingAsApi($this->apiUser())->getJson('/api/inventory-items?per_page=500');

        $response->assertOk();
        $response->assertJsonPath('meta.per_page', 100);
    }

    public function test_index_lists_items_ordered_by_name_ascending(): void
    {
        $device = $this->makeDevice();

        $this->makeItem(['device_id' => $device->id, 'name' => 'Zeta']);
        $this->makeItem(['device_id' => $device->id, 'name' => 'Alpha']);
        $this->makeItem(['device_id' => $device->id, 'name' => 'Mid']);

        $response = $this->actingAsApi($this->apiUser())->getJson('/api/inventory-items');

        $response->assertOk();
        $this->assertSame(['Alpha', 'Mid', 'Zeta'], $response->json('data.*.name'));
    }

    public function test_index_search_matches_serial_number(): void
    {
        $this->makeItem(['serial_number' => 'SN-FIND-0001', 'name' => 'Power Supply A']);
        $this->makeItem(['serial_number' => 'SN-OTHER-0002', 'name' => 'Riser Card']);

        $response = $this->actingAsApi($this->apiUser())->getJson('/api/inventory-items?search=SN-FIND-0001');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Power Supply A');
    }

    public function test_index_search_matches_asset_tag(): void
    {
        $this->makeItem(['asset_tag' => 'TAG-FIND-0001', 'name' => 'Tagged Item']);
        $this->makeItem(['asset_tag' => 'TAG-OTHER-0002', 'name' => 'Untagged Item']);

        $response = $this->actingAsApi($this->apiUser())->getJson('/api/inventory-items?search=TAG-FIND-0001');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Tagged Item');
    }

    public function test_index_search_matches_sku(): void
    {
        $this->makeItem(['sku' => 'SKU-FIND-0001', 'name' => 'Sku Item']);
        $this->makeItem(['sku' => 'SKU-OTHER-0002', 'name' => 'Other Sku Item']);

        $response = $this->actingAsApi($this->apiUser())->getJson('/api/inventory-items?search=SKU-FIND-0001');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Sku Item');
    }

    public function test_index_search_matches_manufacturer_name(): void
    {
        $manufacturer = $this->makeManufacturer('Acme Embedded');

        $this->makeItem(['name' => 'Acme Part', 'manufacturer_id' => $manufacturer->id]);
        $this->makeItem(['name' => 'Generic Part']);

        $response = $this->actingAsApi($this->apiUser())->getJson('/api/inventory-items?search=Acme%20Embedded');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Acme Part');
    }

    public function test_store_creates_item_returns_201_with_boolean_discovered(): void
    {
        $device = $this->makeDevice();

        $response = $this->actingAsApi($this->apiUser())->postJson('/api/inventory-items', [
            'device_id' => $device->id,
            'name' => 'Power Supply A',
            'serial_number' => 'SN-STORE-0001',
            'discovered' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'Power Supply A');
        $response->assertJsonPath('data.discovered', true);
        $this->assertDatabaseHas('inventory_items', [
            'id' => $response->json('data.id'),
            'discovered' => 1,
        ]);
        $this->assertTrue($this->freshItem($response->json('data.id'))->discovered);
    }

    public function test_store_requires_device_id(): void
    {
        $response = $this->actingAsApi($this->apiUser())->postJson('/api/inventory-items', [
            'name' => 'Orphan Item',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('device_id');
    }

    public function test_store_rejects_a_nonexistent_device(): void
    {
        $response = $this->actingAsApi($this->apiUser())->postJson('/api/inventory-items', [
            'device_id' => 999999,
            'name' => 'Orphan Item',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('device_id');
    }

    public function test_store_rejects_a_duplicate_sibling_name(): void
    {
        $device = $this->makeDevice();
        $this->makeItem(['device_id' => $device->id, 'name' => 'Riser Card']);

        $response = $this->actingAsApi($this->apiUser())->postJson('/api/inventory-items', [
            'device_id' => $device->id,
            'name' => 'Riser Card',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');
        $this->assertSame(1, InventoryItem::where('device_id', $device->id)->where('name', 'Riser Card')->count());
    }

    public function test_store_allows_the_same_name_on_a_different_device(): void
    {
        $this->makeItem(['name' => 'Riser Card']);

        $otherDevice = $this->makeDevice();

        $response = $this->actingAsApi($this->apiUser())->postJson('/api/inventory-items', [
            'device_id' => $otherDevice->id,
            'name' => 'Riser Card',
        ]);

        $response->assertStatus(201);
    }

    public function test_store_rejects_an_invalid_status(): void
    {
        $device = $this->makeDevice();

        $response = $this->actingAsApi($this->apiUser())->postJson('/api/inventory-items', [
            'device_id' => $device->id,
            'name' => 'Power Supply A',
            'status' => 'not-a-real-status',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('status');
    }

    public function test_update_persists_changes_including_the_discovered_toggle(): void
    {
        $item = $this->makeItem(['discovered' => false]);

        $response = $this->actingAsApi($this->apiUser())->putJson("/api/inventory-items/{$item->id}", [
            'name' => 'Updated Name',
            'discovered' => true,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Updated Name');
        $response->assertJsonPath('data.discovered', true);
        $this->assertDatabaseHas('inventory_items', [
            'id' => $item->id,
            'name' => 'Updated Name',
            'discovered' => 1,
        ]);
    }

    public function test_destroy_soft_deletes_item(): void
    {
        $item = $this->makeItem();

        $response = $this->actingAsApi($this->apiUser())->deleteJson("/api/inventory-items/{$item->id}");

        $response->assertOk();
        $this->assertSoftDeleted('inventory_items', ['id' => $item->id]);
    }

    public function test_moving_a_parented_item_to_another_device_requires_a_parent_decision(): void
    {
        $deviceA = $this->makeDevice();
        $deviceB = $this->makeDevice();
        $parent = $this->makeItem(['device_id' => $deviceA->id, 'name' => 'Slot Parent']);
        $child = $this->makeItem(['device_id' => $deviceA->id, 'parent_item_id' => $parent->id, 'name' => 'Daughter Item']);

        $response = $this->actingAsApi($this->apiUser())->putJson("/api/inventory-items/{$child->id}", [
            'device_id' => $deviceB->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('device_id');

        $response = $this->actingAsApi($this->apiUser())->putJson("/api/inventory-items/{$child->id}", [
            'device_id' => $deviceB->id,
            'parent_item_id' => null,
        ]);

        $response->assertOk();
        $this->assertSame($deviceB->id, (int) $child->refresh()->device_id);
        $this->assertNull($child->parent_item_id);
    }

    public function test_moving_an_item_with_children_to_another_device_fails_validation(): void
    {
        $deviceA = $this->makeDevice();
        $deviceB = $this->makeDevice();
        $parent = $this->makeItem(['device_id' => $deviceA->id, 'name' => 'Chassis Parent']);
        $this->makeItem(['device_id' => $deviceA->id, 'parent_item_id' => $parent->id, 'name' => 'Slot Child']);

        $response = $this->actingAsApi($this->apiUser())->putJson("/api/inventory-items/{$parent->id}", [
            'device_id' => $deviceB->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('device_id');
        $this->assertSame($deviceA->id, (int) $parent->refresh()->device_id);
    }

    public function test_show_includes_device_manufacturer_and_children_count(): void
    {
        $device = $this->makeDevice(['asset_tag' => 'DEV-SHOW-0001']);
        $manufacturer = $this->makeManufacturer('Acme Embedded');
        $item = $this->makeItem([
            'device_id' => $device->id,
            'manufacturer_id' => $manufacturer->id,
        ]);
        $this->makeItem([
            'device_id' => $device->id,
            'parent_item_id' => $item->id,
            'name' => 'Child Module',
        ]);

        $response = $this->actingAsApi($this->apiUser())->getJson("/api/inventory-items/{$item->id}");

        $response->assertOk();
        $response->assertJsonPath('data.device.id', $device->id);
        $response->assertJsonPath('data.device.asset_tag', 'DEV-SHOW-0001');
        $response->assertJsonPath('data.manufacturer.id', $manufacturer->id);
        $response->assertJsonPath('data.manufacturer.name', 'Acme Embedded');
        $response->assertJsonPath('data.children_count', 1);
    }

    public function test_store_persists_empty_strings_as_null_for_nullable_fields(): void
    {
        $device = $this->makeDevice();

        $response = $this->actingAsApi($this->apiUser())->postJson('/api/inventory-items', [
            'device_id' => $device->id,
            'name' => 'Blank Tag Item',
            'asset_tag' => '',
            'sku' => '',
        ]);

        $response->assertStatus(201);

        $item = $this->freshItem($response->json('data.id'));

        $this->assertNull($item->asset_tag);
        $this->assertNull($item->sku);
    }

    public function test_update_persists_empty_strings_as_null_for_nullable_fields(): void
    {
        $item = $this->makeItem(['asset_tag' => 'TAG-KEEP-1', 'sku' => 'SKU-KEEP-1']);

        $response = $this->actingAsApi($this->apiUser())->putJson("/api/inventory-items/{$item->id}", [
            'asset_tag' => '',
            'sku' => '',
        ]);

        $response->assertOk();

        $this->assertNull($item->refresh()->asset_tag);
        $this->assertNull($item->refresh()->sku);
    }

    public function test_destroy_soft_deletes_the_subtree_and_writes_one_audit_row_each(): void
    {
        $device = $this->makeDevice();
        $parent = $this->makeItem(['device_id' => $device->id, 'name' => 'API Subtree Parent']);
        $child = $this->makeItem(['device_id' => $device->id, 'name' => 'API Subtree Child', 'parent_item_id' => $parent->id]);
        $grandchild = $this->makeItem(['device_id' => $device->id, 'name' => 'API Subtree Grandchild', 'parent_item_id' => $child->id]);

        $response = $this->actingAsApi($this->apiUser())->deleteJson("/api/inventory-items/{$parent->id}");

        $response->assertOk();
        $this->assertSoftDeleted('inventory_items', ['id' => $parent->id]);
        $this->assertSoftDeleted('inventory_items', ['id' => $child->id]);
        $this->assertSoftDeleted('inventory_items', ['id' => $grandchild->id]);

        $rows = AuditLog::query()
            ->where('entity_type', 'inventory_item')
            ->where('action', 'deleted')
            ->whereIn('entity_id', [$parent->id, $child->id, $grandchild->id])
            ->get();

        $this->assertCount(3, $rows);
        $this->assertEqualsCanonicalizing(
            [$parent->id, $child->id, $grandchild->id],
            $rows->pluck('entity_id')->map(fn ($id) => (int) $id)->all(),
        );
    }

    public function test_destroy_rolls_back_when_the_item_audit_write_fails(): void
    {
        $item = $this->makeItem(['name' => 'API Rollback Parent']);
        $child = $this->makeItem([
            'device_id' => $item->device_id,
            'parent_item_id' => $item->id,
            'name' => 'API Rollback Child',
        ]);

        $this->mock(InventoryChangeLogger::class, fn ($mock) => $mock->allows('record')->andReturnFalse());

        try {
            $this->actingAsApi($this->apiUser())->deleteJson("/api/inventory-items/{$item->id}");
            $this->fail('Expected the item audit log failure to abort the item deletion.');
        } catch (\RuntimeException) {
        }

        $this->assertNull($item->refresh()->deleted_at);
        $this->assertNull($child->refresh()->deleted_at);
        $this->assertSame(0, AuditLog::where('entity_type', 'inventory_item')->where('action', 'deleted')
            ->whereIn('entity_id', [$item->id, $child->id])->count());
    }

    private function apiUser(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        $user->assignRole('admin');

        return $user;
    }

    private function clientUser(): User
    {
        $user = User::factory()->create(['role' => 'client']);
        $user->assignRole('client');

        return $user;
    }

    private function actingAsApi(User $user): self
    {
        $token = $user->createToken('test-token')->plainTextToken;

        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ]);
    }

    private function makeDevice(array $overrides = []): Device
    {
        $this->deviceSequence++;

        return Device::create(array_merge([
            'asset_tag' => 'DEV-API-'.$this->deviceSequence,
            'device_type_id' => (int) DeviceType::factory()->create(['category' => 'server'])->id,
        ], $overrides));
    }

    private function makeManufacturer(string $name): Manufacturer
    {
        return Manufacturer::create([
            'name' => $name,
            'slug' => strtolower(str_replace(' ', '-', $name)).'-'.uniqid(),
        ]);
    }

    private function freshItem(int $id): InventoryItem
    {
        $item = InventoryItem::findOrFail($id);
        $item->refresh();

        return $item;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeItem(array $overrides = []): InventoryItem
    {
        $device = $overrides['device_id'] ?? $this->makeDevice()->id;
        unset($overrides['device_id']);

        return InventoryItem::create(array_merge([
            'device_id' => $device,
            'name' => 'Item '.uniqid(),
        ], $overrides));
    }
}
