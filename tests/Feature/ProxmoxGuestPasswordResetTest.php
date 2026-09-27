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
use App\Services\Provisioning\VmStatusPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Proxmox VE guest password reset: agent-first with a cloud-init fallback,
 * mirroring the Hyper-V reset chain (running-VM guard, credential store
 * write-back, provisioning event + audit through the controllers).
 */
final class ProxmoxGuestPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────── setup ───────────────────────────────

    private function proxmoxServer(): Server
    {
        return Server::create([
            'name' => 'pve-reset',
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
            ],
        ]);
    }

    private function customer(): Customer
    {
        return Customer::create(['user_id' => User::factory()->create()->id, 'status' => 'active']);
    }

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

    private function serviceFor(Server $server, Customer $customer, string $tag): ServiceInstance
    {
        return ServiceInstance::create([
            'customer_id' => $customer->id,
            'order_id' => null,
            'server_id' => $server->id,
            'domain' => 'vm.test',
            'service_tag' => $tag,
            'username' => 'pvevm01',
            'provisioning_method' => 'proxmox',
            'status' => 'active',
        ]);
    }

    /**
     * @return array{0:HostingAccount,1:Customer,2:ServiceInstance,3:PanelAccount}
     */
    private function hostingWithProxmox(bool $withGuest = true, string $hostingStatus = 'active'): array
    {
        $server = $this->proxmoxServer();
        $product = Product::create(['name' => 'PVE', 'price' => 50]);
        ProductModule::create([
            'product_id' => $product->id,
            'module_slug' => 'proxmox',
            'enabled' => true,
            'config' => [],
        ]);
        $customer = $this->customer();
        $account = HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'server_id' => $server->id,
            'domain' => 'vm.test',
            'host_name' => 'pvevm01',
            'status' => $hostingStatus,
        ]);
        $service = $this->serviceFor($server, $customer, 'HOST-'.$account->id);

        $data = [
            'service_instance_id' => $service->id,
            'server_id' => $server->id,
            'panel' => 'proxmox',
            'username' => 'pvevm01',
            'external_id' => '901',
            'meta' => ['meta' => ['node' => 'pve1', 'vmid' => 901]],
            'status' => PanelAccount::STATUS_ACTIVE,
        ];

        if ($withGuest) {
            $data['guest_username'] = 'admin';
            $data['guest_password_encrypted'] = 'OldSecret123';
        }

        $panel = PanelAccount::create($data);

        return [$account->fresh(), $customer, $service, $panel];
    }

    /**
     * Fake the PVE surface a password reset touches: the status probe, the
     * guest-agent endpoint and the VM config (cloud-init or not).
     *
     * @param  array<string, mixed>  $config  GET /config payload
     */
    private function fakePveReset(string $status = 'running', array $config = [], int $agentStatus = 200): void
    {
        Http::preventStrayRequests();

        Http::fake([
            '*/api2/json/nodes/pve1/qemu/901/agent/set-user-password' => $agentStatus === 200
                ? Http::response(['data' => null])
                : Http::response(['message' => 'QEMU guest agent is not running'], 500),
            '*/api2/json/nodes/pve1/qemu/901/config' => Http::response(['data' => $config]),
            '*/api2/json/nodes/pve1/qemu/901/status/current' => Http::response(['data' => ['status' => $status, 'vmid' => 901]]),
            '*/api2/json/nodes' => Http::response(['data' => [['node' => 'pve1', 'status' => 'online']]]),
            '*/api2/json/nodes/*/tasks/*' => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]),
        ]);
    }

    private function assertAgentSent(string $username, string $password): void
    {
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
            && str_contains($r->url(), '/nodes/pve1/qemu/901/agent/set-user-password')
            && $r['username'] === $username
            && $r['password'] === $password
            && (int) $r['crypted'] === 0);
    }

    // ─────────────────────────── driver: agent ───────────────────────────

    public function test_reset_via_agent_stores_the_new_password(): void
    {
        [, , $service, $panel] = $this->hostingWithProxmox();
        $this->fakePveReset('running');

        $result = (new Proxmox)->resetGuestAdminPassword($service, 'NewSecret456');

        $this->assertTrue($result->success, (string) $result->message);
        $this->assertSame('admin', $result->data['username'] ?? null);
        $this->assertAgentSent('admin', 'NewSecret456');

        // New password persisted encrypted (decrypts back), never in meta.
        $fresh = $panel->fresh();
        $this->assertSame('NewSecret456', $fresh->guest_password_encrypted);
        $raw = DB::table('panel_accounts')->where('id', $fresh->id)->value('guest_password_encrypted');
        $this->assertNotSame('NewSecret456', $raw);
        $this->assertStringNotContainsString('NewSecret456', (string) json_encode($fresh->meta));

        // No cloud-init round-trip on the agent path.
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'PUT' && str_contains($r->url(), '/qemu/901/config'));
    }

    public function test_reset_defaults_to_root_and_honours_an_explicit_username(): void
    {
        $server = $this->proxmoxServer();
        $customer = $this->customer();
        $service = $this->serviceFor($server, $customer, 'PVE-RESET-1');
        $panel = PanelAccount::create([
            'service_instance_id' => $service->id,
            'server_id' => $server->id,
            'panel' => 'proxmox',
            'username' => 'pvevm01',
            'external_id' => '901',
            'meta' => ['meta' => ['node' => 'pve1']],
            'status' => PanelAccount::STATUS_ACTIVE,
        ]);
        $this->fakePveReset('running');

        // Nothing stored: the agent call goes out as root.
        $result = (new Proxmox)->resetGuestAdminPassword($service, 'RootSecret1');

        $this->assertTrue($result->success, (string) $result->message);
        $this->assertAgentSent('root', 'RootSecret1');
        $this->assertSame('root', $panel->fresh()->guest_username);

        // An explicit username wins over the stored one.
        $result = (new Proxmox)->resetGuestAdminPassword($service, 'DeploySecret2', 'deploy');

        $this->assertTrue($result->success, (string) $result->message);
        $this->assertAgentSent('deploy', 'DeploySecret2');
        $this->assertSame('deploy', $panel->fresh()->guest_username);
        $this->assertSame('DeploySecret2', $panel->fresh()->guest_password_encrypted);
    }

    // ─────────────────────── driver: cloud-init fallback ───────────────────────

    public function test_agent_failure_with_a_cloud_init_drive_falls_back_to_cloud_init(): void
    {
        [, , $service, $panel] = $this->hostingWithProxmox();
        $this->fakePveReset('running', [
            'citype' => 'nocloud',
            'ide2' => 'local-lvm:vm-901-cloudinit,media=cdrom',
            'scsi0' => 'local-lvm:vm-901-disk-0,size=50G',
        ], 500);

        $result = (new Proxmox)->resetGuestAdminPassword($service, 'CloudInitNew1');

        $this->assertTrue($result->success, (string) $result->message);
        $this->assertStringContainsString('next boot', strtolower((string) $result->message));
        // The agent was tried first.
        $this->assertAgentSent('admin', 'CloudInitNew1');
        // Then staged through cipassword.
        Http::assertSent(fn (Request $r): bool => $r->method() === 'PUT'
            && str_contains($r->url(), '/nodes/pve1/qemu/901/config')
            && str_contains((string) $r->body(), '"cipassword":"CloudInitNew1"'));

        $this->assertSame('CloudInitNew1', $panel->fresh()->guest_password_encrypted);
    }

    public function test_agent_failure_without_a_cloud_init_drive_fails_with_the_agent_error(): void
    {
        [, , $service, $panel] = $this->hostingWithProxmox();
        $this->fakePveReset('running', ['scsi0' => 'local-lvm:vm-901-disk-0,size=50G'], 500);

        $result = (new Proxmox)->resetGuestAdminPassword($service, 'Whatever123');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Proxmox VE password reset failed', (string) $result->message);
        $this->assertStringContainsString('guest agent', strtolower((string) $result->message));
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'PUT' && str_contains($r->url(), '/qemu/901/config'));

        // The stored credential is untouched.
        $this->assertSame('OldSecret123', $panel->fresh()->guest_password_encrypted);
    }

    public function test_agent_auth_failure_with_a_cloud_init_drive_still_fails_loudly(): void
    {
        // A 403 from the agent endpoint is an auth failure, not an
        // unavailable agent — it must fail with the agent error even though
        // the VM carries a cloud-init drive, never staging cipassword.
        [, , $service, $panel] = $this->hostingWithProxmox();

        Http::fake([
            '*/api2/json/nodes/pve1/qemu/901/agent/set-user-password' => Http::response(['message' => 'Permission check failed'], 403),
            '*/api2/json/nodes/pve1/qemu/901/config' => Http::response(['data' => [
                'citype' => 'nocloud',
                'ide2' => 'local-lvm:vm-901-cloudinit,media=cdrom',
                'scsi0' => 'local-lvm:vm-901-disk-0,size=50G',
            ]]),
            '*/api2/json/nodes/pve1/qemu/901/status/current' => Http::response(['data' => ['status' => 'running', 'vmid' => 901]]),
            '*/api2/json/nodes' => Http::response(['data' => [['node' => 'pve1', 'status' => 'online']]]),
            '*/api2/json/nodes/*/tasks/*' => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]),
        ]);
        Http::preventStrayRequests();

        $result = (new Proxmox)->resetGuestAdminPassword($service, 'Whatever123');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Proxmox VE password reset failed', (string) $result->message);
        $this->assertStringContainsString('denied', strtolower((string) $result->message));
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'PUT' && str_contains($r->url(), '/qemu/901/config'));

        // The stored credential is untouched.
        $this->assertSame('OldSecret123', $panel->fresh()->guest_password_encrypted);
    }

    // ─────────────────────────── driver: guards ───────────────────────────

    public function test_reset_refuses_a_stopped_vm_without_touching_the_agent(): void
    {
        [, , $service] = $this->hostingWithProxmox();
        $this->fakePveReset('stopped');

        $result = (new Proxmox)->resetGuestAdminPassword($service, 'NewSecret456');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Start the VM before resetting the password.', (string) $result->message);
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'set-user-password'));
    }

    public function test_reset_without_a_recorded_vm_fails_loudly(): void
    {
        $server = $this->proxmoxServer();
        $service = $this->serviceFor($server, $this->customer(), 'PVE-RESET-2');
        Http::fake();
        Http::preventStrayRequests();

        $result = (new Proxmox)->resetGuestAdminPassword($service, 'NewSecret456');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('No Proxmox VE VM is recorded', (string) $result->message);
        Http::assertNothingSent();
    }

    // ─────────────────────────── presenter ───────────────────────────

    public function test_presenter_allows_reset_for_a_running_proxmox_vm(): void
    {
        [$account] = $this->hostingWithProxmox();
        $this->fakePveReset('running');

        $status = app(VmStatusPresenter::class)->build($account->fresh(), true);

        $this->assertTrue($status['vm']['exists']);
        $this->assertSame('running', $status['vm']['state']);
        $this->assertTrue($status['can']['reset_password']);
        $this->assertArrayNotHasKey('reset_password', $status['reasons']);
    }

    public function test_presenter_denies_reset_for_a_stopped_proxmox_vm(): void
    {
        [$account] = $this->hostingWithProxmox();
        $this->fakePveReset('stopped');

        $status = app(VmStatusPresenter::class)->build($account->fresh(), true);

        $this->assertFalse($status['can']['reset_password']);
        $this->assertSame('Start the VM to reset the Administrator password.', $status['reasons']['reset_password']);
    }

    public function test_presenter_denies_reset_for_a_driver_without_the_reset_method(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*act=vs*' => Http::response(['123' => ['status' => 1]])]);

        $server = Server::create([
            'name' => 'vz-1',
            'ip_address' => '10.0.0.60',
            'server_type' => 'virtualizor',
            'api_username' => 'key-id',
            'api_key' => 'SECRET',
            'max_accounts' => 0,
            'status' => 'active',
        ]);
        $product = Product::create(['name' => 'VZ', 'price' => 50]);
        ProductModule::create([
            'product_id' => $product->id,
            'module_slug' => 'virtualizor',
            'enabled' => true,
            'config' => [],
        ]);
        $customer = $this->customer();
        $account = HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'server_id' => $server->id,
            'domain' => 'vm.test',
            'host_name' => 'vzvm01',
            'status' => 'active',
        ]);
        $service = ServiceInstance::create([
            'customer_id' => $customer->id,
            'order_id' => null,
            'server_id' => $server->id,
            'domain' => 'vm.test',
            'service_tag' => 'HOST-'.$account->id,
            'username' => 'vzvm01',
            'provisioning_method' => 'virtualizor',
            'status' => 'active',
        ]);
        PanelAccount::create([
            'service_instance_id' => $service->id,
            'server_id' => $server->id,
            'panel' => 'virtualizor',
            'username' => 'vzvm01',
            'external_id' => '123',
            'status' => PanelAccount::STATUS_ACTIVE,
        ]);

        $status = app(VmStatusPresenter::class)->build($account->fresh(), true);

        // Running on the host, but the driver has no reset flow — the frozen
        // reason is kept, not the running-VM grant.
        $this->assertSame('running', $status['vm']['state']);
        $this->assertFalse($status['can']['reset_password']);
        $this->assertSame('Password reset is not available for this service.', $status['reasons']['reset_password']);
    }

    // ─────────────────────────── admin endpoints ───────────────────────────

    public function test_admin_reset_proxmox_password_via_agent(): void
    {
        [$account, , $service] = $this->hostingWithProxmox();
        $this->fakePveReset('running');

        $response = $this->actingAs($this->adminWith(['hosting.edit']))
            ->postJson(route('admin.hosting.reset-vm-password', $account), [
                'password' => 'AdminNew456',
                'password_confirmation' => 'AdminNew456',
            ]);

        $response->assertOk()->assertJson(['ok' => true, 'username' => 'admin']);
        $this->assertSame('AdminNew456', $response->json('password'));
        $this->assertAgentSent('admin', 'AdminNew456');

        // Encrypted write-back, never in the event payload/result.
        $fresh = PanelAccount::where('service_instance_id', $service->id)->first();
        $this->assertSame('AdminNew456', $fresh->guest_password_encrypted);

        $event = ProvisioningEvent::where('hosting_account_id', $account->id)
            ->where('event_type', 'update')->orderByDesc('id')->first();
        $this->assertNotNull($event);
        $this->assertSame('completed', $event->status);
        $this->assertSame('proxmox', $event->payload['module'] ?? null);
        $this->assertStringNotContainsString('AdminNew456', (string) json_encode($event->payload));
        $this->assertStringNotContainsString('AdminNew456', (string) json_encode($event->result));
    }

    public function test_admin_reveal_returns_proxmox_credentials(): void
    {
        [$account] = $this->hostingWithProxmox();
        Http::fake();

        $this->actingAs($this->adminWith(['hosting.edit']))
            ->getJson(route('admin.hosting.vm-credentials', $account))
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'stored' => true,
                'username' => 'admin',
                'password' => 'OldSecret123',
            ]);
    }

    public function test_admin_reveal_without_stored_proxmox_credentials_is_422(): void
    {
        [$account] = $this->hostingWithProxmox(withGuest: false);
        Http::fake();

        $this->actingAs($this->adminWith(['hosting.edit']))
            ->getJson(route('admin.hosting.vm-credentials', $account))
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'stored' => false]);
    }

    // ─────────────────────────── client endpoint ───────────────────────────

    public function test_client_reset_proxmox_password_needs_no_stored_credentials_and_echoes_nothing(): void
    {
        [$account, $customer, $service] = $this->hostingWithProxmox(withGuest: false);
        $this->fakePveReset('running');

        $response = $this->actingAs($customer->user)
            ->postJson(route('client.hosting.reset-vm-password', $account), [
                'password' => 'ClientNew789',
                'password_confirmation' => 'ClientNew789',
            ]);

        $response->assertStatus(202)->assertJson(['ok' => true, 'started' => true, 'action' => 'reset_password']);
        $this->assertNotNull($response->json('event_id'));
        // The password the customer typed is never echoed back.
        $this->assertArrayNotHasKey('password', $response->json());
        $this->assertStringNotContainsString('ClientNew789', (string) $response->getContent());
        $this->assertAgentSent('root', 'ClientNew789');

        $fresh = PanelAccount::where('service_instance_id', $service->id)->first();
        $this->assertSame('ClientNew789', $fresh->guest_password_encrypted);

        $event = ProvisioningEvent::where('hosting_account_id', $account->id)
            ->where('event_type', 'update')->orderByDesc('id')->first();
        $this->assertNotNull($event);
        $this->assertSame('completed', $event->status);
    }
}
