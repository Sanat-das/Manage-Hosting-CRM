<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\PanelAccount;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Moving a service between servers.
 *
 * `service_instances.server_id` was previously written only once, at creation, so
 * a service could not be moved off a server being decommissioned — and since the
 * server delete is blocked while services point at it, neither could the server.
 * These tests pin the move, its guards, and the fact that it unblocks the delete.
 */
final class ServiceMoveTest extends TestCase
{
    use RefreshDatabase;

    private function adminWith(array $perms, string $roleName = 'admin'): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => $roleName], ['label' => ucfirst($roleName)]);
        $ids = [];
        foreach ($perms as $name) {
            $ids[] = Permission::firstOrCreate(['name' => $name], ['label' => $name])->id;
        }
        $role->permissions()->sync($ids);
        $user->assignRole($roleName);

        return $user;
    }

    private function server(string $name, string $type = 'proxmox', string $status = 'active'): Server
    {
        return Server::create([
            'name' => $name,
            'ip_address' => '10.0.0.'.random_int(10, 250),
            'server_type' => $type,
            'max_accounts' => 0,
            'status' => $status,
        ]);
    }

    private function service(?Server $server, array $overrides = []): ServiceInstance
    {
        $customer = Customer::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'active',
        ]);

        return ServiceInstance::create(array_merge([
            'customer_id' => $customer->id,
            'server_id' => $server?->id,
            'service_tag' => 'SVC-'.random_int(100000, 999999),
            'username' => 'ord'.random_int(1, 999),
            'provisioning_method' => 'proxmox',
            'status' => 'pending',
            'provision_status' => 'pending',
        ], $overrides));
    }

    // ───────────────────────────── happy path ─────────────────────────────

    public function test_an_unprovisioned_service_is_moved_and_the_move_is_audited(): void
    {
        $from = $this->server('old-pve');
        $to = $this->server('new-pve');
        $service = $this->service($from);

        $this->actingAs($this->adminWith(['service-instances.manage']))
            ->put(route('admin.service-instances.move', $service), ['server_id' => $to->id])
            ->assertRedirect(route('admin.service-instances.show', $service))
            ->assertSessionHas('success');

        $this->assertSame($to->id, $service->fresh()->server_id);

        $audit = AuditLog::where('action', 'service.moved')->sole();
        $this->assertSame('service_instance', $audit->entity_type);
        $this->assertSame($service->id, $audit->entity_id);
        $this->assertNotNull($audit->created_at);

        $details = json_decode((string) $audit->details, true);
        $this->assertSame($from->id, $details['from_server_id']);
        $this->assertSame($to->id, $details['to_server_id']);
        $this->assertFalse($details['machine_acknowledged']);
    }

    /**
     * The whole point of the feature: once the last service leaves a server,
     * that server can actually be deleted.
     */
    public function test_moving_the_last_service_unblocks_deleting_the_old_server(): void
    {
        $old = $this->server('doomed-pve');
        $new = $this->server('replacement-pve');
        $service = $this->service($old);

        // Blocked while the service points at it.
        $this->actingAs($this->adminWith(['hosting.manage', 'service-instances.manage']))
            ->delete(route('admin.servers.destroy', $old))
            ->assertSessionHas('error');
        $this->assertDatabaseHas('servers', ['id' => $old->id]);

        // Move the service away, then the delete goes through.
        $this->actingAs($this->adminWith(['service-instances.manage']))
            ->put(route('admin.service-instances.move', $service), ['server_id' => $new->id])
            ->assertSessionHas('success');

        $this->actingAs($this->adminWith(['hosting.manage']))
            ->delete(route('admin.servers.destroy', $old))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('servers', ['id' => $old->id]);
    }

    // ─────────────────────────────── guards ───────────────────────────────

    /**
     * A server the driver can actually probe: configured credentials so the
     * module will dial it, with Http::fake() deciding what the host reports.
     */
    private function probeableServer(string $name): Server
    {
        $server = $this->server($name);
        $server->update([
            'api_username' => 'root@pam!probe',
            'api_password_encrypted' => 'TOKEN-SECRET',
            'connection_meta' => ['port' => 8006, 'auth_type' => 'token', 'verify_tls' => false],
        ]);

        return $server->fresh();
    }

    private function machineBackedService(Server $server, array $overrides = []): ServiceInstance
    {
        $service = $this->service($server, $overrides);

        PanelAccount::create([
            'service_instance_id' => $service->id,
            'server_id' => $server->id,
            'panel' => 'proxmox',
            'username' => 'acme',
            'external_id' => '901',
            'status' => PanelAccount::STATUS_ACTIVE,
            'meta' => ['meta' => ['node' => 'probe-node']],
        ]);

        return $service;
    }

    public function test_a_verified_present_machine_requires_the_explicit_confirmation(): void
    {
        $from = $this->probeableServer('old-pve');
        $to = $this->server('new-pve');
        $service = $this->machineBackedService($from, ['status' => 'active']);

        Http::fake([
            '*/api2/json/nodes/probe-node/qemu/901/status/current' => Http::response(['data' => ['status' => 'running']]),
        ]);

        $response = $this->actingAs($this->adminWith(['service-instances.manage']))
            ->put(route('admin.service-instances.move', $service), ['server_id' => $to->id]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('already has a machine', (string) session('error'));
        $this->assertSame($from->id, $service->fresh()->server_id);
    }

    /**
     * A stale record — the VM was deleted out-of-band — must NOT demand the
     * confirmation tick, or operators learn to tick it blindly.
     */
    public function test_a_verified_absent_machine_moves_without_the_confirmation_and_says_so(): void
    {
        $from = $this->probeableServer('old-pve');
        $to = $this->server('new-pve');
        $service = $this->machineBackedService($from, ['status' => 'active']);

        Http::fake([
            '*/api2/json/nodes/probe-node/qemu/901/status/current' => Http::response([
                'message' => "Configuration file 'nodes/probe-node/qemu-server/901.conf' does not exist",
            ], 500),
        ]);

        $this->actingAs($this->adminWith(['service-instances.manage']))
            ->put(route('admin.service-instances.move', $service), ['server_id' => $to->id])
            ->assertSessionHas('success');

        $this->assertSame($to->id, $service->fresh()->server_id);

        $details = json_decode((string) AuditLog::where('action', 'service.moved')->sole()->details, true);
        $this->assertSame('absent', $details['machine_state']);
        $this->assertFalse($details['machine_acknowledged']);
    }

    /**
     * An unreachable host is "unknown", never "gone": the confirmation still
     * applies, because guessing would re-point a live service.
     */
    public function test_an_unverifiable_machine_still_requires_the_confirmation(): void
    {
        $from = $this->probeableServer('old-pve');
        $to = $this->server('new-pve');
        $service = $this->machineBackedService($from, ['status' => 'active']);

        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('host unreachable'));

        $this->actingAs($this->adminWith(['service-instances.manage']))
            ->put(route('admin.service-instances.move', $service), ['server_id' => $to->id])
            ->assertSessionHas('error');

        $this->assertSame($from->id, $service->fresh()->server_id);

        // With the acknowledgement it goes through, and the audit records the doubt.
        $this->actingAs($this->adminWith(['service-instances.manage']))
            ->put(route('admin.service-instances.move', $service), [
                'server_id' => $to->id,
                'confirm_machine_handled' => '1',
            ])
            ->assertSessionHas('success');

        $details = json_decode((string) AuditLog::where('action', 'service.moved')->sole()->details, true);
        $this->assertSame('unknown', $details['machine_state']);
    }

    /**
     * No panel account and no probing hook: the service record is all there is,
     * so the old conservative behaviour is kept.
     */
    public function test_a_machine_that_cannot_be_probed_at_all_requires_the_confirmation(): void
    {
        $from = $this->server('old-pve');
        $to = $this->server('new-pve');
        // external_id only — no panel account to probe.
        $service = $this->service($from, ['external_id' => '901', 'status' => 'active']);

        $this->actingAs($this->adminWith(['service-instances.manage']))
            ->put(route('admin.service-instances.move', $service), ['server_id' => $to->id])
            ->assertSessionHas('error');

        $this->assertSame($from->id, $service->fresh()->server_id);
    }

    public function test_a_confirmed_move_of_a_present_machine_is_flagged_in_the_audit(): void
    {
        $from = $this->probeableServer('old-pve');
        $to = $this->server('new-pve');
        $service = $this->machineBackedService($from, ['status' => 'active']);

        Http::fake([
            '*/api2/json/nodes/probe-node/qemu/901/status/current' => Http::response(['data' => ['status' => 'running']]),
        ]);

        $this->actingAs($this->adminWith(['service-instances.manage']))
            ->put(route('admin.service-instances.move', $service), [
                'server_id' => $to->id,
                'confirm_machine_handled' => '1',
            ])
            ->assertSessionHas('success');

        $this->assertSame($to->id, $service->fresh()->server_id);

        $details = json_decode((string) AuditLog::where('action', 'service.moved')->sole()->details, true);
        $this->assertSame('present', $details['machine_state']);
        $this->assertTrue($details['machine_acknowledged']);
    }

    public function test_a_service_cannot_be_moved_to_the_server_it_is_already_on(): void
    {
        $server = $this->server('pve-1');
        $service = $this->service($server);

        $this->actingAs($this->adminWith(['service-instances.manage']))
            ->put(route('admin.service-instances.move', $service), ['server_id' => $server->id])
            ->assertSessionHas('error');

        $this->assertSame($server->id, $service->fresh()->server_id);
        $this->assertSame(0, AuditLog::where('action', 'service.moved')->count());
    }

    public function test_a_proxmox_service_cannot_be_moved_onto_a_hyperv_server(): void
    {
        $from = $this->server('old-pve');
        $hyperv = $this->server('hv-1', 'hyperv');
        $service = $this->service($from);

        $response = $this->actingAs($this->adminWith(['service-instances.manage']))
            ->put(route('admin.service-instances.move', $service), ['server_id' => $hyperv->id]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('cannot live on a hyperv server', (string) session('error'));
        $this->assertSame($from->id, $service->fresh()->server_id);
    }

    public function test_a_service_cannot_be_moved_onto_an_inactive_server(): void
    {
        $from = $this->server('old-pve');
        $inactive = $this->server('idle-pve', 'proxmox', 'inactive');
        $service = $this->service($from);

        $response = $this->actingAs($this->adminWith(['service-instances.manage']))
            ->put(route('admin.service-instances.move', $service), ['server_id' => $inactive->id]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('is not active', (string) session('error'));
        $this->assertSame($from->id, $service->fresh()->server_id);
    }

    public function test_a_terminated_service_cannot_be_moved(): void
    {
        $from = $this->server('old-pve');
        $to = $this->server('new-pve');
        $service = $this->service($from, ['status' => 'terminated']);

        $this->actingAs($this->adminWith(['service-instances.manage']))
            ->put(route('admin.service-instances.move', $service), ['server_id' => $to->id])
            ->assertSessionHas('error');

        $this->assertSame($from->id, $service->fresh()->server_id);
    }

    public function test_an_unknown_target_server_is_rejected(): void
    {
        $from = $this->server('old-pve');
        $service = $this->service($from);

        $this->actingAs($this->adminWith(['service-instances.manage']))
            ->put(route('admin.service-instances.move', $service), ['server_id' => 99999])
            ->assertSessionHasErrors('server_id');
    }

    // ───────────────────────────── permission / UI ─────────────────────────────

    public function test_a_user_without_the_manage_permission_cannot_move(): void
    {
        $from = $this->server('old-pve');
        $to = $this->server('new-pve');
        $service = $this->service($from);

        $this->actingAs($this->adminWith(['service-instances.view'], 'staff'))
            ->put(route('admin.service-instances.move', $service), ['server_id' => $to->id])
            ->assertForbidden();

        $this->assertSame($from->id, $service->fresh()->server_id);
    }

    public function test_the_service_page_offers_the_move_and_lists_same_type_targets_only(): void
    {
        $from = $this->server('old-pve');
        $proxmox = $this->server('new-pve');
        $this->server('hv-1', 'hyperv');
        $service = $this->service($from);

        $response = $this->actingAs($this->adminWith(['service-instances.view']))
            ->get(route('admin.service-instances.show', $service))
            ->assertOk();

        $response->assertSee('Move to another server', false);
        $response->assertSee('new-pve', false);
        // The current server is not a target, and neither is a different type.
        $response->assertDontSee('old-pve (proxmox)', false);
        $response->assertDontSee('hv-1', false);
    }

    public function test_the_service_page_warns_and_asks_for_confirmation_when_a_machine_is_present(): void
    {
        $from = $this->probeableServer('old-pve');
        $this->server('new-pve');
        $service = $this->machineBackedService($from, ['status' => 'active']);

        Http::fake([
            '*/api2/json/nodes/probe-node/qemu/901/status/current' => Http::response(['data' => ['status' => 'running']]),
        ]);

        $this->actingAs($this->adminWith(['service-instances.view']))
            ->get(route('admin.service-instances.show', $service))
            ->assertOk()
            ->assertSee('confirm_machine_handled', false)
            ->assertSee('already has a machine', false);
    }

    public function test_the_service_page_reports_a_verified_absent_machine_as_free_to_move(): void
    {
        $from = $this->probeableServer('old-pve');
        $this->server('new-pve');
        $service = $this->machineBackedService($from, ['status' => 'active']);

        Http::fake([
            '*/api2/json/nodes/probe-node/qemu/901/status/current' => Http::response([
                'message' => 'Configuration file does not exist',
            ], 500),
        ]);

        $this->actingAs($this->adminWith(['service-instances.view']))
            ->get(route('admin.service-instances.show', $service))
            ->assertOk()
            ->assertSee('can be moved freely', false)
            ->assertDontSee('confirm_machine_handled', false);
    }

    public function test_the_move_card_says_so_when_no_target_is_available(): void
    {
        $from = $this->server('only-pve');
        $service = $this->service($from);

        $this->actingAs($this->adminWith(['service-instances.view']))
            ->get(route('admin.service-instances.show', $service))
            ->assertOk()
            ->assertSee('No other active proxmox server is available', false);
    }
}
