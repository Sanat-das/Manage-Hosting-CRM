<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProvisionComputeVm;
use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\PanelAccount;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductModule;
use App\Models\Role;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Modules\Virtualizor\Services\VirtualizorClient;
use App\Modules\Virtualizor\Virtualizor;
use App\Services\Provisioning\ComputeTemplateCatalog;
use App\Services\Provisioning\ManualProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Virtualizor VM lifecycle parity: OS-template discovery, curated options,
 * per-service template selection through the shared compute pipeline, live
 * state mapping and the restart verb.
 */
final class VirtualizorVmLifecycleTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────── setup ───────────────────────────────

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

    private function virtualizorServer(array $meta = []): Server
    {
        return Server::create([
            'name' => 'vz-1',
            'ip_address' => '10.0.0.30',
            'server_type' => 'virtualizor',
            'api_url' => 'https://vps.example.net:4085',
            'api_username' => 'API-KEY',
            'api_key' => 'API-PASS',
            'max_accounts' => 0,
            'status' => 'active',
            'connection_meta' => array_merge([
                'virtualizor_os_templates' => [
                    ['osid' => '270', 'label' => 'CentOS 6.5'],
                    ['osid' => '448', 'label' => ''],
                ],
                'virtualizor_os_default' => '270',
            ], $meta),
        ]);
    }

    private function productWithVirtualizorLink(array $config = []): Product
    {
        $product = Product::create([
            'name' => 'VZ Product '.uniqid(),
            'price' => 50,
            'provisioning_module' => 'virtualizor',
            'billing_cycle' => 'monthly',
            'status' => 'active',
        ]);

        ProductModule::create([
            'product_id' => $product->id,
            'module_slug' => 'virtualizor',
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

    private function hostingAccount(Customer $customer, Product $product, Server $server, string $hostName = 'vzvm01'): HostingAccount
    {
        return HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'server_id' => $server->id,
            'domain' => 'vps.test',
            'host_name' => $hostName,
            'status' => 'pending',
        ]);
    }

    /** @return array<string, mixed> */
    private function linkConfig(array $extra = []): array
    {
        return array_merge([
            'plan' => '1',
            'cpu' => 2,
            'ram' => 2048,
            'disk' => 50,
        ], $extra);
    }

    // ─────────────────────────── API client ───────────────────────────

    public function test_list_os_templates_parses_sorts_and_dedupes(): void
    {
        Http::fake([
            '*act=ostemplates*' => Http::response(['ostemplates' => [
                '448' => ['osid' => '448', 'type' => 'vzo', 'name' => 'centos-7-minimal', 'active' => '1'],
                '270' => ['osid' => '270', 'type' => 'kvm', 'name' => 'centos-6.5-x86', 'active' => '1'],
                '270x' => ['osid' => '270', 'type' => 'kvm', 'name' => 'duplicate', 'active' => '1'],
                'bad' => ['osid' => 'abc', 'type' => 'kvm', 'name' => 'nope'],
            ]]),
        ]);

        $templates = (new VirtualizorClient($this->virtualizorServer(), verifyTls: false))->listOsTemplates();

        $this->assertSame(['270', '448'], array_column($templates, 'osid'));
        $this->assertSame('centos-6.5-x86', $templates[0]['name']);
        $this->assertSame('vzo', $templates[1]['type']);
    }

    // ──────────────────────────── curation ────────────────────────────

    public function test_catalog_options_respect_the_product_allow_list(): void
    {
        $server = $this->virtualizorServer();

        $all = ComputeTemplateCatalog::options($server, 'virtualizor');
        $this->assertSame(['270', '448'], array_column($all, 'id'));
        $this->assertSame('CentOS 6.5', $all[0]['label']);
        // Blank curated label falls back to the osid.
        $this->assertSame('448', $all[1]['label']);

        $restricted = ComputeTemplateCatalog::options($server, 'virtualizor', ['448']);
        $this->assertSame(['448'], array_column($restricted, 'id'));

        $this->assertSame('270', ComputeTemplateCatalog::default($server, 'virtualizor'));
        $this->assertSame('osid', ComputeTemplateCatalog::templateKey('virtualizor'));
    }

    // ───────────────────────────── state ─────────────────────────────

    public function test_recorded_state_reads_vs_status_and_fails_closed(): void
    {
        $server = $this->virtualizorServer();
        $service = $this->serviceFor($server, '901');
        $panel = PanelAccount::where('service_instance_id', $service->id)->firstOrFail();

        // Responses are sequenced per call: running, then "panel no longer
        // lists it", then a panel error (must be unknown, never "gone").
        $calls = 0;
        Http::fake(function (Request $request) use (&$calls) {
            $calls++;

            return match ($calls) {
                1 => Http::response(['901' => ['status' => 1]]),
                2 => Http::response(['999' => ['status' => 0]]),
                default => Http::response(['error' => 'license expired']),
            };
        });

        $state = (new Virtualizor)->recordedVmState($panel);
        $this->assertTrue($state['exists']);
        $this->assertSame('running', $state['status']);

        $state = (new Virtualizor)->recordedVmState($panel);
        $this->assertFalse($state['exists']);

        $state = (new Virtualizor)->recordedVmState($panel);
        $this->assertNull($state['exists']);
        $this->assertArrayHasKey('error', $state);
    }

    // ──────────────────────────── restart ────────────────────────────

    public function test_restart_refuses_a_stopped_vps(): void
    {
        $server = $this->virtualizorServer();
        $service = $this->serviceFor($server, '901');

        Http::fake(['*act=vs*' => Http::response(['901' => ['status' => 0]])]);
        Http::preventStrayRequests();

        $result = (new Virtualizor)->restart($service, []);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('not running', (string) $result->message);
    }

    public function test_restart_sends_the_restart_signal(): void
    {
        $server = $this->virtualizorServer();
        $service = $this->serviceFor($server, '901');

        Http::fake([
            '*act=vs*' => Http::response([
                '901' => ['status' => 1],
                'done' => true,
            ]),
        ]);
        Http::preventStrayRequests();

        $result = (new Virtualizor)->restart($service, []);

        $this->assertTrue($result->success, (string) $result->message);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'act=vs')
            && str_contains($request->url(), 'restart=901'));
    }

    // ─────────────────────── shared build pipeline ───────────────────────

    public function test_create_action_queues_a_generic_build_with_the_os_template(): void
    {
        Queue::fake();

        $server = $this->virtualizorServer();
        $product = $this->productWithVirtualizorLink($this->linkConfig());
        $account = $this->hostingAccount($this->customer(), $product, $server);

        $this->actingAs($this->adminWith(['hosting.edit']))
            ->post(route('admin.hosting.module-action', $account), [
                'module_slug' => 'virtualizor',
                'action' => 'create',
                'template' => '448',
            ])
            ->assertRedirect();

        Queue::assertPushedOn('provisioning', ProvisionComputeVm::class, function (ProvisionComputeVm $job): bool {
            return $job->moduleSlug === 'virtualizor' && $job->template === '448';
        });
    }

    public function test_manual_provisioner_injects_the_osid(): void
    {
        Http::fake([
            '*act=vs*' => Http::response(['done' => true]),
            '*act=addvs*' => Http::response(['vpsid' => '901', 'done' => ['vpsid' => '901']]),
        ]);
        Http::preventStrayRequests();

        $server = $this->virtualizorServer();
        $product = $this->productWithVirtualizorLink($this->linkConfig(['allowed_templates' => ['448']]));
        $account = $this->hostingAccount($this->customer(), $product, $server);

        $result = app(ManualProvisioner::class)->provision($account, 'virtualizor', '448');

        $this->assertTrue($result->success, (string) $result->message);

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), 'act=addvs')
                && str_contains((string) $request->body(), 'osid=448');
        });
    }

    public function test_admin_hosting_page_renders_the_virtualizor_compute_card(): void
    {
        $server = $this->virtualizorServer();
        $product = $this->productWithVirtualizorLink($this->linkConfig());
        $account = $this->hostingAccount($this->customer(), $product, $server);

        $this->actingAs($this->adminWith(['hosting.view', 'hosting.edit']))
            ->get(route('admin.hosting.show', $account))
            ->assertOk()
            ->assertSee('OS template')
            ->assertSee('CentOS 6.5')
            ->assertSee('value="270"', false);
    }

    // ────────────────────────────── helpers ──────────────────────────────

    private function serviceFor(Server $server, string $vpsId): ServiceInstance
    {
        $service = ServiceInstance::create([
            'customer_id' => $this->customer()->id,
            'server_id' => $server->id,
            'service_tag' => 'SVC-VZ-'.uniqid(),
            'username' => 'vzvm01',
            'domain' => 'vps.test',
            'provisioning_method' => 'virtualizor',
            'status' => 'active',
        ]);

        PanelAccount::create([
            'service_instance_id' => $service->id,
            'server_id' => $server->id,
            'panel' => 'virtualizor',
            'username' => 'vzvm01',
            'external_id' => $vpsId,
            'status' => PanelAccount::STATUS_ACTIVE,
        ]);

        return $service;
    }
}
