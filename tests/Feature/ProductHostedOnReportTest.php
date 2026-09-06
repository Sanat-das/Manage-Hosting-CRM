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

class ProductHostedOnReportTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): self
    {
        $user = User::factory()->create();

        // Seed the admin role and hosting.view permission in the test DB
        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $view = Permission::firstOrCreate(['name' => 'hosting.view'], ['label' => 'View Hosting']);
        $adminRole->permissions()->syncWithoutDetaching([$view->id]);

        $user->assignRole('admin');

        return $this->actingAs($user);
    }

    private function createHostedOnRelationship(Product $child, array $parent): AssetRelationship
    {
        return AssetRelationship::create([
            'parent_kind' => $parent['kind'],
            'parent_id' => $parent['id'],
            'child_kind' => 'product',
            'child_id' => $child->id,
            'relationship_type' => 'hosted_on',
            'label' => $parent['label'] ?? null,
            'sort_order' => 0,
            'notes' => $parent['notes'] ?? null,
        ]);
    }

    public function test_lists_parents(): void
    {
        $this->actingAsAdmin();

        $product = Product::create(['name' => 'Starter Web Hosting']);
        $server = Server::create(['name' => 'web-01', 'ip_address' => '192.168.10.1']);
        $parentProduct = Product::create(['name' => 'cPanel Reseller Bundle']);

        $this->createHostedOnRelationship($product, [
            'kind' => 'server', 'id' => $server->id, 'label' => 'Runs web stack',
        ]);
        $this->createHostedOnRelationship($product, [
            'kind' => 'product', 'id' => $parentProduct->id, 'label' => 'Included bundle member',
        ]);

        $response = $this->get("/admin/product-hosting-tree?product_id={$product->id}");

        $response->assertStatus(200);
        $response->assertSee('Starter Web Hosting');
        $response->assertSee('web-01');
        $response->assertSee('cPanel Reseller Bundle');
    }

    public function test_csv_export(): void
    {
        $this->actingAsAdmin();

        $product = Product::create(['name' => 'Starter Web Hosting']);
        $server = Server::create(['name' => 'web-01', 'ip_address' => '192.168.10.1']);

        $this->createHostedOnRelationship($product, [
            'kind' => 'server', 'id' => $server->id, 'notes' => 'Primary node',
        ]);

        $response = $this->get("/admin/product-hosting-tree?product_id={$product->id}&csv=1");

        $response->assertStatus(200);
        $response->assertHeader('content-type');
        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));

        $content = $response->streamedContent();
        $this->assertStringContainsString('relationship_type,parent_kind,parent_id,parent_name,label,notes', $content);
        $this->assertStringContainsString('web-01', $content);
    }

    public function test_empty_state(): void
    {
        $this->actingAsAdmin();

        $product = Product::create(['name' => 'Empty Product']);

        $response = $this->get("/admin/product-hosting-tree?product_id={$product->id}");

        $response->assertStatus(200);
        $response->assertSee('No hosted-on relationships found for this product.');
    }
}
