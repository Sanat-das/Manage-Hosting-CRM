<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\PanelAccount;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductModule;
use App\Models\ProvisioningEvent;
use App\Models\Role;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Modules\Proxmox\Proxmox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Proxmox VE rename parity with Hyper-V: the driver sanitizes the name for
 * PVE, renames through `PUT /nodes/{node}/qemu/{vmid}/config`, verifies via
 * the read-back config, rewrites the recorded meta (VMID untouched), refuses
 * unrecorded VMs — and the admin host_name change renames a proxmox-linked
 * VM the same way it renames a Hyper-V one.
 */
final class ProxmoxRenameTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_rename_puts_the_sanitized_name_and_rewrites_meta(): void
    {
        $server = $this->proxmoxServer();
        $service = $this->service($server);
        $account = $this->panelAccount($service, $server, 'old-name');
        $this->fakePveRename('pve-new-01');

        $result = app(Proxmox::class)->rename($service, 'pve-new-01');

        $this->assertTrue($result->success, (string) $result->message);
        $this->assertStringContainsString('pve-new-01', (string) $result->message);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && str_contains($request->url(), '/nodes/pve1/qemu/901/config')
            && ($request->data()['name'] ?? null) === 'pve-new-01');

        $meta = $account->fresh()->meta;
        $this->assertSame('pve-new-01', $meta['name'] ?? null);
        $this->assertSame('pve-new-01', $meta['meta']['name'] ?? null);
        // The VMID linkage is untouched.
        $this->assertSame('901', $account->fresh()->external_id);
    }

    public function test_driver_rename_sanitizes_and_caps_at_63_chars(): void
    {
        $server = $this->proxmoxServer();
        $service = $this->service($server);
        $account = $this->panelAccount($service, $server, 'old-name');

        $raw = 'Web Server (prod) #1! A Very Long Suffix That Pushes Past Sixty Three Characters XYZ';
        $expected = substr(preg_replace('/[^A-Za-z0-9._-]/', '-', trim($raw)) ?? '', 0, 63);
        $this->assertNotSame($raw, $expected);
        $this->fakePveRename($expected);

        $result = app(Proxmox::class)->rename($service, $raw);

        $this->assertTrue($result->success, (string) $result->message);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && str_contains($request->url(), '/nodes/pve1/qemu/901/config')
            && ($request->data()['name'] ?? null) === $expected);

        $sent = $expected;
        $this->assertLessThanOrEqual(63, strlen($sent));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]+$/', $sent);

        $meta = $account->fresh()->meta;
        $this->assertSame($expected, $meta['name'] ?? null);
        $this->assertSame($expected, $meta['meta']['name'] ?? null);
        $this->assertSame('901', $account->fresh()->external_id);
    }

    public function test_driver_rename_refuses_an_unrecorded_vm(): void
    {
        $server = $this->proxmoxServer();
        $service = $this->service($server);
        PanelAccount::create([
            'service_instance_id' => $service->id,
            'server_id' => $server->id,
            'panel' => 'proxmox',
            'username' => 'pvevm01',
            'external_id' => null,
            'meta' => ['meta' => ['node' => 'pve1']],
            'status' => PanelAccount::STATUS_ACTIVE,
        ]);

        Http::preventStrayRequests();
        Http::fake();

        $result = app(Proxmox::class)->rename($service, 'pve-new-01');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('VMID', (string) $result->message);

        Http::assertNothingSent();
    }

    public function test_driver_rename_fails_when_the_read_back_name_does_not_stick(): void
    {
        $server = $this->proxmoxServer();
        $service = $this->service($server);
        $account = $this->panelAccount($service, $server, 'old-name');
        $this->fakePveRename('something-else');

        $result = app(Proxmox::class)->rename($service, 'pve-new-01');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('did not stick', (string) $result->message);

        // The recorded name still describes the host truth, not the request.
        $this->assertSame('old-name', $account->fresh()->meta['name'] ?? null);
    }

    public function test_admin_hosting_update_renames_a_proxmox_vm_and_records_the_event(): void
    {
        $server = $this->proxmoxServer();
        $product = Product::create(['name' => 'PVE', 'price' => 50]);
        ProductModule::create([
            'product_id' => $product->id, 'module_slug' => 'proxmox', 'enabled' => true, 'config' => [],
        ]);
        $customer = $this->makeCustomer();
        $hosting = HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'server_id' => $server->id,
            'domain' => 'vm.test',
            'host_name' => 'pve-old-01',
            'status' => 'active',
        ]);
        $service = ServiceInstance::create([
            'customer_id' => $customer->id,
            'order_id' => null,
            'server_id' => $server->id,
            'domain' => 'vm.test',
            'service_tag' => 'HOST-'.$hosting->id,
            'username' => 'pve-old-01',
            'provisioning_method' => 'proxmox',
            'status' => 'active',
        ]);
        $account = PanelAccount::create([
            'service_instance_id' => $service->id,
            'server_id' => $server->id,
            'panel' => 'proxmox',
            'username' => 'pve-old-01',
            'external_id' => '901',
            'meta' => ['node' => 'pve1', 'vmid' => 901, 'name' => 'pve-old-01', 'meta' => ['node' => 'pve1', 'vmid' => 901, 'name' => 'pve-old-01']],
            'status' => PanelAccount::STATUS_ACTIVE,
        ]);

        $newName = 'pve-renamed-01';
        $this->fakePveRename($newName);

        $this->actingAsAdminWith(['hosting.edit'])
            ->put(route('admin.hosting.update', $hosting), [
                'customer_id' => $hosting->customer_id,
                'product_id' => $hosting->product_id,
                'host_name' => $newName,
            ])
            ->assertRedirect(route('admin.hosting.show', $hosting))
            ->assertSessionHas('success');

        $success = session('success');
        $this->assertStringContainsString('Proxmox VE VM renamed', (string) $success);

        $event = ProvisioningEvent::where('hosting_account_id', $hosting->id)
            ->where('event_type', 'update')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('completed', $event->status);
        $this->assertSame('rename', $event->payload['action'] ?? null);
        $this->assertSame('proxmox', $event->payload['module'] ?? null);
        $this->assertSame($newName, $event->payload['new_name'] ?? null);

        $meta = $account->fresh()->meta;
        $this->assertSame($newName, $meta['name'] ?? null);
        $this->assertSame('901', $account->fresh()->external_id);
    }

    // ─────────────────────────── helpers ───────────────────────────

    private function proxmoxServer(): Server
    {
        return Server::create([
            'name' => 'pve-rename',
            'ip_address' => '10.0.0.20',
            'server_type' => 'proxmox',
            'api_username' => 'root@pam!automation',
            'api_password_encrypted' => 'TOKEN-SECRET',
            'max_accounts' => 0,
            'status' => 'active',
            'connection_meta' => ['port' => 8006, 'auth_type' => 'token', 'verify_tls' => false],
        ]);
    }

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'active',
        ]);
    }

    private function service(Server $server): ServiceInstance
    {
        return ServiceInstance::create([
            'customer_id' => $this->makeCustomer()->id,
            'server_id' => $server->id,
            'service_tag' => 'SVC-'.str()->random(8),
            'username' => 'pvevm01',
            'status' => 'active',
        ]);
    }

    private function panelAccount(ServiceInstance $service, Server $server, string $name): PanelAccount
    {
        return PanelAccount::create([
            'service_instance_id' => $service->id,
            'server_id' => $server->id,
            'panel' => 'proxmox',
            'username' => 'pvevm01',
            'external_id' => '901',
            'meta' => ['node' => 'pve1', 'vmid' => 901, 'name' => $name, 'meta' => ['node' => 'pve1', 'vmid' => 901, 'name' => $name]],
            'status' => PanelAccount::STATUS_ACTIVE,
        ]);
    }

    /**
     * The rename round-trip: the node holds the VM (recorded node answers
     * first, so /nodes is never consulted), the config PUT is accepted, and
     * the read-back config reports $reportedName.
     */
    private function fakePveRename(string $reportedName): void
    {
        Cache::flush();
        Http::fake(function ($request) use ($reportedName) {
            $url = $request->url();

            if (str_contains($url, '/status/current')) {
                return Http::response(['data' => ['status' => 'stopped']]);
            }

            if (str_contains($url, '/qemu/901/config')) {
                if ($request->method() === 'GET') {
                    return Http::response(['data' => ['name' => $reportedName, 'scsi0' => 'local-lvm:32,size=32G']]);
                }

                return Http::response(['data' => null]);
            }

            return Http::response(['error' => 'unexpected host call'], 500);
        });
        Http::preventStrayRequests();
    }

    private function actingAsAdminWith(array $permissionNames): self
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $ids = [];
        foreach ($permissionNames as $name) {
            $ids[] = Permission::firstOrCreate(['name' => $name], ['label' => $name])->id;
        }
        $role->permissions()->sync($ids);
        $user->assignRole('admin');

        return $this->actingAs($user);
    }
}
