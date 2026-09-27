<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RunUnrecordedVmDestroy;
use App\Jobs\RunVmOperation;
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
use App\Services\Provisioning\ProvisioningEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The remaining VM-mutating entry points on the queued operation model:
 * admin hosting lifecycle forms queue one operation per enabled module link
 * (local flip stays synchronous, flashes keep their keys), order-less
 * service actions queue through dispatchForService, and the unrecorded VM
 * destroy queues RunUnrecordedVmDestroy after its guards.
 */
final class VmOperationEntryPointsTest extends TestCase
{
    use RefreshDatabase;

    // ───────────────────────── admin lifecycle ─────────────────────────

    public function test_admin_suspend_queues_per_link_and_keeps_flash_keys(): void
    {
        Queue::fake();
        $account = $this->hostingWithLinks(['hyperv'], 'active');

        $this->actingAsAdminWith(['hosting.suspend'])
            ->from(route('admin.hosting.show', $account))
            ->post(route('admin.hosting.suspend', $account), ['reason' => 'non-payment'])
            ->assertRedirect()
            ->assertSessionHas('success')
            ->assertSessionHasNoErrors();

        $success = session('success');
        $this->assertStringContainsString('suspended', (string) $success);
        $this->assertStringContainsString('queued', (string) $success);
        $this->assertSame('suspended', $account->fresh()->status);

        $event = ProvisioningEvent::where('hosting_account_id', $account->id)->sole();
        $this->assertSame('running', $event->status);
        $this->assertSame('suspend', $event->event_type);
        $this->assertSame('hyperv', $event->payload['module']);
        $this->assertSame('suspend', $event->payload['action']);
        $this->assertSame('queued', $event->payload['stage']);

        Queue::assertPushedOn('provisioning', RunVmOperation::class, function (RunVmOperation $job) use ($account, $event): bool {
            return $job->eventId === $event->id
                && $job->hostingAccountId === $account->id
                && $job->serviceInstanceId === null
                && $job->verb === 'suspend';
        });
    }

    public function test_admin_unsuspend_queues_and_reactivates_locally(): void
    {
        Queue::fake();
        $account = $this->hostingWithLinks(['hyperv'], 'suspended');

        $this->actingAsAdminWith(['hosting.suspend'])
            ->from(route('admin.hosting.show', $account))
            ->post(route('admin.hosting.unsuspend', $account))
            ->assertRedirect()
            ->assertSessionHas('success');

        $success = session('success');
        $this->assertStringContainsString('reactivated', (string) $success);
        $this->assertStringContainsString('queued', (string) $success);
        $this->assertSame('active', $account->fresh()->status);

        $event = ProvisioningEvent::where('hosting_account_id', $account->id)->sole();
        $this->assertSame('unsuspend', $event->event_type);
        $this->assertSame('hyperv', $event->payload['module']);

        Queue::assertPushedOn('provisioning', RunVmOperation::class, function (RunVmOperation $job) use ($account): bool {
            return $job->hostingAccountId === $account->id && $job->verb === 'unsuspend';
        });
    }

    public function test_admin_destroy_queues_terminate_and_redirects_to_index(): void
    {
        Queue::fake();
        $account = $this->hostingWithLinks(['hyperv'], 'active');

        $this->actingAsAdminWith(['hosting.delete'])
            ->delete(route('admin.hosting.destroy', $account), ['reason' => 'abuse'])
            ->assertRedirect(route('admin.hosting.index'))
            ->assertSessionHas('success');

        $success = session('success');
        $this->assertStringContainsString('terminated', (string) $success);
        $this->assertStringContainsString('queued', (string) $success);
        $this->assertSame('terminated', $account->fresh()->status);

        $event = ProvisioningEvent::where('hosting_account_id', $account->id)->sole();
        $this->assertSame('terminate', $event->event_type);
        $this->assertSame('terminate', $event->payload['action']);

        Queue::assertPushedOn('provisioning', RunVmOperation::class, function (RunVmOperation $job) use ($account): bool {
            return $job->hostingAccountId === $account->id && $job->verb === 'terminate';
        });
    }

    public function test_admin_suspend_multi_module_queues_every_module(): void
    {
        Queue::fake();
        $account = $this->hostingWithLinks(['hyperv', 'proxmox'], 'active');

        $this->actingAsAdminWith(['hosting.suspend'])
            ->post(route('admin.hosting.suspend', $account))
            ->assertRedirect()
            ->assertSessionHas('success');

        // One queued operation per enabled link — the second link must not
        // trip the running-action guard on the first link's event.
        $modules = ProvisioningEvent::where('hosting_account_id', $account->id)
            ->orderBy('id')
            ->get()
            ->map(fn (ProvisioningEvent $event) => $event->payload['module'] ?? null)
            ->all();

        $this->assertSame(['hyperv', 'proxmox'], $modules);

        Queue::assertPushed(RunVmOperation::class, 2);
        Queue::assertPushedOn('provisioning', RunVmOperation::class, fn (): bool => true);
    }

    // ─────────────────────── order-less services ───────────────────────

    public function test_orderless_service_suspend_queues_through_the_dispatcher(): void
    {
        Queue::fake();
        $server = $this->hypervServer();
        $customer = $this->makeCustomer();
        $service = ServiceInstance::create([
            'customer_id' => $customer->id,
            'order_id' => null,
            'server_id' => $server->id,
            'domain' => 'vm.test',
            'service_tag' => 'SVC-ORDERLESS',
            'username' => 'testvm1',
            'provisioning_method' => 'hyperv',
            'status' => 'active',
        ]);
        PanelAccount::create([
            'service_instance_id' => $service->id,
            'server_id' => $server->id,
            'panel' => 'hyperv',
            'username' => 'testvm1',
            'external_id' => '11111111-2222-3333-4444-555555555555',
            'meta' => ['vmName' => 'testvm1'],
            'status' => PanelAccount::STATUS_ACTIVE,
        ]);

        $this->actingAsAdminWith(['service-instances.manage'])
            ->post(route('admin.service-instances.suspend', $service))
            ->assertRedirect(route('admin.service-instances.show', $service))
            ->assertSessionHas('success', 'Service suspended (module synced).');

        // Local-first flip stays synchronous; the dispatcher owns the event.
        $this->assertSame('suspended', $service->fresh()->status);

        $event = ProvisioningEvent::where('service_instance_id', $service->id)->sole();
        $this->assertSame('running', $event->status);
        $this->assertSame('suspend', $event->event_type);
        $this->assertSame('hyperv', $event->payload['module']);
        $this->assertNull($event->hosting_account_id);

        Queue::assertPushedOn('provisioning', RunVmOperation::class, function (RunVmOperation $job) use ($service, $event): bool {
            return $job->eventId === $event->id
                && $job->hostingAccountId === null
                && $job->serviceInstanceId === $service->id
                && $job->verb === 'suspend';
        });
    }

    public function test_orderless_service_with_host_mirror_links_the_event_to_the_account(): void
    {
        Queue::fake();
        $account = $this->hostingWithLinks(['hyperv'], 'active');
        $service = ServiceInstance::create([
            'customer_id' => $account->customer_id,
            'order_id' => null,
            'server_id' => $account->server_id,
            'domain' => 'vm.test',
            'service_tag' => 'HOST-'.$account->id,
            'username' => 'testvm1',
            'provisioning_method' => 'hyperv',
            'status' => 'active',
        ]);
        PanelAccount::create([
            'service_instance_id' => $service->id,
            'server_id' => $account->server_id,
            'panel' => 'hyperv',
            'username' => 'testvm1',
            'external_id' => '11111111-2222-3333-4444-555555555555',
            'meta' => ['vmName' => 'testvm1'],
            'status' => PanelAccount::STATUS_ACTIVE,
        ]);

        $this->actingAsAdminWith(['service-instances.manage'])
            ->post(route('admin.service-instances.suspend', $service))
            ->assertRedirect(route('admin.service-instances.show', $service))
            ->assertSessionHas('success');

        $event = ProvisioningEvent::where('service_instance_id', $service->id)->sole();
        $this->assertSame($account->id, $event->hosting_account_id);
        $this->assertSame('hyperv', $event->payload['module']);

        Queue::assertPushedOn('provisioning', RunVmOperation::class, function (RunVmOperation $job) use ($service): bool {
            return $job->serviceInstanceId === $service->id && $job->hostingAccountId === null;
        });
    }

    // ─────────────────────── unrecorded destroy ───────────────────────

    public function test_unrecorded_destroy_queues_the_job_and_event(): void
    {
        Queue::fake();
        $server = $this->proxmoxServer();
        $this->fakePveGuards();

        $this->actingAsAdminWith(['hosting.manage'])
            ->from(route('admin.servers.show', $server))
            ->post(route('admin.servers.vms.destroy', [$server, '105']), [
                'confirm' => '105',
                'node' => 'pve1',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Destroy queued.');

        // Queued, not executed: no host destroy yet.
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'DELETE');

        $event = ProvisioningEvent::sole();
        $this->assertSame('running', $event->status);
        $this->assertSame('terminate', $event->event_type);
        $this->assertSame('proxmox', $event->payload['module']);
        $this->assertSame('destroy_unrecorded', $event->payload['action']);
        $this->assertSame($server->id, $event->payload['server_id']);
        $this->assertSame(105, $event->payload['vmid']);
        $this->assertSame('pve1', $event->payload['node']);
        $this->assertNull($event->hosting_account_id);
        $this->assertNull($event->service_instance_id);

        Queue::assertPushedOn('provisioning', RunUnrecordedVmDestroy::class, function (RunUnrecordedVmDestroy $job) use ($server, $event): bool {
            return $job->eventId === $event->id
                && $job->serverId === $server->id
                && $job->vmid === 105
                && $job->node === 'pve1'
                && $job->tries === 1
                && $job->timeout === 600;
        });
    }

    public function test_unrecorded_destroy_job_destroys_and_completes_the_event(): void
    {
        $server = $this->proxmoxServer();

        Http::fake([
            '*/api2/json/nodes/pve1/qemu/105/status/current' => Http::response(['data' => ['status' => 'stopped']]),
            '*/api2/json/nodes/pve1/qemu/105?*' => Http::response(['data' => 'UPID:pve1:0000:destroy']),
            '*/api2/json/nodes/pve1/tasks/*' => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]),
        ]);
        Http::preventStrayRequests();

        $event = ProvisioningEvent::create([
            'service_instance_id' => null,
            'hosting_account_id' => null,
            'event_type' => 'terminate',
            'status' => 'running',
            'event_status' => 'running',
            'payload' => ['module' => 'proxmox', 'action' => 'destroy_unrecorded', 'server_id' => $server->id, 'vmid' => 105, 'node' => 'pve1'],
        ]);

        (new RunUnrecordedVmDestroy($event->id, $server->id, 105, 'pve1'))->handle(app(ProvisioningEventRecorder::class));

        $this->assertSame('completed', $event->fresh()->status);

        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE'
            && str_contains($r->url(), '/nodes/pve1/qemu/105')
            && str_contains($r->url(), 'purge=1'));
    }

    public function test_unrecorded_destroy_job_failed_callback_fails_a_running_event(): void
    {
        $server = $this->proxmoxServer();

        $running = ProvisioningEvent::create([
            'service_instance_id' => null,
            'hosting_account_id' => null,
            'event_type' => 'terminate',
            'status' => 'running',
            'event_status' => 'running',
            'payload' => ['module' => 'proxmox', 'action' => 'destroy_unrecorded'],
        ]);

        (new RunUnrecordedVmDestroy($running->id, $server->id, 105, 'pve1'))->failed(new \RuntimeException('boom'));

        $running = $running->fresh();
        $this->assertSame('failed', $running->status);
        $this->assertStringContainsString('boom', (string) $running->last_error);
    }

    public function test_unrecorded_destroy_guards_still_refuse(): void
    {
        Queue::fake();

        // Typed confirmation must match.
        $server = $this->proxmoxServer();
        Http::fake();
        Http::preventStrayRequests();

        $this->actingAsAdminWith(['hosting.manage'])
            ->from(route('admin.servers.show', $server))
            ->post(route('admin.servers.vms.destroy', [$server, '105']), [
                'confirm' => '106',
                'node' => 'pve1',
            ])
            ->assertSessionHas('error');

        // A VMID that belongs to a provisioned service must go through its own flow.
        $customer = $this->makeCustomer();
        $service = ServiceInstance::create([
            'customer_id' => $customer->id,
            'server_id' => $server->id,
            'service_tag' => 'SVC-105',
            'username' => 'acme',
            'domain' => 'acme.test',
            'provisioning_method' => 'proxmox',
            'status' => 'active',
        ]);
        PanelAccount::create([
            'service_instance_id' => $service->id,
            'server_id' => $server->id,
            'panel' => 'proxmox',
            'username' => 'acme',
            'external_id' => '105',
            'status' => PanelAccount::STATUS_ACTIVE,
        ]);

        $this->fakePveGuards();

        $this->actingAsAdminWith(['hosting.manage'])
            ->from(route('admin.servers.show', $server))
            ->post(route('admin.servers.vms.destroy', [$server, '105']), [
                'confirm' => '105',
                'node' => 'pve1',
            ])
            ->assertSessionHas('error');

        // Proxmox only.
        $hyperv = $this->hypervServer();

        $this->actingAsAdminWith(['hosting.manage'])
            ->from(route('admin.servers.show', $hyperv))
            ->post(route('admin.servers.vms.destroy', [$hyperv, '105']), [
                'confirm' => '105',
                'node' => 'pve1',
            ])
            ->assertSessionHas('error');

        Queue::assertNotPushed(RunUnrecordedVmDestroy::class);
        $this->assertSame(0, ProvisioningEvent::count());
    }

    // ───────────────────────────── helpers ─────────────────────────────

    private function hypervServer(): Server
    {
        return Server::create([
            'name' => 'hv-'.str()->lower(str()->random(6)),
            'ip_address' => '10.0.0.9',
            'server_type' => 'hyperv',
            'api_url' => 'http://10.0.0.9:5985',
            'api_username' => 'admin',
            'api_password_encrypted' => 'SECRET',
            'max_accounts' => 0,
            'status' => 'active',
        ]);
    }

    private function proxmoxServer(): Server
    {
        return Server::create([
            'name' => 'pve-'.str()->lower(str()->random(6)),
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

    /**
     * @param  list<string>  $slugs
     */
    private function hostingWithLinks(array $slugs, string $status): HostingAccount
    {
        $server = $this->hypervServer();
        $product = Product::create(['name' => 'VM '.uniqid(), 'price' => 50]);

        foreach ($slugs as $slug) {
            ProductModule::create([
                'product_id' => $product->id, 'module_slug' => $slug, 'enabled' => true, 'config' => [],
            ]);
        }

        return HostingAccount::create([
            'customer_id' => $this->makeCustomer()->id,
            'product_id' => $product->id,
            'server_id' => $server->id,
            'domain' => 'vm.test',
            'host_name' => 'hv-web-'.str()->lower(str()->random(6)),
            'status' => $status,
        ])->fresh();
    }

    /**
     * The read-only guards destroyVm runs before queueing: cluster nodes, the
     * existence probe (stopped, present) and a non-template config.
     */
    private function fakePveGuards(): void
    {
        Cache::flush();
        Http::fake([
            '*/api2/json/nodes' => Http::response(['data' => [['node' => 'pve1', 'status' => 'online']]]),
            '*/api2/json/nodes/pve1/qemu/105/status/current' => Http::response(['data' => ['status' => 'stopped']]),
            '*/api2/json/nodes/pve1/qemu/105/config' => Http::response(['data' => ['scsi0' => 'local-lvm:32,size=32G']]),
            '*/api2/json/nodes/pve1/tasks/*' => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]),
            '*/api2/json/nodes/pve1/qemu/105?*' => Http::response(['data' => 'UPID:pve1:0000:destroy']),
        ]);
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
