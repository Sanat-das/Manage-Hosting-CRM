<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceType;
use App\Models\InventoryItem;
use App\Models\Manufacturer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Inventory\InventoryChangeLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryItemCrudTest extends TestCase
{
    use RefreshDatabase;

    private int $deviceSequence = 0;

    private int $itemSequence = 0;

    private function actingAsAdmin(): self
    {
        $user = User::factory()->create();

        // Seed the admin role and inventory permissions in the test DB.
        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $view = Permission::firstOrCreate(['name' => 'devices.view'], ['label' => 'View Devices']);
        $manage = Permission::firstOrCreate(['name' => 'devices.manage'], ['label' => 'Manage Devices']);
        $adminRole->permissions()->syncWithoutDetaching([$view->id, $manage->id]);

        $user->assignRole('admin');

        return $this->actingAs($user);
    }

    private function actingAsInventoryViewer(): self
    {
        $viewerRole = Role::firstOrCreate(['name' => 'viewer'], ['label' => 'Viewer']);
        $viewerRole->permissions()->syncWithoutDetaching([
            Permission::firstOrCreate(['name' => 'devices.view'], ['label' => 'View Devices'])->id,
        ]);

        $user = User::factory()->create();
        $user->assignRole('viewer');

        return $this->actingAs($user);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeDevice(array $overrides = []): Device
    {
        $this->deviceSequence++;

        return Device::create(array_merge([
            'asset_tag' => 'DEV-'.$this->deviceSequence,
            'device_type_id' => (int) DeviceType::factory()->create(['category' => 'server'])->id,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeItem(array $overrides = []): InventoryItem
    {
        $this->itemSequence++;

        return InventoryItem::create(array_merge([
            'device_id' => $this->makeDevice()->id,
            'name' => 'Item '.$this->itemSequence,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'device_id' => $this->makeDevice()->id,
            'name' => 'RAID Controller',
        ], $overrides);
    }

    public function test_index_requires_authentication(): void
    {
        $this->get('/admin/inventory-items')->assertRedirect();
    }

    public function test_create_is_forbidden_without_manage_permission(): void
    {
        $this->actingAsInventoryViewer()
            ->get('/admin/inventory-items/create')
            ->assertForbidden();
    }

    public function test_store_validates_the_device_foreign_key(): void
    {
        $response = $this->actingAsAdmin()->post('/admin/inventory-items', $this->validPayload([
            'device_id' => 999_999,
        ]));

        $response->assertSessionHasErrors('device_id');
    }

    public function test_store_validates_the_manufacturer_foreign_key(): void
    {
        $response = $this->actingAsAdmin()->post('/admin/inventory-items', $this->validPayload([
            'manufacturer_id' => 999_999,
        ]));

        $response->assertSessionHasErrors('manufacturer_id');
    }

    public function test_store_enforces_asset_tag_uniqueness_among_live_items(): void
    {
        $device = $this->makeDevice();
        InventoryItem::create([
            'device_id' => $device->id,
            'name' => 'Taken Tag Item',
            'asset_tag' => 'LIVE-TAG-UNIQ',
        ]);

        $response = $this->actingAsAdmin()->post('/admin/inventory-items', $this->validPayload([
            'device_id' => $device->id,
            'name' => 'Other Item',
            'asset_tag' => 'LIVE-TAG-UNIQ',
        ]));

        $response->assertSessionHasErrors('asset_tag');
    }

    public function test_store_rejects_a_duplicate_name_in_the_same_scope(): void
    {
        $device = $this->makeDevice();
        InventoryItem::create([
            'device_id' => $device->id,
            'name' => 'Motherboard',
        ]);

        $response = $this->actingAsAdmin()->post('/admin/inventory-items', $this->validPayload([
            'device_id' => $device->id,
            'name' => 'Motherboard',
        ]));

        $response->assertSessionHasErrors('name');
    }

    public function test_store_rejects_a_parent_item_from_another_device(): void
    {
        $deviceA = $this->makeDevice();
        $deviceB = $this->makeDevice();
        $parent = InventoryItem::create([
            'device_id' => $deviceB->id,
            'name' => 'Chassis B',
        ]);

        $response = $this->actingAsAdmin()->post('/admin/inventory-items', $this->validPayload([
            'device_id' => $deviceA->id,
            'name' => 'Riser Card',
            'parent_item_id' => $parent->id,
        ]));

        $response->assertSessionHasErrors('parent_item_id');
    }

    public function test_store_rejects_itself_as_parent_via_update(): void
    {
        $item = $this->makeItem();

        $response = $this->actingAsAdmin()->put('/admin/inventory-items/'.$item->id, [
            'device_id' => $item->device_id,
            'name' => $item->name,
            'parent_item_id' => $item->id,
        ]);

        $response->assertSessionHasErrors('parent_item_id');
    }

    public function test_update_rejects_a_parent_that_is_a_descendant(): void
    {
        $device = $this->makeDevice();
        $parent = InventoryItem::create(['device_id' => $device->id, 'name' => 'Carrier']);
        $child = InventoryItem::create(['device_id' => $device->id, 'name' => 'Nested Card', 'parent_item_id' => $parent->id]);

        // Moving the carrier under its own child would create a cycle.
        $response = $this->actingAsAdmin()->put('/admin/inventory-items/'.$parent->id, [
            'device_id' => $device->id,
            'name' => 'Carrier',
            'parent_item_id' => $child->id,
        ]);

        $response->assertSessionHasErrors('parent_item_id');
    }

    public function test_update_rejects_moving_a_child_under_another_devices_item(): void
    {
        $deviceA = $this->makeDevice();
        $deviceB = $this->makeDevice();
        $item = InventoryItem::create(['device_id' => $deviceA->id, 'name' => 'Cable Module']);
        $foreignParent = InventoryItem::create(['device_id' => $deviceB->id, 'name' => 'Backplane B']);

        $response = $this->actingAsAdmin()->put('/admin/inventory-items/'.$item->id, [
            'device_id' => $deviceA->id,
            'name' => 'Cable Module',
            'parent_item_id' => $foreignParent->id,
        ]);

        $response->assertSessionHasErrors('parent_item_id');
    }

    public function test_store_casts_discovered_from_a_checkbox_submission(): void
    {
        $response = $this->actingAsAdmin()->post('/admin/inventory-items', $this->validPayload([
            'name' => 'Discovered Nic',
            'discovered' => '1',
        ]));

        $response->assertRedirect();
        $this->assertTrue(InventoryItem::where('name', 'Discovered Nic')->sole()->discovered);

        $this->actingAsAdmin()->post('/admin/inventory-items', $this->validPayload([
            'name' => 'Manual Nic',
        ]));

        $this->assertFalse(InventoryItem::where('name', 'Manual Nic')->sole()->discovered);
    }

    public function test_store_redirects_to_the_device_show_page(): void
    {
        $device = $this->makeDevice();

        $response = $this->actingAsAdmin()->post('/admin/inventory-items', [
            'device_id' => $device->id,
            'name' => 'PSU 1',
        ]);

        $response->assertRedirect(route('admin.devices.show', $device));
    }

    public function test_edit_persists_changes(): void
    {
        $device = $this->makeDevice();
        $manufacturer = Manufacturer::create(['name' => 'Acme', 'slug' => 'acme']);
        $item = InventoryItem::create([
            'device_id' => $device->id,
            'name' => 'Fan Tray',
        ]);

        $response = $this->actingAsAdmin()->put('/admin/inventory-items/'.$item->id, [
            'device_id' => $device->id,
            'name' => 'Fan Tray B',
            'serial_number' => 'SN-FAN-1',
            'status' => 'maintenance',
            'discovered' => '1',
            'manufacturer_id' => $manufacturer->id,
        ]);

        $response->assertRedirect(route('admin.inventory-items.show', $item));
        $this->assertDatabaseHas('inventory_items', [
            'id' => $item->id,
            'name' => 'Fan Tray B',
            'serial_number' => 'SN-FAN-1',
            'status' => 'maintenance',
            'discovered' => 1,
            'manufacturer_id' => $manufacturer->id,
        ]);
    }

    public function test_deleting_the_device_soft_deletes_its_items_and_writes_one_audit_row_each(): void
    {
        $device = $this->makeDevice();
        $itemA = InventoryItem::create([
            'device_id' => $device->id,
            'name' => 'Audit Item A',
            'asset_tag' => 'AUDIT-TAG-A',
        ]);
        $itemB = InventoryItem::create([
            'device_id' => $device->id,
            'name' => 'Audit Item B',
        ]);

        $this->actingAsAdmin()
            ->delete('/admin/devices/'.$device->id)
            ->assertRedirect();

        $this->assertSoftDeleted('inventory_items', ['id' => $itemA->id]);
        $this->assertSoftDeleted('inventory_items', ['id' => $itemB->id]);

        $rows = AuditLog::query()->where('entity_type', 'inventory_item')->where('action', 'deleted')->get();

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing([$itemA->id, $itemB->id], $rows->pluck('entity_id')->map(fn ($id) => (int) $id)->all());
        foreach ($rows as $row) {
            $this->assertStringContainsString('snapshot', (string) $row->details);
        }
        $this->assertStringContainsString('AUDIT-TAG-A', (string) $rows->firstWhere('entity_id', $itemA->id)->details);
    }

    public function test_deleting_an_item_soft_deletes_it_and_frees_the_asset_tag(): void
    {
        $device = $this->makeDevice();
        $item = InventoryItem::create([
            'device_id' => $device->id,
            'name' => 'Tagged Module',
            'asset_tag' => 'REUSABLE-TAG-1',
        ]);

        $this->actingAsAdmin()
            ->delete('/admin/inventory-items/'.$item->id)
            ->assertRedirect(route('admin.devices.show', $device));

        $this->assertSoftDeleted('inventory_items', ['id' => $item->id]);

        $response = $this->actingAsAdmin()->post('/admin/inventory-items', $this->validPayload([
            'device_id' => $device->id,
            'name' => 'Tagged Module Replacement',
            'asset_tag' => 'REUSABLE-TAG-1',
        ]));

        $response->assertRedirect(route('admin.devices.show', $device));
        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('inventory_items', ['name' => 'Tagged Module Replacement', 'asset_tag' => 'REUSABLE-TAG-1']);
    }

    public function test_index_filter_by_device_returns_only_its_items(): void
    {
        $deviceA = $this->makeDevice();
        $deviceB = $this->makeDevice();
        InventoryItem::create(['device_id' => $deviceA->id, 'name' => 'ZebraWidgetAlpha']);
        InventoryItem::create(['device_id' => $deviceB->id, 'name' => 'QuokkaWidgetBeta']);

        $response = $this->actingAsAdmin()->get('/admin/inventory-items?device_id='.$deviceA->id);

        $response->assertOk();
        $response->assertSee('ZebraWidgetAlpha');
        $response->assertDontSee('QuokkaWidgetBeta');
    }

    public function test_an_item_with_children_rejects_a_device_change(): void
    {
        $deviceA = $this->makeDevice();
        $deviceB = $this->makeDevice();
        $parent = $this->makeItem(['device_id' => $deviceA->id, 'name' => 'Chassis Parent']);
        $this->makeItem(['device_id' => $deviceA->id, 'name' => 'Slot Child', 'parent_item_id' => $parent->id]);

        $response = $this->actingAsAdmin()->from('/admin/inventory-items')->put('/admin/inventory-items/'.$parent->id, [
            'device_id' => $deviceB->id,
            'name' => 'Chassis Parent',
        ]);

        $response->assertSessionHasErrors('device_id');
        $this->assertSame($deviceA->id, (int) $parent->refresh()->device_id);
    }

    public function test_a_trashed_parent_is_rejected(): void
    {
        $device = $this->makeDevice();
        $parent = $this->makeItem(['device_id' => $device->id, 'name' => 'Trashed Parent']);
        $parent->delete();
        $item = $this->makeItem(['device_id' => $device->id, 'name' => 'To Be Rejected']);

        $response = $this->actingAsAdmin()->from('/admin/inventory-items/'.$item->id.'/edit')->put('/admin/inventory-items/'.$item->id, [
            'device_id' => $item->device_id,
            'name' => 'To Be Rejected',
            'parent_item_id' => $parent->id,
        ]);

        $response->assertSessionHasErrors('parent_item_id');
    }

    public function test_creating_an_item_writes_an_audit_row(): void
    {
        $device = $this->makeDevice();

        $this->actingAsAdmin()->post('/admin/inventory-items', $this->validPayload([
            'device_id' => $device->id,
            'name' => 'Fresh Audited Item',
        ]))->assertRedirect();

        $item = InventoryItem::where('name', 'Fresh Audited Item')->sole();
        $rows = AuditLog::where('entity_type', 'inventory_item')->where('entity_id', $item->id)->where('action', 'created')->get();

        $this->assertCount(1, $rows);
    }

    public function test_updating_an_item_writes_an_audit_row(): void
    {
        $item = $this->makeItem(['name' => 'Original Model']);

        $this->actingAsAdmin()->put('/admin/inventory-items/'.$item->id, [
            'device_id' => $item->device_id,
            'name' => 'Renamed Model',
        ])->assertRedirect();

        $updated = AuditLog::where('entity_type', 'inventory_item')->where('entity_id', $item->id)->where('action', 'updated')->get();

        $this->assertCount(1, $updated);
        $this->assertStringContainsString('Renamed Model', (string) $updated->first()->details);
    }

    public function test_deleting_an_item_writes_an_audit_row(): void
    {
        $item = $this->makeItem(['name' => 'Doomed Item']);

        $this->actingAsAdmin()->delete('/admin/inventory-items/'.$item->id)->assertRedirect();

        $deleted = AuditLog::where('entity_type', 'inventory_item')->where('entity_id', $item->id)->where('action', 'deleted')->get();

        $this->assertCount(1, $deleted);
    }

    public function test_deleting_an_item_soft_deletes_its_children_and_descendants_and_writes_one_audit_row_each(): void
    {
        $device = $this->makeDevice();
        $parent = $this->makeItem(['device_id' => $device->id, 'name' => 'Subtree Parent']);
        $child = $this->makeItem(['device_id' => $device->id, 'name' => 'Subtree Child', 'parent_item_id' => $parent->id]);
        $grandchild = $this->makeItem(['device_id' => $device->id, 'name' => 'Subtree Grandchild', 'parent_item_id' => $child->id]);

        $this->actingAsAdmin()
            ->delete('/admin/inventory-items/'.$parent->id)
            ->assertRedirect(route('admin.devices.show', $device));

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

    public function test_item_destroy_rolls_back_when_an_item_audit_write_fails(): void
    {
        $device = $this->makeDevice();
        $parent = $this->makeItem(['device_id' => $device->id, 'name' => 'Rollback Parent']);
        $child = $this->makeItem(['device_id' => $device->id, 'name' => 'Rollback Child', 'parent_item_id' => $parent->id]);
        $grandchild = $this->makeItem(['device_id' => $device->id, 'name' => 'Rollback Grandchild', 'parent_item_id' => $child->id]);

        $this->mock(InventoryChangeLogger::class, fn ($mock) => $mock->allows('record')->andReturnFalse());

        try {
            $this->actingAsAdmin()->delete('/admin/inventory-items/'.$parent->id);
            $this->fail('Expected the item audit log failure to abort the item deletion.');
        } catch (\RuntimeException) {
        }

        $this->assertNull($parent->refresh()->deleted_at);
        $this->assertNull($child->refresh()->deleted_at);
        $this->assertNull($grandchild->refresh()->deleted_at);
        $this->assertSame(0, AuditLog::where('entity_type', 'inventory_item')->where('action', 'deleted')
            ->whereIn('entity_id', [$parent->id, $child->id, $grandchild->id])->count());
    }

    public function test_admin_store_and_update_persist_empty_strings_as_null(): void
    {
        $device = $this->makeDevice();

        $this->actingAsAdmin()->post('/admin/inventory-items', $this->validPayload([
            'device_id' => $device->id,
            'name' => 'Empty Strings Item',
            'asset_tag' => '',
            'sku' => '',
        ]))->assertRedirect();

        $item = InventoryItem::where('name', 'Empty Strings Item')->sole();

        $this->assertNull($item->asset_tag);
        $this->assertNull($item->sku);

        $this->actingAsAdmin()->put('/admin/inventory-items/'.$item->id, [
            'device_id' => $device->id,
            'name' => 'Empty Strings Item',
            'asset_tag' => '',
            'sku' => '',
        ])->assertRedirect();

        $this->assertNull($item->refresh()->asset_tag);
        $this->assertNull($item->refresh()->sku);
    }

    public function test_device_destroy_rolls_back_when_an_item_audit_write_fails(): void
    {
        $device = $this->makeDevice();
        $item = $this->makeItem(['device_id' => $device->id]);

        $this->mock(InventoryChangeLogger::class, fn ($mock) => $mock->allows('record')->andReturnFalse());

        try {
            $this->actingAsAdmin()->delete('/admin/devices/'.$device->id);
            $this->fail('Expected the item audit log failure to abort the device deletion.');
        } catch (\RuntimeException) {
        }

        $this->assertNull($device->refresh()->deleted_at);
        $this->assertNull($item->refresh()->deleted_at);
        $this->assertSame(0, AuditLog::where('entity_type', 'inventory_item')->where('action', 'deleted')->count());
    }

    public function test_a_crafted_web_put_strands_parentage_no_longer_passes(): void
    {
        $deviceA = $this->makeDevice();
        $deviceB = $this->makeDevice();
        $parent = $this->makeItem(['device_id' => $deviceA->id, 'name' => 'Slot Parent A']);
        $child = $this->makeItem(['device_id' => $deviceA->id, 'parent_item_id' => $parent->id, 'name' => 'Daughter Item C']);

        $response = $this->actingAsAdmin()->from('/admin/inventory-items/'.$child->id.'/edit')->put('/admin/inventory-items/'.$child->id, [
            'device_id' => $deviceB->id,
            // no parent_item_id key at all — a crafted non-form PUT
        ]);

        $response->assertSessionHasErrors('device_id');
        $this->assertSame($deviceA->id, (int) $child->refresh()->device_id);
    }

    public function test_device_show_history_includes_soft_deleted_item_rows(): void
    {
        $device = $this->makeDevice();
        $item = $this->makeItem(['device_id' => $device->id, 'name' => 'Ephemeral Item']);

        $this->actingAsAdmin()->delete('/admin/inventory-items/'.$item->id)->assertRedirect();

        $this->get('/admin/devices/'.$device->id)
            ->assertOk()
            ->assertSee('Ephemeral Item');
    }

    public function test_device_show_history_includes_item_audit_rows(): void
    {
        $device = $this->makeDevice();
        $item = $this->makeItem(['device_id' => $device->id, 'name' => 'History Subject']);

        $this->actingAsAdmin()->put('/admin/inventory-items/'.$item->id, [
            'device_id' => $device->id,
            'name' => 'History Renamed',
        ]);

        // 'History Subject' only survives on the page inside the History card's
        // item-update entry (name: History Subject → History Renamed); the
        // Items card shows the renamed value instead.
        $this->get('/admin/devices/'.$device->id)
            ->assertOk()
            ->assertSee('History Subject');
    }
}
