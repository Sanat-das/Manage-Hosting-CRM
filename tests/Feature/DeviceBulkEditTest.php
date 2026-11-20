<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Datacenter;
use App\Models\Device;
use App\Models\DeviceType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Inventory\InventoryChangeLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceBulkEditTest extends TestCase
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
    private function makeDeviceTypeId(string $category = 'server'): int
    {
        return (int) DeviceType::factory()->create(['category' => $category])->id;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeAsset(array $overrides = []): Device
    {
        $this->assetSequence++;

        if (! array_key_exists('device_type_id', $overrides)) {
            $overrides['device_type_id'] = $this->makeDeviceTypeId();
        }

        return Device::create(array_merge([
            'asset_tag' => 'BULK-AST-'.$this->assetSequence,
        ], $overrides));
    }

    private function datacenter(string $code = 'TST1'): Datacenter
    {
        return Datacenter::create(['name' => "Datacenter {$code}", 'code' => $code, 'status' => 'active']);
    }

    private function auditFor(Device $asset, string $action)
    {
        return AuditLog::query()
            ->where('entity_type', 'device')
            ->where('entity_id', $asset->id)
            ->where('action', $action)
            ->get();
    }

    public function test_bulk_edit_applies_status_to_selected_devices(): void
    {
        $this->actingAsAdmin();
        $asset1 = $this->makeAsset(['status' => 'in_stock']);
        $asset2 = $this->makeAsset(['status' => 'in_stock']);

        $response = $this->post('/admin/devices/bulk-edit', [
            'ids' => [$asset1->id, $asset2->id],
            'status' => 'maintenance',
        ]);

        $response->assertRedirect(route('admin.devices.index'));
        $response->assertSessionHas('success', 'Updated 2 devices.');

        $this->assertSame('maintenance', $asset1->refresh()->status);
        $this->assertSame('maintenance', $asset2->refresh()->status);

        $first = $this->auditFor($asset1, 'updated');
        $second = $this->auditFor($asset2, 'updated');

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
        $this->assertStringContainsString('"maintenance"', (string) $first->first()->details);
        $this->assertStringContainsString('"maintenance"', (string) $second->first()->details);
    }

    public function test_bulk_edit_applies_datacenter_only(): void
    {
        $this->actingAsAdmin();
        $datacenter = $this->datacenter();
        $asset1 = $this->makeAsset(['status' => 'in_stock']);
        $asset2 = $this->makeAsset(['status' => 'in_stock']);

        $response = $this->post('/admin/devices/bulk-edit', [
            'ids' => [$asset1->id, $asset2->id],
            'datacenter_id' => (string) $datacenter->id,
        ]);

        $response->assertRedirect(route('admin.devices.index'));
        $response->assertSessionHas('success', 'Updated 2 devices.');

        $this->assertSame((int) $datacenter->id, (int) $asset1->refresh()->datacenter_id);
        $this->assertSame((int) $datacenter->id, (int) $asset2->refresh()->datacenter_id);
        $this->assertSame('in_stock', $asset1->status);

        $first = $this->auditFor($asset1, 'updated');
        $this->assertCount(1, $first);
        $this->assertStringContainsString('datacenter_id', (string) $first->first()->details);
    }

    public function test_bulk_edit_requires_at_least_one_field(): void
    {
        $this->actingAsAdmin();
        $asset = $this->makeAsset(['status' => 'in_stock']);

        $response = $this->post('/admin/devices/bulk-edit', [
            'ids' => [$asset->id],
        ]);

        $response->assertSessionHasErrors('bulk_edit');
        $this->assertSame('in_stock', $asset->refresh()->status);
        $this->assertSame(0, $this->auditFor($asset, 'updated')->count());
    }

    public function test_bulk_edit_rejects_an_unknown_id(): void
    {
        $this->actingAsAdmin();

        $response = $this->post('/admin/devices/bulk-edit', [
            'ids' => [999999],
            'status' => 'maintenance',
        ]);

        $response->assertSessionHasErrors('ids.0');
    }

    public function test_bulk_edit_is_forbidden_without_manage_permission(): void
    {
        $user = User::factory()->create();

        // The seeded admin role is a superuser; remove only the route's gate so
        // the refusal comes from the devices.manage check, not the panel door
        // (which still sees the role's other permissions).
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $adminRole->permissions()->detach(
            Permission::where('name', 'devices.manage')->firstOrFail()->id,
        );

        $user->assignRole('admin');
        $asset = $this->makeAsset(['status' => 'in_stock']);

        $this->actingAs($user)
            ->post('/admin/devices/bulk-edit', [
                'ids' => [$asset->id],
                'status' => 'maintenance',
            ])
            ->assertForbidden();
    }

    public function test_bulk_edit_rolls_back_when_the_audit_log_fails(): void
    {
        $this->actingAsAdmin();
        $asset1 = $this->makeAsset(['status' => 'in_stock']);
        $asset2 = $this->makeAsset(['status' => 'in_stock']);

        $this->mock(InventoryChangeLogger::class, fn ($mock) => $mock->allows('record')->andReturnFalse());

        try {
            $this->post('/admin/devices/bulk-edit', [
                'ids' => [$asset1->id, $asset2->id],
                'status' => 'maintenance',
            ]);
            $this->fail('Expected the audit log failure to abort the request.');
        } catch (\RuntimeException) {
            //
        }

        $this->assertSame('in_stock', $asset1->refresh()->status);
        $this->assertSame('in_stock', $asset2->refresh()->status);
        $this->assertSame(0, $this->auditFor($asset1, 'updated')->count());
        $this->assertSame(0, $this->auditFor($asset2, 'updated')->count());
    }

    public function test_bulk_edit_is_a_noop_when_all_values_are_current(): void
    {
        $this->actingAsAdmin();
        $asset = $this->makeAsset(['status' => 'maintenance']);

        $this->post(route('admin.devices.bulk-edit'), ['ids' => [$asset->id], 'status' => 'maintenance'])
            ->assertRedirect(route('admin.devices.index'))
            ->assertSessionHas('success', 'Updated 0 devices.');

        $this->assertSame('maintenance', $asset->refresh()->status);
        $this->assertCount(0, $this->auditFor($asset, 'updated'));
    }
}
