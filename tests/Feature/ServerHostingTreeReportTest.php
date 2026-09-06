<?php

namespace Tests\Feature;

use App\Models\AssetRelationship;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerHostingTreeReportTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): self
    {
        $user = User::factory()->create();

        // Seed the admin role with the permissions this report actually requires.
        // hosting-tree is gated by asset-relationships.view (not hosting.view).
        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $view = Permission::firstOrCreate(['name' => 'asset-relationships.view'], ['label' => 'View Asset Relationships']);
        $adminRole->permissions()->syncWithoutDetaching([$view->id]);

        $user->assignRole('admin');

        return $this->actingAs($user);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function relationshipPayload(Server $server, Product $product, array $overrides = []): array
    {
        return array_merge([
            'parent_kind' => 'server',
            'parent_id' => $server->id,
            'child_kind' => 'product',
            'child_id' => $product->id,
            'relationship_type' => 'hosted_on',
            'label' => 'Runs web stack',
            'notes' => 'Reporting link only',
        ], $overrides);
    }

    public function test_lists_children(): void
    {
        $this->actingAsAdmin();

        $server = Server::create(['name' => 'Web-01', 'ip_address' => '10.0.0.10']);
        $productA = Product::create(['name' => 'Shared Hosting Basic']);
        $productB = Product::create(['name' => 'Reseller Pro']);

        AssetRelationship::create($this->relationshipPayload($server, $productA));
        AssetRelationship::create($this->relationshipPayload($server, $productB));

        $response = $this->get("/admin/hosting-tree?server_id={$server->id}");

        $response->assertStatus(200);
        $response->assertSee('Web-01');
        $response->assertSee('Shared Hosting Basic');
        $response->assertSee('Reseller Pro');
    }

    public function test_csv_export(): void
    {
        $this->actingAsAdmin();

        $server = Server::create(['name' => 'Web-01', 'ip_address' => '10.0.0.10']);
        $product = Product::create(['name' => 'Shared Hosting Basic']);

        AssetRelationship::create($this->relationshipPayload($server, $product, ['label' => 'Runs web stack']));

        $response = $this->get("/admin/hosting-tree?server_id={$server->id}&csv=1");

        $response->assertStatus(200);
        $this->assertStringStartsWith('text/csv', (string) $response->headers->get('content-type'));

        $content = $response->streamedContent();

        $this->assertStringContainsString('relationship_type,child_kind,child_id,child_name,label,notes', $content);
        $this->assertStringContainsString('Shared Hosting Basic', $content);
        $this->assertStringContainsString('hosted_on', $content);
    }

    public function test_empty_state(): void
    {
        $this->actingAsAdmin();

        $server = Server::create(['name' => 'Empty-Srv', 'ip_address' => '10.0.0.99']);

        $response = $this->get("/admin/hosting-tree?server_id={$server->id}");

        $response->assertStatus(200);
        $response->assertSee('No hosting tree relationships found for this server.');
    }
}
