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
use App\Modules\Proxmox\Proxmox;
use App\Modules\Proxmox\Services\ProxmoxClient;
use App\Services\Provisioning\ManualProvisioner;
use App\Services\Provisioning\VmStatusPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Proxmox VE parity with the Hyper-V VM lifecycle: per-service template
 * selection, queued build through the shared compute pipeline, live state
 * mapping and the newly added restart verb.
 */
final class ProxmoxVmLifecycleTest extends TestCase
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

    private function proxmoxServer(string $name = 'pve-actions'): Server
    {
        return Server::create([
            'name' => $name,
            'ip_address' => '10.0.0.20',
            'server_type' => 'proxmox',
            'api_username' => 'root@pam!automation',
            'api_password_encrypted' => 'TOKEN-SECRET',
            'max_accounts' => 0,
            'status' => 'active',
            'connection_meta' => [
                'port' => 8006,
                'auth_type' => 'token',
                'verify_tls' => false,
                'proxmox_templates' => [
                    ['vmid' => '113', 'node' => 'pve1', 'label' => 'ubuntu-2204'],
                    ['vmid' => '150', 'node' => 'pve1', 'label' => 'debian-12'],
                ],
                'proxmox_template_default' => '113',
            ],
        ]);
    }

    private function productWithProxmoxLink(array $config = []): Product
    {
        $product = Product::create([
            'name' => 'PVE Product '.uniqid(),
            'price' => 50,
            'provisioning_module' => 'proxmox',
            'billing_cycle' => 'monthly',
            'status' => 'active',
        ]);

        ProductModule::create([
            'product_id' => $product->id,
            'module_slug' => 'proxmox',
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

    private function hostingAccount(Customer $customer, Product $product, Server $server, string $hostName = 'pvevm01'): HostingAccount
    {
        return HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'server_id' => $server->id,
            'domain' => 'vm.test',
            'host_name' => $hostName,
            'status' => 'pending',
        ]);
    }

    /** @return array<string, mixed> */
    private function linkConfig(array $extra = []): array
    {
        return array_merge([
            'cpu' => 2,
            'ram' => 2048,
            'disk' => 50,
            'start_after_create' => false,
        ], $extra);
    }

    /**
     * Minimal PVE surface for a clone provision: nodes, next id, clone/resize
     * tasks, vm config and the post-build existence probe.
     */
    private function fakePve(string $status = 'stopped'): void
    {
        Http::fake([
            '*/api2/json/nodes' => Http::response(['data' => [
                ['node' => 'pve1', 'status' => 'online'],
            ]]),
            '*/api2/json/nodes/*/tasks/*' => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]),
            '*/api2/json/cluster/nextid?vmid=*' => function (Request $request) {
                return Http::response(['data' => (string) ($request->data()['vmid'] ?? 100)]);
            },
            '*/api2/json/cluster/nextid' => Http::response(['data' => '100']),
            '*qemu/*/clone' => Http::response(['data' => 'UPID:pve1:0000:clone']),
            '*qemu/*/resize' => Http::response(['data' => 'UPID:pve1:0000:resize']),
            '*qemu/*/status/reboot' => Http::response(['data' => 'UPID:pve1:0000:reboot']),
            '*qemu/*/status/start' => Http::response(['data' => 'UPID:pve1:0000:start']),
            '*qemu/*/config' => Http::response(['data' => ['scsi0' => 'local-lvm:vm-100-disk-0,size=50G']]),
            '*qemu/*/status/current' => Http::response(['data' => ['status' => $status]]),
        ]);

        Http::preventStrayRequests();
    }

    // ─────────────────────────── admin card ───────────────────────────

    public function test_admin_hosting_page_renders_the_proxmox_compute_card(): void
    {
        $server = $this->proxmoxServer();
        $product = $this->productWithProxmoxLink($this->linkConfig());
        $account = $this->hostingAccount($this->customer(), $product, $server);

        $this->actingAs($this->adminWith(['hosting.view', 'hosting.edit']))
            ->get(route('admin.hosting.show', $account))
            ->assertOk()
            ->assertSee('Template VMID')
            ->assertSee('ubuntu-2204')
            ->assertSee('value="113"', false)
            ->assertSee('Create VM');
    }

    public function test_create_action_queues_a_generic_build_with_the_picked_template(): void
    {
        Queue::fake();

        $server = $this->proxmoxServer();
        $product = $this->productWithProxmoxLink($this->linkConfig());
        $account = $this->hostingAccount($this->customer(), $product, $server);

        $this->actingAs($this->adminWith(['hosting.edit']))
            ->post(route('admin.hosting.module-action', $account), [
                'module_slug' => 'proxmox',
                'action' => 'create',
                'template' => '150',
                'start_after_create' => '1',
            ])
            ->assertRedirect();

        Queue::assertPushedOn('provisioning', ProvisionComputeVm::class, function (ProvisionComputeVm $job): bool {
            return $job->moduleSlug === 'proxmox'
                && $job->template === '150'
                && $job->startAfterCreate === true;
        });
    }

    // ───────────────────── manual provisioner rules ─────────────────────

    public function test_manual_provisioner_rejects_a_template_outside_the_product_restriction(): void
    {
        $server = $this->proxmoxServer();
        $product = $this->productWithProxmoxLink($this->linkConfig(['allowed_templates' => ['113']]));
        $account = $this->hostingAccount($this->customer(), $product, $server);

        $result = app(ManualProvisioner::class)->provision($account, 'proxmox', '900');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('not in the curated list', (string) $result->message);
    }

    public function test_manual_provisioner_injects_the_picked_template_vmid(): void
    {
        $this->fakePve();

        $server = $this->proxmoxServer();
        $product = $this->productWithProxmoxLink($this->linkConfig(['allowed_templates' => ['113']]));
        $account = $this->hostingAccount($this->customer(), $product, $server);

        $result = app(ManualProvisioner::class)->provision($account, 'proxmox', '113');

        $this->assertTrue($result->success, (string) $result->message);

        $panel = PanelAccount::where('panel', 'proxmox')->firstOrFail();
        $this->assertSame('113', $panel->meta['meta']['template_vmid']);
    }

    // ───────────────────────── status presenter ─────────────────────────

    public function test_status_presenter_maps_pve_stopped_state_and_gates_reset(): void
    {
        Http::fake([
            '*/api2/json/nodes/pve1/qemu/901/status/current' => Http::response(['data' => ['status' => 'stopped', 'vmid' => 901]]),
        ]);
        Http::preventStrayRequests();

        $server = $this->proxmoxServer();
        $product = $this->productWithProxmoxLink($this->linkConfig());
        $customer = $this->customer();
        $account = $this->hostingAccount($customer, $product, $server, 'pvevm02');

        $service = ServiceInstance::create([
            'customer_id' => $customer->id,
            'server_id' => $server->id,
            'service_tag' => 'HOST-'.$account->id,
            'username' => 'pvevm02',
            'domain' => 'vm.test',
            'provisioning_method' => 'proxmox',
            'status' => 'active',
        ]);

        PanelAccount::create([
            'service_instance_id' => $service->id,
            'server_id' => $server->id,
            'panel' => 'proxmox',
            'username' => 'pvevm02',
            'external_id' => '901',
            'meta' => ['meta' => ['node' => 'pve1', 'vmid' => 901]],
            'status' => PanelAccount::STATUS_ACTIVE,
        ]);

        $status = app(VmStatusPresenter::class)->build($account->fresh(), true);

        $this->assertTrue($status['vm']['exists']);
        $this->assertSame('stopped', $status['vm']['state']);
        $this->assertFalse($status['can']['create']);
        $this->assertTrue($status['can']['start']);
        $this->assertTrue($status['can']['delete']);
        $this->assertFalse($status['can']['reset_password']);
    }

    // ───────────────────────────── restart ─────────────────────────────

    public function test_restart_reboots_the_vm_through_pve(): void
    {
        $this->fakePve('running');

        $server = $this->proxmoxServer();
        $customer = $this->customer();

        $service = ServiceInstance::create([
            'customer_id' => $customer->id,
            'server_id' => $server->id,
            'service_tag' => 'SVC-RESTART',
            'username' => 'pvevm03',
            'domain' => 'vm.test',
            'provisioning_method' => 'proxmox',
            'status' => 'active',
        ]);

        PanelAccount::create([
            'service_instance_id' => $service->id,
            'server_id' => $server->id,
            'panel' => 'proxmox',
            'username' => 'pvevm03',
            'external_id' => '901',
            'meta' => ['meta' => ['node' => 'pve1', 'vmid' => 901]],
            'status' => PanelAccount::STATUS_ACTIVE,
        ]);

        $result = (new Proxmox)->restart($service, []);

        $this->assertTrue($result->success, (string) $result->message);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/nodes/pve1/qemu/901/status/reboot'));
    }

    /**
     * Regression (found on live PVE 9.1): write bodies must be form-encoded.
     * A parameter-less POST carried Laravel's default JSON body, which
     * serialises an empty array as `[]` — PVE answers HTTP 500 "Not a HASH
     * reference", so every start/reboot failed on a real cluster. DELETE is
     * stricter still: ANY body gets HTTP 501 "Unexpected content for method
     * 'DELETE'", so its parameters must travel in the query string. The
     * faked suite stayed green because it never inspected the body.
     */
    public function test_write_verbs_send_form_encoded_bodies(): void
    {
        $this->fakePve('stopped');
        Http::fake(['*qemu/901?*' => Http::response(['data' => 'UPID:pve1:0000:destroy'])]);

        $client = new ProxmoxClient($this->proxmoxServer());

        $client->startVm('pve1', 901);
        $client->rebootVm('pve1', 901);
        $client->destroyVm('pve1', 901);

        $assertFormEncoded = function (string $pathFragment): void {
            Http::assertSent(function (Request $request) use ($pathFragment): bool {
                if ($request->method() !== 'POST' || ! str_contains($request->url(), $pathFragment)) {
                    return false;
                }

                $contentType = (string) ($request->header('Content-Type')[0] ?? '');

                return ! str_contains($contentType, 'application/json')
                    && trim((string) $request->body()) !== '[]';
            });
        };

        $assertFormEncoded('/status/start');
        $assertFormEncoded('/status/reboot');

        // DELETE: no body at all; purge flags in the query string.
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'DELETE'
                && str_contains($request->url(), '/qemu/901')
                && str_contains($request->url(), 'purge=1')
                && trim((string) $request->body()) === '';
        });
    }

    public function test_status_presenter_allows_reset_for_a_running_proxmox_vm(): void
    {
        Http::fake([
            '*/api2/json/nodes/pve1/qemu/901/status/current' => Http::response(['data' => ['status' => 'running', 'vmid' => 901]]),
        ]);
        Http::preventStrayRequests();

        $server = $this->proxmoxServer();
        $product = $this->productWithProxmoxLink($this->linkConfig());
        $customer = $this->customer();
        $account = $this->hostingAccount($customer, $product, $server, 'pvevm04');

        $service = ServiceInstance::create([
            'customer_id' => $customer->id,
            'server_id' => $server->id,
            'service_tag' => 'HOST-'.$account->id,
            'username' => 'pvevm04',
            'domain' => 'vm.test',
            'provisioning_method' => 'proxmox',
            'status' => 'active',
        ]);

        PanelAccount::create([
            'service_instance_id' => $service->id,
            'server_id' => $server->id,
            'panel' => 'proxmox',
            'username' => 'pvevm04',
            'external_id' => '901',
            'meta' => ['meta' => ['node' => 'pve1', 'vmid' => 901]],
            'status' => PanelAccount::STATUS_ACTIVE,
        ]);

        $status = app(VmStatusPresenter::class)->build($account->fresh(), true);

        $this->assertTrue($status['vm']['exists']);
        $this->assertSame('running', $status['vm']['state']);
        $this->assertTrue($status['can']['reset_password']);
        $this->assertArrayNotHasKey('reset_password', $status['reasons']);
    }

    /**
     * A module action's page reload renders immediately; the presenter's 10s
     * live-state probe cache must be dropped first or the card replays the
     * pre-action state (a stopped VM shown after Start).
     */
    public function test_module_action_forgets_the_cached_vm_state(): void
    {
        $this->fakePve('stopped');

        $server = $this->proxmoxServer();
        $product = $this->productWithProxmoxLink($this->linkConfig());
        $account = $this->hostingAccount($this->customer(), $product, $server, 'pvevm-cache');

        // The same shape ManualProvisioner::serviceForHosting() resolves.
        $service = ServiceInstance::create([
            'customer_id' => $account->customer_id,
            'server_id' => $server->id,
            'domain' => $account->domain,
            'service_tag' => 'SVC-CACHE',
            'username' => 'pvevm-cache',
            'provisioning_method' => 'proxmox',
            'status' => 'active',
        ]);

        PanelAccount::create([
            'service_instance_id' => $service->id,
            'server_id' => $server->id,
            'panel' => 'proxmox',
            'username' => 'pvevm-cache',
            'external_id' => '901',
            'meta' => ['meta' => ['node' => 'pve1', 'vmid' => 901]],
            'status' => PanelAccount::STATUS_ACTIVE,
        ]);

        // Simulate a page render that cached the live state a moment ago.
        $key = "proxmox:vm-state:{$account->id}";
        Cache::put($key, ['exists' => true, 'state' => 'stopped'], 10);
        $this->assertTrue(Cache::has($key));

        $this->actingAs($this->adminWith(['hosting.edit']))
            ->post(route('admin.hosting.module-action', $account), [
                'module_slug' => 'proxmox',
                'action' => 'start',
            ])
            ->assertRedirect();

        $this->assertFalse(Cache::has($key), 'The post-action render must not replay the pre-action VM state.');
    }
}
