<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductModule;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use App\Services\Integrations\IntegrationRegistry;
use App\Services\Provisioning\ComputeTemplateCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Product-level default template picker: union across active servers,
 * merge-save endpoint, validation and the edit-page card.
 */
final class ProductComputeTemplateDefaultTest extends TestCase
{
    use RefreshDatabase;

    private function adminWith(array $perms): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $ids = [];
        foreach ($perms as $name) {
            $ids[] = Permission::firstOrCreate(['name' => $name], ['label' => $name])->id;
        }
        $role->permissions()->sync($ids);
        $user->assignRole('admin');

        return $user;
    }

    private function proxmoxServer(string $name, array $templates, string $status = 'active'): Server
    {
        return Server::create([
            'name' => $name,
            'ip_address' => '10.0.0.'.random_int(10, 250),
            'server_type' => 'proxmox',
            'api_username' => 'root@pam!automation',
            'api_password_encrypted' => 'TOKEN-SECRET',
            'max_accounts' => 0,
            'status' => $status,
            'connection_meta' => [
                'proxmox_templates' => $templates,
            ],
        ]);
    }

    private function productWithLink(string $slug, array $config): Product
    {
        $product = Product::create([
            'name' => 'Prod '.uniqid(),
            'price' => 50,
            'provisioning_module' => $slug,
            'billing_cycle' => 'monthly',
            'status' => 'active',
        ]);

        ProductModule::create([
            'product_id' => $product->id,
            'module_slug' => $slug,
            'enabled' => true,
            'provisioning_mode' => 'auto',
            'config' => $config,
        ]);

        return $product;
    }

    public function test_union_options_span_active_servers_and_dedupe(): void
    {
        $this->proxmoxServer('pve-a', [
            ['vmid' => '113', 'node' => 'pve1', 'label' => 'ubuntu-2204'],
            ['vmid' => '150', 'node' => 'pve1', 'label' => 'debian-12'],
        ]);
        $this->proxmoxServer('pve-b', [
            ['vmid' => '113', 'node' => 'pve2', 'label' => 'ubuntu (dupe)'],
            ['vmid' => '200', 'node' => 'pve2', 'label' => 'almalinux-9'],
        ]);
        $this->proxmoxServer('pve-off', [
            ['vmid' => '999', 'node' => 'pve3', 'label' => 'inactive-server'],
        ], 'inactive');

        $union = ComputeTemplateCatalog::unionOptions('proxmox');

        $this->assertSame(['200', '150', '113'], array_column($union, 'id'));

        $this->assertSame([], ComputeTemplateCatalog::unionOptions('hyperv'));
        $this->assertSame([], ComputeTemplateCatalog::unionOptions('unknown-module'));
    }

    public function test_endpoint_saves_the_default_and_preserves_other_config(): void
    {
        $this->proxmoxServer('pve-a', [['vmid' => '113', 'node' => 'pve1', 'label' => 'ubuntu-2204']]);

        $product = $this->productWithLink('proxmox', [
            'cpu' => 4,
            'ram' => 4096,
            'disk' => 80,
            'template_vmid' => '113',
        ]);

        $this->actingAs($this->adminWith(['products.edit']))
            ->put(route('admin.products.modules.template-default', [$product, 'proxmox']), [
                'template' => '113',
            ])
            ->assertRedirect(route('admin.products.edit', [$product, 'tab' => 'modules']));

        $pivot = ProductModule::where('product_id', $product->id)->where('module_slug', 'proxmox')->firstOrFail();
        $dec = app(IntegrationRegistry::class)->decryptConfigFor('proxmox', $pivot->config);

        $this->assertSame('113', $dec['template_vmid']);
        $this->assertSame(4, $dec['cpu']);
        $this->assertSame(4096, $dec['ram']);
        $this->assertSame(80, $dec['disk']);
    }

    public function test_endpoint_rejects_an_unknown_template(): void
    {
        $this->proxmoxServer('pve-a', [['vmid' => '113', 'node' => 'pve1', 'label' => 'ubuntu-2204']]);

        $product = $this->productWithLink('proxmox', ['template_vmid' => '113']);

        $this->actingAs($this->adminWith(['products.edit']))
            ->from(route('admin.products.edit', [$product, 'tab' => 'modules']))
            ->put(route('admin.products.modules.template-default', [$product, 'proxmox']), [
                'template' => '424242',
            ])
            ->assertRedirect(route('admin.products.edit', [$product, 'tab' => 'modules']))
            ->assertSessionHasErrors('template');

        $pivot = ProductModule::where('product_id', $product->id)->where('module_slug', 'proxmox')->firstOrFail();
        $dec = app(IntegrationRegistry::class)->decryptConfigFor('proxmox', $pivot->config);
        $this->assertSame('113', $dec['template_vmid']);
    }

    public function test_empty_clears_the_default(): void
    {
        $this->proxmoxServer('pve-a', [['vmid' => '113', 'node' => 'pve1', 'label' => 'ubuntu-2204']]);

        $product = $this->productWithLink('proxmox', [
            'cpu' => 2,
            'template_vmid' => '113',
        ]);

        $this->actingAs($this->adminWith(['products.edit']))
            ->put(route('admin.products.modules.template-default', [$product, 'proxmox']), [
                'template' => '',
            ])
            ->assertRedirect(route('admin.products.edit', [$product, 'tab' => 'modules']));

        $pivot = ProductModule::where('product_id', $product->id)->where('module_slug', 'proxmox')->firstOrFail();
        $dec = app(IntegrationRegistry::class)->decryptConfigFor('proxmox', $pivot->config);

        $this->assertArrayNotHasKey('template_vmid', $dec);
        $this->assertSame(2, $dec['cpu']);
    }

    public function test_virtualizor_uses_its_own_key(): void
    {
        Server::create([
            'name' => 'vz-a',
            'ip_address' => '10.0.0.31',
            'server_type' => 'virtualizor',
            'api_username' => 'KEY',
            'api_key' => 'PASS',
            'max_accounts' => 0,
            'status' => 'active',
            'connection_meta' => [
                'virtualizor_os_templates' => [['osid' => '270', 'label' => 'CentOS 6.5']],
            ],
        ]);

        $product = $this->productWithLink('virtualizor', ['plan' => '1', 'osid' => '270']);

        $this->actingAs($this->adminWith(['products.edit']))
            ->put(route('admin.products.modules.template-default', [$product, 'virtualizor']), [
                'template' => '270',
            ])
            ->assertRedirect(route('admin.products.edit', [$product, 'tab' => 'modules']));

        $pivot = ProductModule::where('product_id', $product->id)->where('module_slug', 'virtualizor')->firstOrFail();
        $dec = app(IntegrationRegistry::class)->decryptConfigFor('virtualizor', $pivot->config);
        $this->assertSame('270', $dec['osid']);
    }

    public function test_virtualizor_restriction_endpoint_and_card(): void
    {
        Server::create([
            'name' => 'vz-restrict',
            'ip_address' => '10.0.0.32',
            'server_type' => 'virtualizor',
            'api_username' => 'KEY',
            'api_key' => 'PASS',
            'max_accounts' => 0,
            'status' => 'active',
            'connection_meta' => [
                'virtualizor_os_templates' => [
                    ['osid' => '270', 'label' => 'CentOS 6.5'],
                    ['osid' => '448', 'label' => 'CentOS 7 minimal'],
                ],
            ],
        ]);

        $product = $this->productWithLink('virtualizor', ['plan' => '1', 'osid' => '270']);

        $this->actingAs($this->adminWith(['products.edit']))
            ->from(route('admin.products.edit', [$product, 'tab' => 'modules']))
            ->put(route('admin.products.modules.templates', [$product, 'virtualizor']), [
                'allowed_templates' => ['999'],
            ])
            ->assertSessionHasErrors('allowed_templates');

        $this->actingAs($this->adminWith(['products.edit']))
            ->put(route('admin.products.modules.templates', [$product, 'virtualizor']), [
                'allowed_templates' => ['270'],
            ])
            ->assertRedirect(route('admin.products.edit', [$product, 'tab' => 'modules']));

        $pivot = ProductModule::where('product_id', $product->id)->where('module_slug', 'virtualizor')->firstOrFail();
        $dec = app(IntegrationRegistry::class)->decryptConfigFor('virtualizor', $pivot->config);

        $this->assertSame(['270'], $dec['allowed_templates']);
        $this->assertSame('1', $dec['plan']);
        $this->assertSame('270', $dec['osid']);

        $this->actingAs($this->adminWith(['products.edit']))
            ->get(route('admin.products.edit', [$product, 'tab' => 'modules']))
            ->assertOk()
            ->assertSee('Virtualizor OS templates (this product)')
            ->assertSee('CentOS 6.5');
    }

    public function test_edit_page_renders_the_default_picker(): void
    {
        $this->proxmoxServer('pve-a', [['vmid' => '113', 'node' => 'pve1', 'label' => 'ubuntu-2204']]);

        $product = $this->productWithLink('proxmox', ['template_vmid' => '113']);

        $this->actingAs($this->adminWith(['products.edit']))
            ->get(route('admin.products.edit', [$product, 'tab' => 'modules']))
            ->assertOk()
            ->assertSee('default template')
            ->assertSee('ubuntu-2204')
            ->assertSee('value="113"', false)
            ->assertSee('— Server default —');
    }
}
