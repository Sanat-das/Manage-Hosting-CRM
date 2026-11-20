<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceType;
use App\Models\Manufacturer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use App\Services\Inventory\InventoryChangeLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryChangeLogTest extends TestCase
{
    use RefreshDatabase;

    private int $assetSequence = 0;

    private function actingAsAdmin(): self
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $view = Permission::firstOrCreate(['name' => 'devices.view'], ['label' => 'View Devices']);
        $manage = Permission::firstOrCreate(['name' => 'devices.manage'], ['label' => 'Manage Devices']);
        $adminRole->permissions()->syncWithoutDetaching([$view->id, $manage->id]);

        $user->assignRole('admin');

        return $this->actingAs($user);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeDeviceTypeId(string $category = 'server'): int
    {
        $manufacturer = Manufacturer::factory()->create();

        return (int) DeviceType::factory()->create([
            'manufacturer_id' => $manufacturer->id,
            'category' => $category,
        ])->id;
    }

    private function makeAsset(array $overrides = []): Device
    {
        $this->assetSequence++;

        return Device::create(array_merge([
            'asset_tag' => 'LOG-AST-'.$this->assetSequence,
            'device_type_id' => $overrides['device_type_id'] ?? $this->makeDeviceTypeId(),
        ], $overrides));
    }

    private function auditFor(Device $asset, string $action)
    {
        return AuditLog::query()
            ->where('entity_type', 'device')
            ->where('entity_id', $asset->id)
            ->where('action', $action)
            ->get();
    }

    public function test_creating_an_asset_through_the_form_writes_an_audit_row(): void
    {
        $this->actingAsAdmin();

        $response = $this->post('/admin/devices', [
            'asset_tag' => 'LOG-FORM-1',
            'device_type_id' => $this->makeDeviceTypeId(),
            'model' => 'PowerEdge R750',
        ]);

        $response->assertRedirect();

        $asset = Device::where('asset_tag', 'LOG-FORM-1')->sole();

        $created = $this->auditFor($asset, 'created');
        $this->assertCount(1, $created);
        $this->assertNotNull($created->first()->created_at);
        $this->assertStringContainsString('LOG-FORM-1', (string) $created->first()->details);
    }

    public function test_audit_log_captures_create_update_delete_and_tag_changes(): void
    {
        $this->actingAsAdmin();
        $tag = Tag::create(['name' => 'production', 'slug' => 'production', 'color' => '#ef4444']);

        $this->post('/admin/devices', [
            'asset_tag' => 'LOG-FULL-1',
            'device_type_id' => $this->makeDeviceTypeId(),
            'tags' => [$tag->id],
            'tags_submitted' => '1',
        ]);

        $asset = Device::where('asset_tag', 'LOG-FULL-1')->sole();

        $this->assertSame(1, $this->auditFor($asset, 'created')->count());
        $this->assertSame(1, $this->auditFor($asset, 'tags_updated')->count());

        $rackmountType = DeviceType::factory()->create(['category' => 'server', 'model' => 'Rackmount Server']);

        $this->put('/admin/devices/'.$asset->id, [
            'asset_tag' => $asset->asset_tag,
            'device_type_id' => $rackmountType->id,
            'model' => 'Rackmount Server',
        ])->assertRedirect();

        $updated = $this->auditFor($asset, 'updated');
        $this->assertCount(1, $updated);
        $this->assertStringContainsString('Rackmount Server', (string) $updated->first()->details);
    }

    public function test_no_op_update_writes_no_audit_row(): void
    {
        $this->actingAsAdmin();
        $type = DeviceType::factory()->create(['category' => 'server']);

        $this->post('/admin/devices', [
            'asset_tag' => 'LOG-NOOP-1',
            'device_type_id' => $type->id,
        ])->assertRedirect();
        $asset = Device::where('asset_tag', 'LOG-NOOP-1')->sole();

        $this->put('/admin/devices/'.$asset->id, [
            'asset_tag' => $asset->asset_tag,
            'device_type_id' => $asset->device_type_id,
        ]);

        $this->assertSame(0, $this->auditFor($asset, 'updated')->count());
    }

    public function test_deleting_an_asset_writes_a_deleted_row_with_snapshot(): void
    {
        $this->actingAsAdmin();
        $asset = $this->makeAsset(['model' => 'R750']);

        $this->delete('/admin/devices/'.$asset->id)->assertRedirect();

        $deleted = $this->auditFor($asset, 'deleted');

        $this->assertCount(1, $deleted);
        $this->assertStringContainsString('snapshot', (string) $deleted->first()->details);
        $this->assertStringContainsString('LOG-AST', (string) $deleted->first()->details);
    }

    public function test_submitting_an_empty_tag_list_clears_tags_and_logs_the_change(): void
    {
        $this->actingAsAdmin();
        $tag = Tag::create(['name' => 'staged', 'slug' => 'staged', 'color' => '#3b82f6']);
        $asset = $this->makeAsset();
        $asset->tags()->attach($tag->id);

        $this->put('/admin/devices/'.$asset->id, [
            'asset_tag' => $asset->asset_tag,
            'device_type_id' => $this->makeDeviceTypeId(),
            'tags_submitted' => '1',
        ])->assertRedirect();

        $this->assertSame(0, $asset->refresh()->tags()->count());
        $tagsUpdated = $this->auditFor($asset, 'tags_updated');
        $this->assertCount(1, $tagsUpdated);
        $this->assertStringContainsString('staged', (string) $tagsUpdated->first()->details);
    }

    public function test_show_page_renders_the_history_card_with_a_change(): void
    {
        $this->actingAsAdmin();
        $asset = $this->makeAsset(['model' => 'R750']);

        $r760Type = DeviceType::factory()->create(['category' => 'server', 'model' => 'R760']);

        $this->put('/admin/devices/'.$asset->id, [
            'asset_tag' => $asset->asset_tag,
            'device_type_id' => $r760Type->id,
        ]);

        $this->get('/admin/devices/'.$asset->id)
            ->assertOk()
            ->assertSee('History')
            ->assertSee('R760');
    }

    public function test_bulk_status_writes_an_audit_row_per_changed_asset(): void
    {
        $this->actingAsAdmin();
        $asset1 = $this->makeAsset(['status' => 'in_stock']);
        $asset2 = $this->makeAsset(['status' => 'in_stock']);

        $this->post('/admin/devices/bulk-status', [
            'ids' => [$asset1->id, $asset2->id],
            'status' => 'maintenance',
        ])->assertRedirect();

        $first = $this->auditFor($asset1, 'updated');
        $second = $this->auditFor($asset2, 'updated');

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
        $this->assertStringContainsString('"maintenance"', (string) $first->first()->details);
    }

    public function test_deleting_a_tag_writes_a_row_for_affected_assets(): void
    {
        $this->actingAsAdmin();
        $tag = Tag::create(['name' => 'to-remove', 'slug' => 'to-remove', 'color' => '#ef4444']);
        $asset = $this->makeAsset();
        $asset->tags()->attach($tag->id);

        $this->delete('/admin/tags/'.$tag->id)->assertRedirect();

        $tagsUpdated = $this->auditFor($asset, 'tags_updated');
        $this->assertCount(1, $tagsUpdated);
        $this->assertStringContainsString('to-remove', (string) $tagsUpdated->first()->details);
    }

    public function test_deleting_a_device_type_writes_a_row_for_affected_assets(): void
    {
        $this->actingAsAdmin();
        $manufacturer = Manufacturer::create(['name' => 'VERIFY-TestFab', 'slug' => 'verify-testfab', 'description' => null]);
        $deviceType = DeviceType::create(['manufacturer_id' => $manufacturer->id, 'model' => 'VERIFY-R750', 'slug' => 'verify-r750', 'u_height' => 2, 'is_full_depth' => true, 'category' => 'server']);
        $asset = $this->makeAsset(['device_type_id' => $deviceType->id]);

        $this->delete('/admin/device-types/'.$deviceType->id)->assertRedirect();

        $updated = $this->auditFor($asset, 'updated');
        $this->assertCount(1, $updated);
        $this->assertStringContainsString('device_type_id', (string) $updated->first()->details);
        $this->assertNull($asset->refresh()->device_type_id);
    }

    public function test_a_soft_deleted_asset_gets_an_audit_row_when_its_tag_is_deleted(): void
    {
        $this->actingAsAdmin();
        $tag = Tag::create(['name' => 'soft-target', 'slug' => 'soft-target', 'color' => '#ef4444']);
        $asset = $this->makeAsset();
        $asset->tags()->attach($tag->id);
        $asset->delete();

        $this->delete('/admin/tags/'.$tag->id)->assertRedirect();

        $tagsUpdated = $this->auditFor($asset, 'tags_updated');
        $this->assertCount(1, $tagsUpdated);
        $this->assertStringContainsString('soft-target', (string) $tagsUpdated->first()->details);
    }

    public function test_json_requests_ignore_the_tags_submitted_marker(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $user->assignRole('admin');
        $token = $user->createToken('marker-test')->plainTextToken;

        $tag = Tag::create(['name' => 'marker-keep', 'slug' => 'marker-keep', 'color' => '#10b981']);
        $asset = $this->makeAsset();
        $asset->tags()->attach($tag->id);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson('/api/devices/'.$asset->id, [
            'tags_submitted' => true,
            'model' => 'Unchanged-Keeper',
            'device_type_id' => $asset->device_type_id,
        ])->assertOk();

        $this->assertSame(1, $asset->refresh()->tags()->count());
    }

    public function test_notes_values_are_redacted_in_the_change_log(): void
    {
        $this->actingAsAdmin();
        $asset = $this->makeAsset(['model' => 'R750']);

        $this->put('/admin/devices/'.$asset->id, [
            'asset_tag' => $asset->asset_tag,
            'device_type_id' => $this->makeDeviceTypeId(),
            'notes' => 'power to the rack, db password=hunter2, ssh as ops',
        ])->assertRedirect();

        $updated = $this->auditFor($asset, 'updated');

        $this->assertCount(1, $updated);
        $this->assertStringNotContainsString('hunter2', (string) $updated->first()->details);
        $this->assertStringContainsString('redacted', (string) $updated->first()->details);
    }

    public function test_bulk_status_rolls_back_when_the_audit_log_fails(): void
    {
        $this->actingAsAdmin();
        $asset1 = $this->makeAsset(['status' => 'in_stock']);
        $asset2 = $this->makeAsset(['status' => 'in_stock']);

        $this->mock(InventoryChangeLogger::class, fn ($mock) => $mock->allows('record')->andReturnFalse());

        try {
            $this->post('/admin/devices/bulk-status', [
                'ids' => [$asset1->id, $asset2->id],
                'status' => 'maintenance',
            ]);
            $this->fail('Expected the audit log failure to abort the request.');
        } catch (\RuntimeException) {
        }

        $this->assertSame('in_stock', $asset1->refresh()->status);
        $this->assertSame('in_stock', $asset2->refresh()->status);
        $this->assertSame(0, $this->auditFor($asset1, 'updated')->count());
        $this->assertSame(0, $this->auditFor($asset2, 'updated')->count());
    }

    public function test_json_api_tags_empty_list_clears_and_null_preserves(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $user->assignRole('admin');
        $token = $user->createToken('tags-null-test')->plainTextToken;

        $alpha = Tag::create(['name' => 'alpha', 'slug' => 'alpha', 'color' => '#111111']);
        $beta = Tag::create(['name' => 'beta', 'slug' => 'beta', 'color' => '#222222']);
        $asset = $this->makeAsset();
        $asset->tags()->attach([$alpha->id, $beta->id]);

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->putJson('/api/devices/'.$asset->id, ['tags' => [], 'device_type_id' => $asset->device_type_id])
            ->assertOk();

        $this->assertSame(0, $asset->refresh()->tags()->count());

        $asset->tags()->attach($alpha->id);

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->putJson('/api/devices/'.$asset->id, ['tags' => null, 'device_type_id' => $asset->device_type_id])
            ->assertOk();

        $this->assertSame(1, $asset->refresh()->tags()->count());
    }

    public function test_tag_change_logs_the_full_old_and_new_name_sets(): void
    {
        $this->actingAsAdmin();
        $alpha = Tag::create(['name' => 'alpha', 'slug' => 'alpha', 'color' => '#111111']);
        $beta = Tag::create(['name' => 'beta', 'slug' => 'beta', 'color' => '#222222']);
        $asset = $this->makeAsset();
        $asset->tags()->attach([$alpha->id, $beta->id]);

        $this->put('/admin/devices/'.$asset->id, [
            'asset_tag' => $asset->asset_tag,
            'device_type_id' => $this->makeDeviceTypeId(),
            'tags' => [$beta->id],
        ])->assertRedirect();

        $details = json_decode((string) $this->auditFor($asset, 'tags_updated')->first()->details, true);

        $this->assertSame(['alpha', 'beta'], $details['tags']['old']);
        $this->assertSame(['beta'], $details['tags']['new']);
    }

    public function test_notes_values_are_redacted_from_the_deleted_snapshot(): void
    {
        $this->actingAsAdmin();
        $asset = $this->makeAsset(['notes' => 'db password=hunter2']);

        $this->delete('/admin/devices/'.$asset->id)->assertRedirect();

        $deleted = $this->auditFor($asset, 'deleted');
        $this->assertStringNotContainsString('hunter2', (string) $deleted->first()->details);
        $this->assertStringContainsString('redacted', (string) $deleted->first()->details);
    }

    public function test_tag_destroy_rolls_back_when_the_audit_write_fails(): void
    {
        $this->actingAsAdmin();
        $tag = Tag::create(['name' => 'rollback-keep', 'slug' => 'rollback-keep', 'color' => '#ef4444']);
        $asset = $this->makeAsset();
        $asset->tags()->attach($tag->id);

        $this->mock(InventoryChangeLogger::class, fn ($mock) => $mock->allows('recordTagChange')->andReturnFalse());

        try {
            $this->delete('/admin/tags/'.$tag->id);
            $this->fail('Expected the audit log failure to abort the tag delete.');
        } catch (\RuntimeException) {
        }

        $this->assertSame(1, Tag::whereKey($tag->id)->count());
        $this->assertSame(1, $asset->refresh()->tags()->count());
        $this->assertSame(0, $this->auditFor($asset, 'tags_updated')->count());
    }

    public function test_device_type_destroy_rolls_back_when_the_audit_write_fails(): void
    {
        $this->actingAsAdmin();
        $manufacturer = Manufacturer::create(['name' => 'VERIFY-TestFab', 'slug' => 'verify-testfab', 'description' => null]);
        $deviceType = DeviceType::create(['manufacturer_id' => $manufacturer->id, 'model' => 'VERIFY-R750', 'slug' => 'verify-r750-rb', 'u_height' => 2, 'is_full_depth' => true, 'category' => 'server']);
        $asset = $this->makeAsset(['device_type_id' => $deviceType->id]);

        $this->mock(InventoryChangeLogger::class, fn ($mock) => $mock->allows('record')->andReturnFalse());

        try {
            $this->delete('/admin/device-types/'.$deviceType->id);
            $this->fail('Expected the audit log failure to abort the device type delete.');
        } catch (\RuntimeException) {
        }

        $this->assertSame(1, DeviceType::whereKey($deviceType->id)->count());
        $this->assertSame($deviceType->id, (int) $asset->refresh()->device_type_id);
        $this->assertSame(0, $this->auditFor($asset, 'updated')->count());
    }

    public function test_manufacturer_destroy_rolls_back_when_the_audit_write_fails(): void
    {
        $this->actingAsAdmin();
        $manufacturer = Manufacturer::create(['name' => 'VERIFY-TestFab', 'slug' => 'verify-testfab-rb', 'description' => null]);
        $deviceType = DeviceType::create(['manufacturer_id' => $manufacturer->id, 'model' => 'VERIFY-R750', 'slug' => 'verify-r750-mfrb', 'u_height' => 2, 'is_full_depth' => true, 'category' => 'server']);
        $asset = $this->makeAsset(['device_type_id' => $deviceType->id]);

        $this->mock(InventoryChangeLogger::class, fn ($mock) => $mock->allows('record')->andReturnFalse());

        try {
            $this->delete('/admin/manufacturers/'.$manufacturer->id);
            $this->fail('Expected the audit log failure to abort the manufacturer delete.');
        } catch (\RuntimeException) {
        }

        $this->assertSame(1, Manufacturer::whereKey($manufacturer->id)->count());
        $this->assertSame(1, DeviceType::whereKey($deviceType->id)->count());
        $this->assertSame($deviceType->id, (int) $asset->refresh()->device_type_id);
        $this->assertSame(0, $this->auditFor($asset, 'updated')->count());
    }

    public function test_admin_update_rolls_back_the_asset_write_when_the_audit_write_fails(): void
    {
        $this->actingAsAdmin();
        $tag = Tag::create(['name' => 'rollback-keep', 'slug' => 'rollback-keep', 'color' => '#10b981']);
        $asset = $this->makeAsset(['model' => 'R750']);
        $asset->tags()->attach($tag->id);

        $this->mock(InventoryChangeLogger::class, fn ($mock) => $mock->allows('recordTagChange')->andReturnFalse());

        try {
            $this->put('/admin/devices/'.$asset->id, [
                'asset_tag' => $asset->asset_tag,
                'device_type_id' => $this->makeDeviceTypeId(),
                'model' => 'R760',
                'tags_submitted' => '1',
            ]);
            $this->fail('Expected the audit log failure to abort the update.');
        } catch (\RuntimeException) {
        }

        $this->assertSame('R750', $asset->refresh()->model);
        $this->assertSame(1, $asset->refresh()->tags()->count());
    }

    public function test_api_update_also_writes_an_audit_row(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $user->assignRole('admin');
        $token = $user->createToken('change-log-test')->plainTextToken;

        $asset = $this->makeAsset(['model' => 'R750']);

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->putJson('/api/devices/'.$asset->id, ['model' => 'R760', 'device_type_id' => $asset->device_type_id])
            ->assertOk();

        $this->assertSame(1, $this->auditFor($asset, 'updated')->count());
    }
}
