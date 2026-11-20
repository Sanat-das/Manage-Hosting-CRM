<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagCrudTest extends TestCase
{
    use RefreshDatabase;

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
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Production',
            'slug' => 'production',
            'color' => '#6c757d',
            'description' => 'Production workloads',
        ], $overrides);
    }

    private function makeAsset(): Device
    {
        return Device::create([
            'asset_tag' => 'TAG-ASSET-1',
            'device_type_id' => (int) DeviceType::factory()->create(['category' => 'server'])->id,
        ]);
    }

    public function test_index_requires_authentication(): void
    {
        $this->get('/admin/tags')->assertRedirect();
    }

    public function test_view_only_user_cannot_create_or_store_tags(): void
    {
        $this->actingAsInventoryViewer()->get(route('admin.tags.create'))->assertForbidden();

        $this->actingAsInventoryViewer()
            ->post(route('admin.tags.store'), $this->validPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('tags', 0);
    }

    public function test_admin_can_create_a_tag_with_a_slug_generated_from_its_name(): void
    {
        $response = $this->actingAsAdmin()->post(route('admin.tags.store'), $this->validPayload([
            'name' => 'Web Servers',
            'slug' => '',
        ]));

        $response->assertRedirect(route('admin.tags.index'));
        $this->assertDatabaseHas('tags', [
            'name' => 'Web Servers',
            'slug' => 'web-servers',
            'color' => '#6c757d',
        ]);
    }

    public function test_duplicate_tag_name_is_rejected(): void
    {
        Tag::factory()->create(['name' => 'Production']);

        $response = $this->actingAsAdmin()->post(route('admin.tags.store'), $this->validPayload([
            'slug' => 'production-2',
        ]));

        $response->assertSessionHasErrors('name');
        $this->assertDatabaseCount('tags', 1);
    }

    public function test_duplicate_tag_slug_is_rejected(): void
    {
        Tag::factory()->create(['slug' => 'production']);

        $response = $this->actingAsAdmin()->post(route('admin.tags.store'), $this->validPayload([
            'name' => 'Production Two',
        ]));

        $response->assertSessionHasErrors('slug');
        $this->assertDatabaseCount('tags', 1);
    }

    public function test_invalid_tag_color_is_rejected(): void
    {
        $response = $this->actingAsAdmin()->post(route('admin.tags.store'), $this->validPayload([
            'color' => 'not-a-color',
        ]));

        $response->assertSessionHasErrors('color');
        $this->assertDatabaseCount('tags', 0);
    }

    public function test_admin_can_update_a_tag(): void
    {
        $tag = Tag::factory()->create([
            'name' => 'Production',
            'slug' => 'production',
            'color' => '#6c757d',
        ]);

        $response = $this->actingAsAdmin()->put(route('admin.tags.update', $tag), [
            'name' => 'Production Web',
            'slug' => 'production-web',
            'color' => '#0d6efd',
            'description' => 'Customer-facing web tier',
        ]);

        $response->assertRedirect(route('admin.tags.index'));

        $tag->refresh();
        $this->assertSame('Production Web', $tag->name);
        $this->assertSame('production-web', $tag->slug);
        $this->assertSame('#0d6efd', $tag->color);
        $this->assertSame('Customer-facing web tier', $tag->description);
    }

    public function test_deleting_a_tag_removes_its_pivot_rows(): void
    {
        $tag = Tag::factory()->create();
        $asset = $this->makeAsset();
        $asset->tags()->attach($tag->id);

        $this->assertDatabaseHas('taggables', [
            'tag_id' => $tag->id,
            'taggable_type' => Device::class,
            'taggable_id' => $asset->id,
        ]);

        $response = $this->actingAsAdmin()->delete(route('admin.tags.destroy', $tag));

        $response->assertRedirect(route('admin.tags.index'));
        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
        $this->assertDatabaseCount('taggables', 0);
    }

    public function test_a_tag_can_be_attached_to_an_device(): void
    {
        $tag = Tag::factory()->create();
        $asset = $this->makeAsset();

        $asset->tags()->attach($tag->id);

        $this->assertDatabaseHas('taggables', [
            'tag_id' => $tag->id,
            'taggable_type' => Device::class,
            'taggable_id' => $asset->id,
        ]);
        $this->assertTrue($asset->fresh()->tags->contains($tag));
        $this->assertSame(1, $tag->fresh()->assets()->count());
    }
}
