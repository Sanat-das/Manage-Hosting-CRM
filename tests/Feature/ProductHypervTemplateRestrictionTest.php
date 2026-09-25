<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductModule;
use App\Models\Role;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Modules\HyperV\HyperV;
use App\Services\Provisioning\HypervTemplateCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductHypervTemplateRestrictionTest extends TestCase
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

    private function hypervServer(array $meta, string $name = 'hv-1'): Server
    {
        return Server::create([
            'name' => $name,
            'ip_address' => '10.0.0.'.random_int(10, 250),
            'server_type' => 'hyperv',
            'api_url' => 'http://10.0.0.9:5985',
            'api_username' => 'admin',
            'api_password_encrypted' => 'SECRET',
            'max_accounts' => 0,
            'status' => 'active',
            'connection_meta' => $meta,
        ]);
    }

    private function productWithHypervLink(array $config = []): Product
    {
        $product = Product::create([
            'name' => 'HV Product '.uniqid(),
            'price' => 50,
            'provisioning_module' => 'hyperv',
            'billing_cycle' => 'monthly',
            'status' => 'active',
        ]);
        ProductModule::create([
            'product_id' => $product->id,
            'module_slug' => 'hyperv',
            'enabled' => true,
            'provisioning_mode' => ProductModule::PROVISIONING_MODE_MANUAL,
            'config' => $config,
        ]);
        return $product;
    }

    private function customer(): Customer
    {
        return Customer::create(['user_id' => User::factory()->create()->id, 'status' => 'active']);
    }

    private function serviceFor(Server $server): ServiceInstance
    {
        return ServiceInstance::create([
            'customer_id' => $this->customer()->id,
            'server_id' => $server->id,
            'service_tag' => 'SVC-'.str()->random(8),
            'username' => 'testvm1',
            'status' => 'active',
        ]);
    }

    private function vmConfig(string $host = 'testvm1'): array
    {
        return ['cpu' => 2, 'ram' => 2048, 'disk' => 50, 'switch' => 'Default Switch', 'generation' => 2, 'host_name' => $host];
    }

    private function fakeCloneSuccess(string $template): callable
    {
        return function ($request) use ($template) {
            $body = (string) $request->body();
            if (str_contains($body, 'Copy-Item')) {
                return Http::response(['vmId' => '11111111-2222-3333-4444-555555555555', 'name' => 'testvm1', 'state' => 'Off', 'vhdPath' => 'C:\\VMs\\testvm1.vhdx', 'switchName' => 'Default Switch', 'generation' => 1, 'cloned' => true, 'templateVm' => $template]);
            }
            if (str_contains($body, 'Test-Path') && str_contains($body, '.vhdx') && ! str_contains($body, $template) && ! str_contains($body, 'Copy-Item')) {
                return Http::response(['vhdPath' => 'C:\\VMs\\testvm1.vhdx']);
            }
            if (str_contains($body, $template) && str_contains($body, 'Get-VM')) {
                return Http::response(['generation' => 1, 'sourceVhd' => 'C:\\VMs\\'.$template.'.vhdx']);
            }
            // Host-verify probe after create (Get-VM -Id): the VM now exists.
            if (str_contains($body, 'Get-VM -Id')) {
                return Http::response(['exists' => true, 'name' => 'testvm1', 'state' => 'Off', 'vmId' => '11111111-2222-3333-4444-555555555555']);
            }
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => false]);
            }
            return Http::response(['error' => 'unexpected'], 500);
        };
    }

    // ── a. endpoint saves while preserving other keys ──
    public function test_endpoint_saves_restriction_preserving_other_keys(): void
    {
        $srv = $this->hypervServer(['template_vms' => ['gold-win01', 'other-tpl'], 'template_vm' => 'gold-win01']);
        $product = $this->productWithHypervLink(['cpu' => 2, 'ram' => 2048, 'disk' => 50, 'switch' => 'Default Switch', 'generation' => 2, 'delete_vhd_on_terminate' => true]);
        // also need second server to ensure union contains gold-win01
        $this->hypervServer(['template_vms' => ['gold-win01']], 'hv-union-2');

        $this->actingAs($this->adminWith(['products.edit']))
            ->put(route('admin.products.modules.templates', [$product, 'hyperv']), ['allowed_templates' => ['gold-win01']])
            ->assertRedirect(route('admin.products.edit', [$product, 'tab' => 'modules']));

        $pivot = ProductModule::where('product_id', $product->id)->where('module_slug', 'hyperv')->firstOrFail();
        $dec = app(\App\Services\Integrations\IntegrationRegistry::class)->decryptConfigFor('hyperv', $pivot->config);
        $this->assertSame(['gold-win01'], $dec['allowed_templates']);
        $this->assertSame(2, $dec['cpu']);
        $this->assertSame(2048, $dec['ram']);
        $this->assertSame(50, $dec['disk']);
        $this->assertSame('Default Switch', $dec['switch']);
        $this->assertSame(2, $dec['generation']);
        $this->assertTrue((bool) $dec['delete_vhd_on_terminate']);
    }

    // ── b. unknown rejected ──
    public function test_endpoint_rejects_unknown_template(): void
    {
        $srv = $this->hypervServer(['template_vms' => ['gold-win01']]);
        $product = $this->productWithHypervLink(['cpu' => 2]);
        $orig = ProductModule::where('product_id', $product->id)->where('module_slug', 'hyperv')->firstOrFail()->config;

        $this->actingAs($this->adminWith(['products.edit']))
            ->put(route('admin.products.modules.templates', [$product, 'hyperv']), ['allowed_templates' => ['evil-tpl']])
            ->assertSessionHasErrors('allowed_templates');

        $fresh = ProductModule::where('product_id', $product->id)->where('module_slug', 'hyperv')->firstOrFail()->config;
        $this->assertSame($orig, $fresh);
    }

    // ── c. empty clears ──
    public function test_endpoint_empty_clears_restriction(): void
    {
        $srv = $this->hypervServer(['template_vms' => ['gold-win01']]);
        $product = $this->productWithHypervLink(['cpu' => 2, 'ram' => 2048, 'disk' => 50, 'switch' => 'Default Switch', 'generation' => 2, 'delete_vhd_on_terminate' => true, 'allowed_templates' => ['gold-win01']]);
        // ensure union contains gold-win01
        $this->assertArrayHasKey('allowed_templates', app(\App\Services\Integrations\IntegrationRegistry::class)->decryptConfigFor('hyperv', ProductModule::where('product_id', $product->id)->where('module_slug', 'hyperv')->firstOrFail()->config));

        $this->actingAs($this->adminWith(['products.edit']))
            ->put(route('admin.products.modules.templates', [$product, 'hyperv']), ['allowed_templates' => []])
            ->assertRedirect();

        $pivot = ProductModule::where('product_id', $product->id)->where('module_slug', 'hyperv')->firstOrFail();
        $dec = app(\App\Services\Integrations\IntegrationRegistry::class)->decryptConfigFor('hyperv', $pivot->config);
        $this->assertArrayNotHasKey('allowed_templates', $dec);
        $this->assertSame(2, $dec['cpu']);
        $this->assertSame('Default Switch', $dec['switch']);
    }

    // ── d. permission / 404s ──
    public function test_endpoint_permission_and_404s(): void
    {
        $srv = $this->hypervServer(['template_vms' => ['gold-win01']]);
        $product = $this->productWithHypervLink(['cpu' => 2]);

        // no permission → 403
        $user = $this->adminWith([]);
        // ensure role has no products.edit
        $this->actingAs($user)
            ->put(route('admin.products.modules.templates', [$product, 'hyperv']), ['allowed_templates' => ['gold-win01']])
            ->assertForbidden();

        // non-hyperv slug → 404
        $this->actingAs($this->adminWith(['products.edit']))
            ->put(route('admin.products.modules.templates', [$product, 'cpanel']), ['allowed_templates' => ['gold-win01']])
            ->assertNotFound();

        // missing link → 404
        $product2 = Product::create(['name' => 'NoLink', 'price' => 10, 'provisioning_module' => 'hyperv', 'billing_cycle' => 'monthly', 'status' => 'active']);
        $this->actingAs($this->adminWith(['products.edit']))
            ->put(route('admin.products.modules.templates', [$product2, 'hyperv']), ['allowed_templates' => ['gold-win01']])
            ->assertNotFound();
    }

    // ── e. edit page renders card ──
    public function test_edit_page_renders_card_with_union_and_selection(): void
    {
        $srv1 = $this->hypervServer(['template_vms' => ['gold-win01', 'other-tpl'], 'template_labels' => ['gold-win01' => 'Gold Win01', 'other-tpl' => 'Other'], 'template_vm' => 'gold-win01'], 'hv-a');
        $srv2 = $this->hypervServer(['template_vms' => ['third-tpl'], 'template_labels' => ['third-tpl' => 'Third']], 'hv-b');
        $product = $this->productWithHypervLink(['cpu' => 2, 'allowed_templates' => ['gold-win01']]);

        $resp = $this->actingAs($this->adminWith(['products.edit']))
            ->get(route('admin.products.edit', $product))
            ->assertOk();
        $resp->assertSee('Hyper-V templates (this product)', false);
        $resp->assertSee('Gold Win01', false);
        $resp->assertSee('gold-win01', false);
        $resp->assertSee('other-tpl', false);
        $resp->assertSee('third-tpl', false);
        // checked
        $resp->assertSee('value="gold-win01"', false);
        // mode radio
        $resp->assertSee('Restrict to selected', false);
        $resp->assertSee('All curated templates', false);
        $resp->assertSee(route('admin.products.modules.templates', [$product, 'hyperv']), false);
    }

    public function test_edit_page_shows_hint_when_no_link(): void
    {
        $srv = $this->hypervServer(['template_vms' => ['gold-win01']]);
        $product = Product::create(['name' => 'NoLink2', 'price' => 10, 'provisioning_module' => 'hyperv', 'billing_cycle' => 'monthly', 'status' => 'active']);
        // no ProductModule row

        $this->actingAs($this->adminWith(['products.edit']))
            ->get(route('admin.products.edit', $product))
            ->assertOk()
            ->assertSee('Save the product with the Hyper-V provisioning module first.', false);
    }

    // ── f. ManualProvisioner ──
    public function test_manual_provisioner_rejects_outside_restriction(): void
    {
        $server = $this->hypervServer(['template_vms' => ['gold-win01', 'other-tpl'], 'template_vm' => 'gold-win01']);
        $this->hypervServer(['template_vms' => ['gold-win01', 'other-tpl']], 'hv-union-2');
        $product = $this->productWithHypervLink(['cpu' => 2, 'ram' => 2048, 'disk' => 50, 'switch' => 'Default Switch', 'generation' => 2, 'allowed_templates' => ['gold-win01']]);
        $account = HostingAccount::create(['customer_id' => $this->customer()->id, 'product_id' => $product->id, 'server_id' => $server->id, 'status' => 'pending', 'host_name' => 'testvm1']);
        Http::fake();
        $result = app(\App\Services\Provisioning\ManualProvisioner::class)->provision($account, 'hyperv', 'other-tpl');
        $this->assertFalse($result->success);
        Http::assertNothingSent();
    }

    public function test_manual_provisioner_allows_inside_restriction(): void
    {
        $server = $this->hypervServer(['template_vms' => ['gold-win01', 'other-tpl'], 'template_vm' => 'gold-win01']);
        $this->hypervServer(['template_vms' => ['gold-win01']], 'hv-union-2');
        $product = $this->productWithHypervLink(['cpu' => 2, 'ram' => 2048, 'disk' => 50, 'switch' => 'Default Switch', 'generation' => 2, 'allowed_templates' => ['gold-win01']]);
        $account = HostingAccount::create(['customer_id' => $this->customer()->id, 'product_id' => $product->id, 'server_id' => $server->id, 'status' => 'pending', 'host_name' => 'testvm1']);
        Http::fake($this->fakeCloneSuccess('gold-win01'));
        $result = app(\App\Services\Provisioning\ManualProvisioner::class)->provision($account, 'hyperv', 'gold-win01');
        $this->assertTrue($result->success);
        Http::assertSent(function ($req) { return str_contains((string) $req->body(), 'Copy-Item'); });
    }

    public function test_manual_provisioner_fails_when_restriction_excludes_all_curated(): void
    {
        $server = $this->hypervServer(['template_vms' => ['gold-win01', 'other-tpl'], 'template_vm' => 'gold-win01']);
        $other = $this->hypervServer(['template_vms' => ['third-tpl']], 'hv-other');
        // union = gold-win01, other-tpl, third-tpl ; server curated = gold-win01, other-tpl ; allowed = third-tpl => effective empty
        $product = $this->productWithHypervLink(['cpu' => 2, 'ram' => 2048, 'disk' => 50, 'switch' => 'Default Switch', 'generation' => 2, 'allowed_templates' => ['third-tpl']]);
        $account = HostingAccount::create(['customer_id' => $this->customer()->id, 'product_id' => $product->id, 'server_id' => $server->id, 'status' => 'pending', 'host_name' => 'testvm1']);
        Http::fake();
        $result = app(\App\Services\Provisioning\ManualProvisioner::class)->provision($account, 'hyperv', null);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('no template is allowed for this product', strtolower($result->message));
        Http::assertNothingSent();
    }

    public function test_manual_provisioner_backward_compat_all_curated(): void
    {
        $server = $this->hypervServer(['template_vms' => ['gold-win01', 'other-tpl'], 'template_vm' => 'gold-win01']);
        $product = $this->productWithHypervLink(['cpu' => 2, 'ram' => 2048, 'disk' => 50, 'switch' => 'Default Switch', 'generation' => 2]);
        $account = HostingAccount::create(['customer_id' => $this->customer()->id, 'product_id' => $product->id, 'server_id' => $server->id, 'status' => 'pending', 'host_name' => 'testvm1']);
        Http::fake($this->fakeCloneSuccess('other-tpl'));
        $result = app(\App\Services\Provisioning\ManualProvisioner::class)->provision($account, 'hyperv', 'other-tpl');
        $this->assertTrue($result->success);
    }

    // ── g. Module enforcement ──
    public function test_module_rejects_explicit_outside_restriction(): void
    {
        $server = $this->hypervServer(['template_vms' => ['gold-win01', 'other-tpl'], 'template_vm' => 'gold-win01']);
        $this->hypervServer(['template_vms' => ['gold-win01', 'other-tpl']], 'hv-union-2');
        $service = $this->serviceFor($server);
        Http::fake();
        $result = app(HyperV::class)->provision($service, $this->vmConfig() + ['template_vm' => 'other-tpl', 'allowed_templates' => ['gold-win01']]);
        $this->assertFalse($result->success);
        Http::assertNothingSent();
    }

    public function test_module_fails_when_default_outside_restriction(): void
    {
        $server = $this->hypervServer(['template_vms' => ['gold-win01', 'other-tpl'], 'template_vm' => 'gold-win01']);
        $this->hypervServer(['template_vms' => ['gold-win01', 'other-tpl', 'third-tpl']], 'hv-union-2');
        // server default gold-win01 not in allowed [other-tpl] -> no default -> loud fail
        $service = $this->serviceFor($server);
        Http::fake();
        $cfg = $this->vmConfig();
        unset($cfg['template_vm']); // use default path
        $cfg['allowed_templates'] = ['other-tpl'];
        $result = app(HyperV::class)->provision($service, $cfg);
        $this->assertFalse($result->success);
        // Should be loud about no template selected / no default or no template allowed ; at least fail
        Http::assertNothingSent();
    }

    // ── h. UI effective lists ──
    public function test_admin_modal_shows_only_effective_and_empty_state(): void
    {
        $server = $this->hypervServer(['template_vms' => ['gold-win01', 'other-tpl'], 'template_labels' => ['gold-win01' => 'Gold', 'other-tpl' => 'Other'], 'template_vm' => 'gold-win01'], 'hv-admin');
        $this->hypervServer(['template_vms' => ['gold-win01', 'other-tpl']], 'hv-admin-union');
        $product = $this->productWithHypervLink(['cpu' => 2, 'allowed_templates' => ['gold-win01']]);
        $account = HostingAccount::create(['customer_id' => $this->customer()->id, 'product_id' => $product->id, 'server_id' => $server->id, 'status' => 'pending', 'host_name' => 'host-admin1']);

        $resp = $this->actingAs($this->adminWith(['hosting.view', 'hosting.edit']))->get(route('admin.hosting.show', $account))->assertOk();
        // effective only gold-win01
        $resp->assertSee('Gold', false);
        $resp->assertDontSee('Other', false);
        // Should have select with gold-win01
        $resp->assertSee('value="gold-win01"', false);

        // now restriction excludes everything
        $otherServer = $this->hypervServer(['template_vms' => ['third-tpl']], 'hv-third');
        $product2 = $this->productWithHypervLink(['cpu' => 2, 'allowed_templates' => ['third-tpl']]);
        $account2 = HostingAccount::create(['customer_id' => $this->customer()->id, 'product_id' => $product2->id, 'server_id' => $server->id, 'status' => 'pending', 'host_name' => 'host-admin2']);
        $resp2 = $this->actingAs($this->adminWith(['hosting.view', 'hosting.edit']))->get(route('admin.hosting.show', $account2))->assertOk();
        $resp2->assertSee('No templates are available for this product on this server', false);
        $resp2->assertDontSee('id="hv-template-', false);
    }

    public function test_client_card_shows_only_effective_and_empty_state(): void
    {
        $server = $this->hypervServer(['template_vms' => ['gold-win01', 'other-tpl'], 'template_labels' => ['gold-win01' => 'Gold', 'other-tpl' => 'Other'], 'template_vm' => 'gold-win01'], 'hv-client');
        $this->hypervServer(['template_vms' => ['gold-win01', 'other-tpl']], 'hv-client-union');
        $product = $this->productWithHypervLink(['cpu' => 2, 'allowed_templates' => ['gold-win01']]);
        $custUser = User::factory()->create();
        $customer = Customer::create(['user_id' => $custUser->id, 'status' => 'active']);
        $account = HostingAccount::create(['customer_id' => $customer->id, 'product_id' => $product->id, 'server_id' => $server->id, 'status' => 'pending', 'host_name' => 'host-client1']);
        $this->actingAs($custUser)->get(route('client.hosting.show', $account->id))->assertOk()->assertSee('Gold', false)->assertDontSee('Other', false);

        // excludes everything
        $product2 = $this->productWithHypervLink(['cpu' => 2, 'allowed_templates' => ['third-tpl']]);
        $this->hypervServer(['template_vms' => ['third-tpl']], 'hv-third-client');
        $account2 = HostingAccount::create(['customer_id' => $customer->id, 'product_id' => $product2->id, 'server_id' => $server->id, 'status' => 'pending', 'host_name' => 'host-client2']);
        $this->actingAs($custUser)->get(route('client.hosting.show', $account2->id))->assertOk()->assertSee('This service has no template available', false)->assertDontSee('Provision VM', false);
    }

    // ── i. catalog sanity ──
    public function test_catalog_union_and_effective_and_sanitize(): void
    {
        Server::query()->delete();
        $s1 = $this->hypervServer(['template_vms' => ['b', 'a'], 'template_labels' => ['b' => 'Beta', 'a' => 'Alpha']], 'hv-cat-1');
        $s2 = $this->hypervServer(['template_vms' => ['a', 'c'], 'template_labels' => ['a' => 'Alpha2', 'c' => 'Gamma']], 'hv-cat-2');
        $union = HypervTemplateCatalog::unionOptions();
        $names = array_column($union, 'name');
        $this->assertSame(['a', 'b', 'c'], $names); // sorted by label Alpha,Beta,Gamma
        $this->assertSame('Alpha', collect($union)->firstWhere('name', 'a')['label']); // first label wins

        $eff = HypervTemplateCatalog::effectiveNames($s1, ['a', 'c', 'b']);
        $this->assertSame(['b', 'a'], $eff); // preserves server curated order b,a ; c not in curated

        $san = HypervTemplateCatalog::sanitizeAllowed([' a ', '', 'a', 'b', str_repeat('x', 70), 'b']);
        $this->assertSame(['a', 'b', str_repeat('x', 64)], $san);
        $this->assertCount(1, HypervTemplateCatalog::sanitizeAllowed(array_fill(0, 60, 'tpl')));
        $many = array_map(fn($i) => "tpl-$i", range(0, 60));
        $this->assertCount(50, HypervTemplateCatalog::sanitizeAllowed($many));
    }
}
