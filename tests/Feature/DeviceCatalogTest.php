<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceType;
use App\Models\Manufacturer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NetBox-style catalogs on devices: device type write-through to the
 * free-text manufacturer/model columns, tag assignment, and the catalog's
 * presence in the index, CSV export and REST payload.
 */
class DeviceCatalogTest extends TestCase
{
    use RefreshDatabase;

    private int $assetSequence = 0;

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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeAsset(array $overrides = []): Device
    {
        $this->assetSequence++;

        return Device::create(array_merge([
            'asset_tag' => 'AST-'.$this->assetSequence,
            'device_type_id' => $overrides['device_type_id'] ?? DeviceType::factory()->create(['category' => 'server'])->id,
        ], $overrides));
    }

    private function deviceType(string $manufacturerName = 'Dell', string $model = 'PowerEdge R750'): DeviceType
    {
        $manufacturer = Manufacturer::factory()->create(['name' => $manufacturerName]);

        return DeviceType::factory()->create([
            'manufacturer_id' => $manufacturer->id,
            'model' => $model,
            'u_height' => 2,
            'category' => 'server',
            'power_draw_watts' => 450,
        ]);
    }

    public function test_create_with_a_device_type_overwrites_manufacturer_and_model(): void
    {
        $deviceType = $this->deviceType();

        $response = $this->actingAsAdmin()->post('/admin/devices', [
            'asset_tag' => 'AST-CATALOG-CREATE',
            'device_type_id' => $deviceType->id,
            'manufacturer' => 'Manual Maker',
            'model' => 'Manual Model',
        ]);

        $response->assertRedirect(route('admin.devices.index'));

        $this->assertDatabaseHas('devices', [
            'asset_tag' => 'AST-CATALOG-CREATE',
            'device_type_id' => $deviceType->id,
            'manufacturer' => 'Dell',
            'model' => 'PowerEdge R750',
        ]);
    }

    public function test_store_rejects_a_missing_device_type(): void
    {
        $response = $this->actingAsAdmin()->post('/admin/devices', [
            'asset_tag' => 'AST-MANUAL-CREATE',
            'manufacturer' => 'Manual Maker',
            'model' => 'Manual Model',
        ]);

        $response->assertSessionHasErrors('device_type_id');
        $this->assertDatabaseMissing('devices', ['asset_tag' => 'AST-MANUAL-CREATE']);
    }

    public function test_update_to_a_device_type_overwrites_manufacturer_and_model(): void
    {
        $deviceType = $this->deviceType('Cisco', 'Catalyst 9300');
        $asset = $this->makeAsset([
            'manufacturer' => 'Manual Maker',
            'model' => 'Manual Model',
        ]);

        $response = $this->actingAsAdmin()->put("/admin/devices/{$asset->id}", [
            'device_type_id' => $deviceType->id,
        ]);

        $response->assertRedirect(route('admin.devices.show', $asset));

        $this->assertDatabaseHas('devices', [
            'id' => $asset->id,
            'device_type_id' => $deviceType->id,
            'manufacturer' => 'Cisco',
            'model' => 'Catalyst 9300',
        ]);
    }

    public function test_update_rejects_a_missing_device_type(): void
    {
        $deviceType = $this->deviceType();
        $asset = $this->makeAsset([
            'device_type_id' => $deviceType->id,
            'manufacturer' => 'Dell',
            'model' => 'PowerEdge R750',
        ]);

        $response = $this->actingAsAdmin()->put("/admin/devices/{$asset->id}", [
            'device_type_id' => null,
        ]);

        $response->assertSessionHasErrors('device_type_id');
        $this->assertSame($deviceType->id, $asset->refresh()->device_type_id);
    }

    public function test_tags_are_synced_on_create(): void
    {
        $production = Tag::factory()->create(['name' => 'production']);
        $edge = Tag::factory()->create(['name' => 'Edge']);

        $response = $this->actingAsAdmin()->post('/admin/devices', [
            'asset_tag' => 'AST-TAGGED-CREATE',
            'device_type_id' => DeviceType::factory()->create(['category' => 'server'])->id,
            'tags' => [$production->id, $edge->id],
        ]);

        $response->assertRedirect(route('admin.devices.index'));

        $asset = Device::where('asset_tag', 'AST-TAGGED-CREATE')->firstOrFail();
        $this->assertSame(
            [$production->id, $edge->id],
            $asset->tags()->orderBy('tags.id')->pluck('tags.id')->map(fn ($id) => (int) $id)->all(),
        );
    }

    public function test_tags_are_synced_on_update(): void
    {
        $previous = Tag::factory()->create(['name' => 'Previous']);
        $replacement = Tag::factory()->create(['name' => 'Replacement']);
        $asset = $this->makeAsset();
        $asset->tags()->attach($previous->id);

        $response = $this->actingAsAdmin()->put("/admin/devices/{$asset->id}", [
            'tags' => [$replacement->id],
            'device_type_id' => $asset->device_type_id,
        ]);

        $response->assertRedirect(route('admin.devices.show', $asset));

        $this->assertSame([$replacement->id], $asset->tags()->pluck('tags.id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_omitting_tags_on_update_preserves_the_existing_tags(): void
    {
        $tag = Tag::factory()->create(['name' => 'production']);
        $asset = $this->makeAsset();
        $asset->tags()->attach($tag->id);

        $response = $this->actingAsAdmin()->put("/admin/devices/{$asset->id}", [
            'vendor' => 'Catalog Vendor',
            'device_type_id' => $asset->device_type_id,
        ]);

        $response->assertRedirect(route('admin.devices.show', $asset));

        $this->assertSame([$tag->id], $asset->tags()->pluck('tags.id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame('Catalog Vendor', $asset->fresh()->vendor);
    }

    public function test_index_filters_assets_by_tag(): void
    {
        $tag = Tag::factory()->create(['name' => 'production']);
        $tagged = $this->makeAsset(['asset_tag' => 'AST-TAGGED']);
        $tagged->tags()->attach($tag->id);
        $this->makeAsset(['asset_tag' => 'AST-UNTAGGED']);

        $response = $this->actingAsAdmin()->get('/admin/devices?tag='.$tag->id);

        $response->assertOk();
        $response->assertSee('AST-TAGGED');
        $response->assertDontSee('AST-UNTAGGED');
    }

    public function test_index_renders_tag_badges_with_the_stored_color(): void
    {
        $tag = Tag::factory()->create(['name' => 'production', 'color' => '#198754']);
        $asset = $this->makeAsset(['asset_tag' => 'AST-BADGE']);
        $asset->tags()->attach($tag->id);

        $response = $this->actingAsAdmin()->get('/admin/devices');

        $response->assertOk();
        $response->assertSee(
            '<span class="badge text-white me-1" style="background-color: #198754;">production</span>',
            false,
        );
    }

    public function test_api_show_returns_the_device_type_and_tags(): void
    {
        $deviceType = $this->deviceType();
        $tag = Tag::factory()->create([
            'name' => 'production',
            'slug' => 'production',
            'color' => '#198754',
        ]);
        $asset = $this->makeAsset([
            'asset_tag' => 'AST-API-CATALOG',
            'device_type_id' => $deviceType->id,
        ]);
        $asset->tags()->attach($tag->id);

        $user = User::factory()->create(['role' => 'admin']);
        $user->assignRole('admin');
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson("/api/devices/{$asset->id}");

        $response->assertOk();
        $response->assertJsonPath('data.device_type_id', $deviceType->id);
        $response->assertJsonPath('data.device_type.id', $deviceType->id);
        $response->assertJsonPath('data.device_type.model', 'PowerEdge R750');
        $response->assertJsonPath('data.device_type.manufacturer', 'Dell');
        $response->assertJsonPath('data.device_type.u_height', 2);
        $response->assertJsonPath('data.tags.0.id', $tag->id);
        $response->assertJsonPath('data.tags.0.name', 'production');
        $response->assertJsonPath('data.tags.0.slug', 'production');
        $response->assertJsonPath('data.tags.0.color', '#198754');
    }

    public function test_index_filters_by_tag_slug_and_by_missing_id(): void
    {
        $this->actingAsAdmin();
        $production = Tag::create(['name' => 'production', 'slug' => 'production', 'color' => '#198754']);
        $staging = Tag::create(['name' => 'Staging', 'slug' => 'staging', 'color' => '#3b82f6']);
        $productionAsset = $this->makeAsset(['asset_tag' => 'AST-SLUG-1']);
        $productionAsset->tags()->attach($production->id);
        $stagingAsset = $this->makeAsset(['asset_tag' => 'AST-SLUG-2']);
        $stagingAsset->tags()->attach($staging->id);

        $this->get('/admin/devices?tag=production')
            ->assertOk()
            ->assertSee('AST-SLUG-1')
            ->assertDontSee('AST-SLUG-2');

        $this->get('/admin/devices?tag=999999')
            ->assertOk()
            ->assertDontSee('AST-SLUG-1');
    }

    public function test_export_includes_device_type_and_tag_columns(): void
    {
        $deviceType = $this->deviceType();
        $tag = Tag::factory()->create(['name' => 'production']);
        $asset = $this->makeAsset([
            'asset_tag' => 'AST-EXPORT-CATALOG',
            'device_type_id' => $deviceType->id,
            'manufacturer' => 'Dell',
            'model' => 'PowerEdge R750',
        ]);
        $asset->tags()->attach($tag->id);

        $response = $this->actingAsAdmin()->get('/admin/devices/export');

        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString('warranty_expiry,device_type,tags', $content);
        $this->assertStringContainsString('AST-EXPORT-CATALOG,server,,"PowerEdge R750",Dell,,in_stock', $content);
        $this->assertStringContainsString(',"Dell PowerEdge R750",production', $content);
    }
}
