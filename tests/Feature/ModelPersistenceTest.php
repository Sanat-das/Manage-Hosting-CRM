<?php

namespace Tests\Feature;

use App\Models\LicenseAssignment;
use App\Models\ProductResource;
use App\Models\ResourceAllocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression guard for the `$timestamps = false` fix.
 *
 * `license_assignments` and `resource_allocations` record their own domain
 * moments (`assigned_at` / `allocated_at`) and carry no created_at/updated_at
 * columns. Before the models opted out of timestamp management, the first
 * Eloquent write tried to persist those missing columns and threw a
 * QueryException. These tests exercise a real create() against the real schema
 * so that regression cannot return silently.
 */
class ModelPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_license_assignment_create_persists(): void
    {
        $assetId = DB::table('inventory_assets')->insertGetId([
            'asset_tag' => 'LIC-ASSET-1',
            'asset_type' => 'software_license',
            'status' => 'in_stock',
        ]);

        $licenseId = DB::table('licenses')->insertGetId([
            'inventory_asset_id' => $assetId,
            'license_type' => 'cpanel',
        ]);

        $assignment = LicenseAssignment::create([
            'license_id' => $licenseId,
            'assigned_to_type' => 'server',
            'assigned_to_id' => 42,
            'assigned_at' => now(),
        ]);

        $this->assertModelExists($assignment);
        $this->assertDatabaseHas('license_assignments', [
            'id' => $assignment->id,
            'license_id' => $licenseId,
            'assigned_to_type' => 'server',
            'assigned_to_id' => 42,
        ]);
    }

    public function test_resource_allocation_create_persists(): void
    {
        $resourceTypeId = DB::table('resource_types')->insertGetId([
            'name' => 'Test Resource',
            'slug' => 'test_resource',
            'category' => 'capacity',
        ]);

        $allocation = ResourceAllocation::create([
            'service_id' => 7,
            'resource_type_id' => $resourceTypeId,
            'quantity_allocated' => 2,
            'allocated_at' => now(),
        ]);

        $this->assertModelExists($allocation);
        $this->assertDatabaseHas('resource_allocations', [
            'id' => $allocation->id,
            'service_id' => 7,
            'resource_type_id' => $resourceTypeId,
        ]);
    }

    public function test_product_resource_create_persists_with_only_created_at(): void
    {
        $productId = DB::table('products')->insertGetId(['name' => 'Test Product']);
        $resourceTypeId = DB::table('resource_types')->value('id');

        $resource = ProductResource::create([
            'product_id' => $productId,
            'resource_type_id' => $resourceTypeId,
            'quantity' => 4,
        ]);

        $this->assertModelExists($resource);
        $this->assertDatabaseHas('product_resources', [
            'id' => $resource->id,
            'product_id' => $productId,
            'resource_type_id' => $resourceTypeId,
        ]);
    }
}
