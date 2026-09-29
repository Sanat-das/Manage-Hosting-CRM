<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RunUnrecordedVmDestroy;
use App\Models\Customer;
use App\Models\PanelAccount;
use App\Models\Permission;
use App\Models\ProvisioningEvent;
use App\Models\Role;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\Provisioning\ProvisioningEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Destroying a VM that exists on the host but has no provisioned record.
 *
 * This closes the late-clone window: a clone that outlived its task timeout can
 * finish after the failure was reported, leaving an unaddressed VM and an
 * unusable VMID. The action must refuse anything that belongs to a service or
 * is a template, and must require a typed VMID confirmation.
 */
final class ProxmoxUnrecordedVmDestroyTest extends TestCase
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

    private function server(bool $connected = false): Server
    {
        return Server::create([
            'name' => 'pve-orphans',
            'ip_address' => '10.0.0.20',
            'server_type' => 'proxmox',
            'api_username' => 'root@pam!automation',
            'api_password_encrypted' => 'TOKEN-SECRET',
            'max_accounts' => 0,
            'status' => 'active',
            'connection_status' => $connected ? 'connected' : 'unknown',
            'connection_meta' => ['port' => 8006, 'auth_type' => 'token', 'verify_tls' => false],
        ]);
    }

    private function fakePveDestroy(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*/api2/json/nodes' => Http::response(['data' => [['node' => 'pve1', 'status' => 'online']]]),
            '*/api2/json/nodes/pve1/qemu/105/status/current' => Http::response(['data' => ['status' => 'stopped']]),
            '*/api2/json/nodes/pve1/qemu/105/config' => Http::response(['data' => ['scsi0' => 'local-lvm:32,size=32G']]),
            '*/api2/json/nodes/pve1/tasks/*' => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]),
            '*/api2/json/nodes/pve1/qemu/105?*' => Http::response(['data' => 'UPID:pve1:0000:destroy']),
        ]);
    }

    public function test_an_admin_can_destroy_an_unrecorded_vm(): void
    {
        $server = $this->server();
        $this->fakePveDestroy();

        $this->actingAs($this->adminWith(['hosting.manage']))
            ->from(route('admin.servers.show', $server))
            ->post(route('admin.servers.vms.destroy', [$server, '105']), [
                'confirm' => '105',
                'node' => 'pve1',
            ])
            ->assertRedirect(route('admin.servers.show', $server))
            ->assertSessionHas('success');

        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE'
            && str_contains($r->url(), '/nodes/pve1/qemu/105')
            && str_contains($r->url(), 'purge=1'));
    }

    public function test_a_queued_destroy_hands_off_so_the_guard_keeps_the_row_running(): void
    {
        Queue::fake();

        $server = $this->server();
        $this->fakePveDestroy();

        $this->actingAs($this->adminWith(['hosting.manage']))
            ->from(route('admin.servers.show', $server))
            ->post(route('admin.servers.vms.destroy', [$server, '105']), [
                'confirm' => '105',
                'node' => 'pve1',
            ])
            ->assertRedirect(route('admin.servers.show', $server))
            ->assertSessionHas('success');

        $event = ProvisioningEvent::sole();

        Queue::assertPushed(RunUnrecordedVmDestroy::class, fn (RunUnrecordedVmDestroy $job): bool => $job->eventId === $event->id);

        // Request teardown must not fail the queued destroy.
        ProvisioningEventRecorder::flushOpenEvents();

        $this->assertSame('running', $event->fresh()->status);
    }

    public function test_a_vm_that_belongs_to_a_service_is_refused(): void
    {
        $server = $this->server();
        $this->fakePveDestroy();

        $customer = Customer::create(['user_id' => User::factory()->create()->id, 'status' => 'active']);
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

        $this->actingAs($this->adminWith(['hosting.manage']))
            ->from(route('admin.servers.show', $server))
            ->post(route('admin.servers.vms.destroy', [$server, '105']), [
                'confirm' => '105',
                'node' => 'pve1',
            ])
            ->assertSessionHas('error');

        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'DELETE');
    }

    public function test_a_template_is_refused(): void
    {
        $server = $this->server();

        Http::preventStrayRequests();
        Http::fake([
            '*/api2/json/nodes' => Http::response(['data' => [['node' => 'pve1', 'status' => 'online']]]),
            '*/api2/json/nodes/pve1/qemu/105/status/current' => Http::response(['data' => ['status' => 'stopped']]),
            '*/api2/json/nodes/pve1/qemu/105/config' => Http::response(['data' => ['template' => 1, 'scsi0' => 'local-lvm:32,size=32G']]),
            '*/api2/json/nodes/pve1/tasks/*' => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]),
            '*/api2/json/nodes/pve1/qemu/105?*' => Http::response(['data' => 'UPID:pve1:0000:destroy']),
        ]);

        $this->actingAs($this->adminWith(['hosting.manage']))
            ->from(route('admin.servers.show', $server))
            ->post(route('admin.servers.vms.destroy', [$server, '105']), [
                'confirm' => '105',
                'node' => 'pve1',
            ])
            ->assertSessionHas('error');

        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'DELETE');
    }

    public function test_the_typed_confirmation_must_match(): void
    {
        $server = $this->server();

        Http::preventStrayRequests();
        Http::fake();

        $this->actingAs($this->adminWith(['hosting.manage']))
            ->from(route('admin.servers.show', $server))
            ->post(route('admin.servers.vms.destroy', [$server, '105']), [
                'confirm' => '106',
                'node' => 'pve1',
            ])
            ->assertSessionHas('error');

        Http::assertNothingSent();
    }

    public function test_the_permission_is_required(): void
    {
        $server = $this->server();

        Http::preventStrayRequests();
        Http::fake();

        $this->actingAs($this->adminWith(['hosting.view']))
            ->post(route('admin.servers.vms.destroy', [$server, '105']), [
                'confirm' => '105',
                'node' => 'pve1',
            ])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    /**
     * The table must offer the destroy form for an unrecorded VM, and must NOT
     * offer it for a template (the server refuses templates; showing a button
     * that always errors is worse than showing the badge).
     */
    public function test_the_server_page_offers_destroy_only_for_non_template_unrecorded_vms(): void
    {
        $server = $this->server(connected: true);

        Http::preventStrayRequests();
        Http::fake([
            '*/api2/json/version' => Http::response(['data' => ['version' => '9.1', 'release' => '9.1']]),
            '*/api2/json/nodes' => Http::response(['data' => [['node' => 'pve1', 'status' => 'online']]]),
            '*/api2/json/cluster/status' => Http::response(['data' => [['type' => 'cluster', 'name' => 'homelab']]]),
            '*/api2/json/cluster/resources*' => Http::response(['data' => [
                ['vmid' => 105, 'type' => 'qemu', 'name' => 'orphan', 'status' => 'stopped', 'node' => 'pve1', 'template' => 0],
                ['vmid' => 900, 'type' => 'qemu', 'name' => 'tpl', 'status' => 'stopped', 'node' => 'pve1', 'template' => 1],
            ]]),
        ]);

        $this->actingAs($this->adminWith(['hosting.view']))
            ->get(route('admin.servers.show', $server))
            ->assertOk()
            ->assertSee('On host, not provisioned', false)
            ->assertSee('vms/105/destroy', false)
            ->assertSee('>template</span>', false)
            ->assertDontSee('vms/900/destroy', false);
    }
}
