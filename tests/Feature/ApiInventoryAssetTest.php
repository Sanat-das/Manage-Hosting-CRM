<?php

namespace Tests\Feature;

use App\Models\Datacenter;
use App\Models\InventoryAsset;
use App\Models\Rack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Inventory assets REST API (Api\InventoryAssetController).
 *
 * Mirrors the reference /api/inventory-assets endpoints and reuses the admin
 * InventoryAssetController's validation semantics (including the shared rack
 * U-position closure), so the API and the panel agree on a valid asset.
 */
class ApiInventoryAssetTest extends TestCase
{
    use RefreshDatabase;

    private int $assetSequence = 0;

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/inventory-assets')->assertStatus(401);
    }

    public function test_client_token_is_forbidden_from_index(): void
    {
        $this->actingAsApi($this->clientUser())
            ->getJson('/api/inventory-assets')
            ->assertForbidden();
    }

    public function test_index_returns_paginated_envelope(): void
    {
        $this->makeAsset(['asset_tag' => 'API-AST-1']);
        $this->makeAsset(['asset_tag' => 'API-AST-2']);
        $this->makeAsset(['asset_tag' => 'API-AST-3']);

        $response = $this->actingAsApi($this->apiUser())->getJson('/api/inventory-assets');

        $response->assertOk();
        $response->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
        $response->assertJsonCount(3, 'data');
        $response->assertJsonPath('meta.total', 3);
    }

    public function test_index_filters_by_asset_type(): void
    {
        $this->makeAsset(['asset_tag' => 'MATCH-SERVER', 'asset_type' => 'server']);
        $this->makeAsset(['asset_tag' => 'OTHER-SWITCH', 'asset_type' => 'switch']);

        $response = $this->actingAsApi($this->apiUser())->getJson('/api/inventory-assets?asset_type=server');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.asset_tag', 'MATCH-SERVER');
    }

    public function test_index_caps_per_page_at_100(): void
    {
        $this->makeAsset();

        $response = $this->actingAsApi($this->apiUser())->getJson('/api/inventory-assets?per_page=500');

        $response->assertOk();
        $response->assertJsonPath('meta.per_page', 100);
    }

    public function test_show_returns_asset_with_datacenter_and_rack_names(): void
    {
        $datacenter = $this->datacenter();
        $rack = $this->rack($datacenter);
        $asset = $this->makeAsset([
            'asset_tag' => 'API-AST-SHOW',
            'datacenter_id' => $datacenter->id,
            'rack_id' => $rack->id,
            'rack_u_position' => 3,
        ]);

        $response = $this->actingAsApi($this->apiUser())->getJson("/api/inventory-assets/{$asset->id}");

        $response->assertOk();
        $response->assertJsonPath('data.asset_tag', 'API-AST-SHOW');
        $response->assertJsonPath('data.datacenter_name', $datacenter->name);
        $response->assertJsonPath('data.rack_name', $rack->name);
    }

    public function test_store_requires_asset_tag(): void
    {
        $response = $this->actingAsApi($this->apiUser())->postJson('/api/inventory-assets', [
            'asset_type' => 'server',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('asset_tag');
    }

    public function test_store_rejects_invalid_asset_type(): void
    {
        $response = $this->actingAsApi($this->apiUser())->postJson('/api/inventory-assets', [
            'asset_tag' => 'API-AST-BADTYPE',
            'asset_type' => 'not-a-real-type',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('asset_type');
    }

    public function test_store_rejects_occupied_rack_position(): void
    {
        $rack = $this->rack($this->datacenter());
        $this->makeAsset(['asset_tag' => 'API-AST-HOLDER', 'rack_id' => $rack->id, 'rack_u_position' => 5]);

        $response = $this->actingAsApi($this->apiUser())->postJson('/api/inventory-assets', $this->validPayload([
            'asset_tag' => 'API-AST-NEW',
            'rack_id' => $rack->id,
            'rack_u_position' => 5,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'rack_u_position' => 'That U position is already occupied in the selected rack.',
        ]);
        $this->assertDatabaseMissing('inventory_assets', ['asset_tag' => 'API-AST-NEW']);
    }

    public function test_store_creates_asset_returns_201(): void
    {
        $response = $this->actingAsApi($this->apiUser())->postJson('/api/inventory-assets', $this->validPayload([
            'asset_tag' => 'API-AST-CREATE',
            'vendor' => 'Dell EMC',
            'purchase_cost' => '1499.99',
        ]));

        $response->assertStatus(201);
        $response->assertJsonPath('data.asset_tag', 'API-AST-CREATE');
        $response->assertJsonPath('data.status', 'in_stock');
        $this->assertDatabaseHas('inventory_assets', [
            'asset_tag' => 'API-AST-CREATE',
            'vendor' => 'Dell EMC',
            'purchase_cost' => '1499.99',
        ]);
    }

    public function test_update_changes_asset_fields(): void
    {
        $asset = $this->makeAsset(['asset_tag' => 'API-AST-EDIT']);

        $response = $this->actingAsApi($this->apiUser())->putJson("/api/inventory-assets/{$asset->id}", [
            'model' => 'Updated model',
            'status' => 'installed',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.model', 'Updated model');
        $response->assertJsonPath('data.status', 'installed');
        $this->assertDatabaseHas('inventory_assets', [
            'id' => $asset->id,
            'model' => 'Updated model',
            'status' => 'installed',
        ]);
    }

    public function test_destroy_soft_deletes_asset(): void
    {
        $asset = $this->makeAsset(['asset_tag' => 'API-AST-DELETE']);

        $response = $this->actingAsApi($this->apiUser())->deleteJson("/api/inventory-assets/{$asset->id}");

        $response->assertOk();
        $this->assertSoftDeleted('inventory_assets', ['id' => $asset->id]);
    }

    public function test_update_allows_a_legacy_unchanged_position_without_a_rack(): void
    {
        $asset = $this->makeAsset(['asset_tag' => 'API-AST-LEGACY', 'rack_u_position' => 12]);

        $response = $this->actingAsApi($this->apiUser())->putJson("/api/inventory-assets/{$asset->id}", [
            'rack_u_position' => 12,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.rack_u_position', 12);
        $this->assertDatabaseHas('inventory_assets', ['id' => $asset->id, 'rack_u_position' => 12]);
    }

    public function test_update_rejects_moving_to_an_occupied_position_without_submitting_it(): void
    {
        $datacenter = $this->datacenter();
        $rackA = $this->rack($datacenter, 42, 'Rack A');
        $rackB = $this->rack($datacenter, 42, 'Rack B');
        $holder = $this->makeAsset(['asset_tag' => 'API-AST-HOLDER', 'rack_id' => $rackB->id, 'rack_u_position' => 5]);
        $mover = $this->makeAsset(['asset_tag' => 'API-AST-MOVER', 'rack_id' => $rackA->id, 'rack_u_position' => 5]);

        $response = $this->actingAsApi($this->apiUser())->putJson("/api/inventory-assets/{$mover->id}", [
            'rack_id' => $rackB->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'rack_u_position' => 'That U position is already occupied in the selected rack.',
        ]);

        $this->assertDatabaseHas('inventory_assets', ['id' => $mover->id, 'rack_id' => $rackA->id, 'rack_u_position' => 5]);
        $this->assertDatabaseHas('inventory_assets', ['id' => $holder->id, 'rack_u_position' => 5]);
    }

    public function test_update_clearing_the_rack_also_clears_the_position(): void
    {
        $rack = $this->rack($this->datacenter());
        $asset = $this->makeAsset(['asset_tag' => 'API-AST-CLEAR', 'rack_id' => $rack->id, 'rack_u_position' => 5]);

        $response = $this->actingAsApi($this->apiUser())->putJson("/api/inventory-assets/{$asset->id}", [
            'rack_id' => null,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.rack_id', null);
        $response->assertJsonPath('data.rack_u_position', null);

        $asset->refresh();
        $this->assertNull($asset->rack_id);
        $this->assertNull($asset->rack_u_position);
    }

    public function test_update_position_only_within_the_stored_rack_succeeds(): void
    {
        $rack = $this->rack($this->datacenter());
        $asset = $this->makeAsset(['asset_tag' => 'API-AST-POS-FREE', 'rack_id' => $rack->id, 'rack_u_position' => 5]);

        $response = $this->actingAsApi($this->apiUser())->putJson("/api/inventory-assets/{$asset->id}", [
            'rack_u_position' => 6,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.rack_id', $rack->id);
        $response->assertJsonPath('data.rack_u_position', 6);
        $this->assertDatabaseHas('inventory_assets', [
            'id' => $asset->id,
            'rack_id' => $rack->id,
            'rack_u_position' => 6,
        ]);
    }

    public function test_update_position_only_to_an_occupied_slot_in_the_stored_rack_fails(): void
    {
        $rack = $this->rack($this->datacenter());
        $this->makeAsset(['asset_tag' => 'API-AST-POS-HOLDER', 'rack_id' => $rack->id, 'rack_u_position' => 6]);
        $asset = $this->makeAsset(['asset_tag' => 'API-AST-POS-MOVER', 'rack_id' => $rack->id, 'rack_u_position' => 5]);

        $response = $this->actingAsApi($this->apiUser())->putJson("/api/inventory-assets/{$asset->id}", [
            'rack_u_position' => 6,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'rack_u_position' => 'That U position is already occupied in the selected rack.',
        ]);
        $this->assertDatabaseHas('inventory_assets', ['id' => $asset->id, 'rack_u_position' => 5]);
    }

    public function test_update_position_only_exceeding_the_stored_rack_height_fails(): void
    {
        $rack = $this->rack($this->datacenter(), 42);
        $asset = $this->makeAsset(['asset_tag' => 'API-AST-POS-TALL', 'rack_id' => $rack->id, 'rack_u_position' => 5]);

        $response = $this->actingAsApi($this->apiUser())->putJson("/api/inventory-assets/{$asset->id}", [
            'rack_u_position' => 43,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('rack_u_position');
    }

    public function test_update_position_only_on_a_rackless_asset_is_rejected(): void
    {
        $asset = $this->makeAsset(['asset_tag' => 'API-AST-POS-NORACK', 'rack_u_position' => 12]);

        $response = $this->actingAsApi($this->apiUser())->putJson("/api/inventory-assets/{$asset->id}", [
            'rack_u_position' => 7,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'rack_u_position' => 'Select a rack before setting the U position.',
        ]);
        $this->assertDatabaseHas('inventory_assets', ['id' => $asset->id, 'rack_u_position' => 12]);
    }

    public function test_update_explicit_null_rack_with_a_position_is_still_rejected(): void
    {
        $rack = $this->rack($this->datacenter());
        $asset = $this->makeAsset(['asset_tag' => 'API-AST-NULL-RACK', 'rack_id' => $rack->id, 'rack_u_position' => 5]);

        $response = $this->actingAsApi($this->apiUser())->putJson("/api/inventory-assets/{$asset->id}", [
            'rack_id' => null,
            'rack_u_position' => 6,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'rack_u_position' => 'Select a rack before setting the U position.',
        ]);
        $this->assertDatabaseHas('inventory_assets', ['id' => $asset->id, 'rack_id' => $rack->id, 'rack_u_position' => 5]);
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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'asset_type' => 'server',
            'model' => 'PowerEdge R750',
        ], $overrides);
    }

    private function datacenter(string $code = 'APIDC'): Datacenter
    {
        return Datacenter::create(['name' => "Datacenter {$code}", 'code' => $code, 'status' => 'active']);
    }

    private function rack(Datacenter $datacenter, int $uHeight = 42, string $name = 'Rack API'): Rack
    {
        return Rack::create([
            'datacenter_id' => $datacenter->id,
            'name' => $name,
            'u_height' => $uHeight,
            'status' => 'active',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeAsset(array $overrides = []): InventoryAsset
    {
        $this->assetSequence++;

        return InventoryAsset::create(array_merge([
            'asset_tag' => 'API-AST-'.$this->assetSequence,
            'asset_type' => 'server',
        ], $overrides));
    }
}
