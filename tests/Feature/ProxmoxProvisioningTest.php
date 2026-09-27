<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\Integrations\PanelException;
use App\Models\Customer;
use App\Models\PanelAccount;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Modules\Proxmox\Proxmox;
use App\Modules\Proxmox\Services\ProxmoxClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Proxmox VE on top of AbstractComputeModule.
 *
 * The PVE API has three traits the fakes below reproduce deliberately, because
 * each one is a way to get this badly wrong:
 *
 *  - every payload is wrapped in `{"data": …}`, and errors arrive as a
 *    different envelope (`errors`/`message`) rather than an HTTP status alone;
 *  - mutating calls answer with a UPID and finish later, so the driver must poll
 *    the task before believing anything happened;
 *  - a VMID that already exists is refused rather than reused.
 *
 * NB: written against the documented PVE 7/8 API shape and verified against
 * faked responses — it has not been run against a live PVE node.
 */
final class ProxmoxProvisioningTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────── setup ───────────────────────────────

    private function server(string $authType = 'token'): Server
    {
        return Server::create([
            'name' => 'pve-1',
            'ip_address' => '10.0.0.20',
            'server_type' => 'proxmox',
            'api_username' => 'root@pam!automation',
            'api_password_encrypted' => 'TOKEN-SECRET',
            'max_accounts' => 0,
            'status' => 'active',
            'connection_meta' => [
                'port' => 8006,
                'auth_type' => $authType,
                'verify_tls' => false,
                'ticket_username' => 'root@pam',
            ],
        ]);
    }

    private function service(Server $server, string $domain = 'acme.test'): ServiceInstance
    {
        $customer = Customer::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'active',
        ]);

        return ServiceInstance::create([
            'customer_id' => $customer->id,
            'server_id' => $server->id,
            'service_tag' => 'PVE-'.random_int(100000, 999999),
            'username' => 'acme',
            'domain' => $domain,
            'provisioning_method' => 'proxmox',
            'status' => 'active',
        ]);
    }

    /**
     * Fakes the whole PVE surface a token-auth provision touches.
     *
     * Order matters: Http::fake() matches the FIRST pattern that fits, so the
     * specific per-VMID routes must precede the catch-all ones.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function fakePve(array $overrides = []): void
    {
        // A stray request would otherwise reach a REAL Proxmox host if one were
        // ever configured in the environment. Fail loudly on unfaked URLs instead.
        Http::preventStrayRequests();

        // Overrides go FIRST: Http::fake() matches the first fitting pattern, so
        // a caller's specific response must outrank the catch-alls below.
        Http::fake(array_merge($overrides, [
            '*/api2/json/version' => Http::response(['data' => ['version' => '8.2.2', 'release' => '8.2', 'repoid' => 'abc']]),
            '*/api2/json/access/permissions' => Http::response(['data' => [
                '/vms' => ['VM.Allocate' => 1, 'VM.Audit' => 1, 'VM.Clone' => 1],
                '/storage' => ['Datastore.AllocateSpace' => 1, 'Datastore.Audit' => 1],
            ]]),
            // Real PVE semantics: `?vmid=N` VALIDATES N (400 when taken) and the
            // bare endpoint answers with the cluster's next free id. Modelling
            // these as one "search from N" call is what let a live bug through.
            '*/api2/json/cluster/nextid?vmid=*' => function (Request $request) {
                $vmid = (int) ($request->data()['vmid'] ?? 0);

                if (in_array($vmid, [900, 901, 902, 101], true)) {
                    return Http::response([
                        'data' => null,
                        'errors' => ['vmid' => 'VM '.$vmid.' already exists'],
                        'message' => "Parameter verification failed.\n",
                    ], 400);
                }

                return Http::response(['data' => (string) $vmid]);
            },
            '*/api2/json/cluster/nextid' => Http::response(['data' => '901']),
            '*/api2/json/nodes/pve1/tasks/*' => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]),
            '*/api2/json/nodes/pve-live/tasks/*' => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]),
            '*/api2/json/nodes/pve2/tasks/*' => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]),
            // Action routes BEFORE the per-VMID status wildcard below, which
            // would otherwise swallow /resize, /clone and the power actions.
            '*qemu/*/clone' => Http::response(['data' => 'UPID:pve1:0000:clone']),
            '*qemu/*/resize' => Http::response(['data' => 'UPID:pve1:0000:resize']),
            '*qemu/*/config' => Http::response(['data' => ['scsi0' => 'local-lvm:50,size=50G']]),
            '*qemu/*/status/start' => Http::response(['data' => 'UPID:pve1:0000:start']),
            '*qemu/*/status/shutdown' => Http::response(['data' => 'UPID:pve1:0000:shutdown']),
            '*qemu/*/status/stop' => Http::response(['data' => 'UPID:pve1:0000:stop']),
            '*/api2/json/nodes/pve1/qemu/901/status/current' => Http::response(['data' => ['status' => 'running', 'vmid' => 901]]),
            '*/api2/json/nodes/pve1/qemu/502/status/current' => Http::response(['data' => ['status' => 'running', 'vmid' => 502]]),
            '*/api2/json/nodes/pve1/qemu/500/status/current' => Http::response(['data' => ['status' => 'running', 'vmid' => 500]]),
            '*/api2/json/nodes/pve-live/qemu/*' => Http::response(['data' => ['status' => 'running']]),
            '*/api2/json/nodes' => Http::response(['data' => [
                ['node' => 'pve1', 'status' => 'online'],
                ['node' => 'pve2', 'status' => 'online'],
            ]]),
            '*/api2/json/cluster/status' => Http::response(['data' => [['type' => 'cluster', 'name' => 'homelab']]]),
            '*/api2/json/cluster/resources*' => Http::response(['data' => [
                ['vmid' => 900, 'type' => 'qemu', 'name' => 'template-ubuntu', 'status' => 'stopped', 'node' => 'pve1', 'template' => 1],
            ]]),
            '*/api2/json/nodes/pve1/storage' => Http::response(['data' => [
                // A realistic mix: a disabled pool with no space, a backup-only
                // pool, and two usable pools. The old "first disk-capable entry"
                // logic could return `local` here.
                ['storage' => 'local', 'type' => 'dir', 'active' => 0, 'avail' => 0, 'content' => 'rootdir,iso,images,backup'],
                ['storage' => 'pvstorage', 'type' => 'pbs', 'active' => 1, 'avail' => 900000000000, 'content' => 'backup'],
                ['storage' => 'local-zfs', 'type' => 'zfspool', 'active' => 1, 'avail' => 40000000000, 'content' => 'rootdir,images'],
                ['storage' => 'ceph_storage', 'type' => 'rbd', 'active' => 1, 'avail' => 3000000000000, 'content' => 'rootdir,images'],
            ]]),
            '*/api2/json/nodes/pve1/qemu' => Http::response(['data' => null]),
            '*/api2/json/nodes/*/tasks/*' => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]),
            '*qemu/*/status/current' => Http::response(['data' => ['status' => 'running']]),
            '*qemu/*' => Http::response(['data' => 'UPID:pve1:0000:destroy']),
        ], $overrides));
    }

    // ───────────────────────── auth: token mode ─────────────────────────

    public function test_token_auth_sends_the_pveapitoken_header(): void
    {
        $client = new ProxmoxClient($this->server('token'));

        Http::fake(['*/api2/json/version' => Http::response(['data' => ['version' => '8.2.2', 'release' => '8.2']])]);

        $client->version();

        Http::assertSent(fn (Request $r): bool => $r->hasHeader('Authorization', 'PVEAPIToken=root@pam!automation=TOKEN-SECRET'));
    }

    public function test_token_auth_never_requests_a_ticket(): void
    {
        $client = new ProxmoxClient($this->server('token'));

        Http::fake(['*' => Http::response(['data' => []])]);

        try {
            $client->nodes();
        } catch (\Throwable) {
            // The shape of this fake is irrelevant; only the URL matters.
        }

        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/access/ticket'));
    }

    // ───────────────────────── auth: ticket mode ─────────────────────────

    public function test_ticket_auth_authenticates_then_reuses_the_cached_ticket(): void
    {
        $client = new ProxmoxClient($this->server('ticket'));

        Http::fake([
            '*/api2/json/access/ticket' => Http::response(['data' => [
                'ticket' => 'PVE:root@pam:TICKET',
                'CSRFPreventionToken' => 'CSRF-TOKEN',
            ]]),
            '*/api2/json/nodes' => Http::response(['data' => [['node' => 'pve1', 'status' => 'online']]]),
        ]);

        $client->nodes();
        $client->nodes();

        // One ticket for two calls — the whole point of the cache.
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/access/ticket')
            && $r['username'] === 'root@pam'
            && $r['password'] === 'TOKEN-SECRET');
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/nodes')
            && $r->hasHeader('CSRFPreventionToken', 'CSRF-TOKEN'));
    }

    public function test_ticket_auth_retries_once_after_a_401(): void
    {
        $client = new ProxmoxClient($this->server('ticket'));
        $calls = 0;

        Http::fake([
            '*/api2/json/access/ticket' => Http::response(['data' => [
                'ticket' => 'PVE:root@pam:TICKET',
                'CSRFPreventionToken' => 'CSRF-TOKEN',
            ]]),
            '*/api2/json/nodes' => function () use (&$calls) {
                $calls++;

                // First attempt (stale cached ticket) is rejected; the retry wins.
                return $calls === 1
                    ? Http::response(['message' => 'authentication failure'], 401)
                    : Http::response(['data' => [['node' => 'pve1', 'status' => 'online']]]);
            },
        ]);

        $nodes = $client->nodes();

        $this->assertSame(['pve1'], $nodes);
        // Two ticket fetches: the initial one and the one after the 401.
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/access/ticket'));
    }

    public function test_ticket_auth_failure_is_reported_clearly(): void
    {
        $client = new ProxmoxClient($this->server('ticket'));

        Http::fake([
            '*/api2/json/access/ticket' => Http::response(['message' => 'authentication failure'], 401),
        ]);

        $this->expectException(PanelException::class);
        $this->expectExceptionMessageMatches('/ticket credentials/');

        $client->nodes();
    }

    public function test_ticket_auth_falls_back_to_the_token_id_column_for_the_username(): void
    {
        // A near-miss save: token-id field filled, dedicated ticket field blank.
        $server = Server::create([
            'name' => 'pve-near-miss',
            'ip_address' => '10.100.1.30',
            'server_type' => 'proxmox',
            'api_username' => 'root@pam',
            'api_password_encrypted' => 'ROOT-PASSWORD',
            'max_accounts' => 0,
            'status' => 'active',
            'connection_meta' => ['port' => 8006, 'auth_type' => 'ticket', 'verify_tls' => false],
        ]);

        $this->assertSame('root@pam', ProxmoxClient::ticketUsername($server));
        $this->assertTrue(ProxmoxClient::isConfigured($server));

        Http::fake([
            '*/api2/json/access/ticket' => Http::response(['data' => [
                'ticket' => 'PVE:root@pam:TICKET',
                'CSRFPreventionToken' => 'CSRF-TOKEN',
            ]]),
            '*/api2/json/nodes' => Http::response(['data' => [['node' => 'pve1', 'status' => 'online']]]),
        ]);

        // Authenticates with the fallback username rather than failing on a blank one.
        $this->assertSame(['pve1'], (new ProxmoxClient($server))->nodes());
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/access/ticket') && $r['username'] === 'root@pam');
    }

    public function test_a_missing_ticket_username_is_rejected_before_any_call(): void
    {
        $server = $this->server('ticket');
        // Neither the ticket field NOR the token-id fallback carries a username.
        $server->update([
            'api_username' => null,
            'connection_meta' => ['port' => 8006, 'auth_type' => 'ticket', 'verify_tls' => false],
        ]);

        Http::fake();

        $client = new ProxmoxClient($server->fresh());

        $this->expectException(PanelException::class);
        $this->expectExceptionMessageMatches('/username.*password/s');

        $client->nodes();
    }

    // ──────────────────────── configuration gate ────────────────────────

    /**
     * The address field is free text: a full URL, a bare host, or host:port must
     * all resolve to the same endpoint. Using it verbatim produced
     * `https://10.0.0.5:8006:8006/...` and "Invalid host" on a live server.
     */
    public function test_a_bare_host_and_port_address_is_parsed_correctly(): void
    {
        $server = Server::create([
            'name' => 'pve-bare',
            'ip_address' => '10.0.0.50',
            'api_url' => '10.0.0.50:8006',
            'server_type' => 'proxmox',
            'api_username' => 'root@pam!automation',
            'api_password_encrypted' => 'TOKEN-SECRET',
            'max_accounts' => 0,
            'status' => 'active',
            'connection_meta' => ['auth_type' => 'token', 'verify_tls' => false],
        ]);

        $client = new ProxmoxClient($server);

        $this->assertSame('10.0.0.50', $client->hostLabel());
        $this->assertTrue(ProxmoxClient::isConfigured($server));

        Http::preventStrayRequests();
        Http::fake(['*/api2/json/version' => Http::response(['data' => ['version' => '9.1', 'release' => '9.1']])]);

        $client->version();

        Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), 'https://10.0.0.50:8006/api2json/version')
            || str_starts_with($r->url(), 'https://10.0.0.50:8006/api2/json/version'));
    }

    public function test_a_full_url_address_is_parsed_and_its_port_honoured(): void
    {
        $server = Server::create([
            'name' => 'pve-url',
            'ip_address' => '10.0.0.51',
            'api_url' => 'https://pve.example.com:8443/',
            'server_type' => 'proxmox',
            'api_username' => 'root@pam!automation',
            'api_password_encrypted' => 'TOKEN-SECRET',
            'max_accounts' => 0,
            'status' => 'active',
            // No port in meta, so the address's own port must be used.
            'connection_meta' => ['auth_type' => 'token', 'verify_tls' => false],
        ]);

        $client = new ProxmoxClient($server);

        $this->assertSame('pve.example.com:8443', $client->hostLabel());

        Http::preventStrayRequests();
        Http::fake(['*/api2/json/version' => Http::response(['data' => ['version' => '9.1', 'release' => '9.1']])]);

        $client->version();

        Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), 'https://pve.example.com:8443/api2'));
    }

    public function test_meta_port_wins_over_the_address_port(): void
    {
        $server = Server::create([
            'name' => 'pve-both',
            'ip_address' => '10.0.0.52',
            'api_url' => '10.0.0.52:9999',
            'server_type' => 'proxmox',
            'api_username' => 'root@pam!automation',
            'api_password_encrypted' => 'TOKEN-SECRET',
            'max_accounts' => 0,
            'status' => 'active',
            'connection_meta' => ['auth_type' => 'token', 'port' => 8006, 'verify_tls' => false],
        ]);

        // The explicit transport setting is authoritative.
        $this->assertSame('10.0.0.52', (new ProxmoxClient($server))->hostLabel());
    }

    /**
     * A cluster with a node offline must not be reported as having no storage:
     * the probe has to run against a REACHABLE node. PVE lists nodes in an
     * arbitrary order, so the first entry can be the dead one.
     */
    public function test_test_connection_probes_an_online_node_for_storage(): void
    {
        $client = new ProxmoxClient($this->server());

        Http::fake([
            '*/api2/json/version' => Http::response(['data' => ['version' => '9.1', 'release' => '9.1']]),
            // The offline node is listed FIRST.
            '*/api2/json/nodes' => Http::response(['data' => [
                ['node' => 'pve-dead', 'status' => 'offline'],
                ['node' => 'pve-live', 'status' => 'online'],
            ]]),
            '*/api2/json/access/permissions' => Http::response(['data' => ['/vms' => ['VM.Audit' => 1]]]),
            '*/api2/json/cluster/resources*' => Http::response(['data' => [['vmid' => 101, 'type' => 'qemu', 'status' => 'running']]]),
            // The dead node cannot answer for storage.
            '*/api2/json/nodes/pve-dead/storage' => Http::response('', 595),
            '*/api2/json/nodes/pve-live/storage' => Http::response(['data' => [
                ['storage' => 'local-zfs', 'active' => 1, 'avail' => 40000000000, 'content' => 'images'],
            ]]),
        ]);

        $result = $client->testConnection();

        $this->assertTrue($result->ok, $result->message);
        $this->assertSame('pve-live', $result->meta['node']);
        $this->assertSame(1, $result->meta['datastores_visible']);
        $this->assertStringNotContainsString('no datastores are visible', $result->message);
    }

    public function test_a_half_configured_token_server_is_not_reported_as_ready(): void
    {
        $server = Server::create([
            'name' => 'pve-half',
            'ip_address' => '10.0.0.30',
            'server_type' => 'proxmox',
            'api_username' => 'root@pam!automation',
            // secret missing
            'max_accounts' => 0,
            'status' => 'active',
            'connection_meta' => ['auth_type' => 'token'],
        ]);

        $this->assertFalse(ProxmoxClient::isConfigured($server));
    }

    public function test_a_fully_configured_server_is_ready(): void
    {
        $this->assertTrue(ProxmoxClient::isConfigured($this->server('token')));
        $this->assertTrue(ProxmoxClient::isConfigured($this->server('ticket')));
    }

    public function test_next_vmid_uses_pves_suggestion_when_it_is_at_or_above_the_floor(): void
    {
        $client = new ProxmoxClient($this->server());

        Http::fake(['*/api2/json/cluster/nextid' => Http::response(['data' => '112'])]);

        $this->assertSame(112, $client->nextVmId(100));
        // The bare endpoint is the allocator; the floor needs no extra probe.
        Http::assertSentCount(1);
    }

    public function test_next_vmid_probes_upward_when_pves_suggestion_is_below_the_floor(): void
    {
        $client = new ProxmoxClient($this->server());

        Http::fake([
            '*/api2/json/cluster/nextid?vmid=*' => function (Request $request) {
                $vmid = (int) ($request->data()['vmid'] ?? 0);

                // 200 and 201 are taken; 202 is the first free id at/above the floor.
                return in_array($vmid, [200, 201], true)
                    ? Http::response(['data' => null, 'errors' => ['vmid' => 'VM '.$vmid.' already exists']], 400)
                    : Http::response(['data' => (string) $vmid]);
            },
            '*/api2/json/cluster/nextid' => Http::response(['data' => '112']),
        ]);

        // PVE suggests 112, but the operator reserved everything below 200.
        $this->assertSame(202, $client->nextVmId(200));
    }

    public function test_next_vmid_fails_when_no_id_is_free_above_the_floor(): void
    {
        $client = new ProxmoxClient($this->server());

        Http::fake([
            '*/api2/json/cluster/nextid?vmid=*' => Http::response([
                'data' => null,
                'errors' => ['vmid' => 'VM already exists'],
            ], 400),
            '*/api2/json/cluster/nextid' => Http::response(['data' => '112']),
        ]);

        $this->expectException(PanelException::class);
        $this->expectExceptionMessageMatches('/no free VMID in the/');

        $client->nextVmId(200);
    }

    // ──────────────────────────── error mapping ────────────────────────────

    public function test_api_error_envelope_is_flattened_into_the_message(): void
    {
        $client = new ProxmoxClient($this->server());

        Http::fake([
            '*/api2/json/nodes/pve1/qemu' => Http::response([
                'errors' => ['vmid' => 'VM 101 already exists on node pve1'],
                'message' => 'unable to create VM',
            ], 500),
        ]);

        $this->expectException(PanelException::class);
        $this->expectExceptionMessageMatches('/vmid: VM 101 already exists/');

        $client->createVm('pve1', 101, 'vm', 2, 2048, 50, 'local-lvm');
    }

    public function test_a_permission_denied_is_explained(): void
    {
        $client = new ProxmoxClient($this->server());

        Http::fake(['*' => Http::response(['message' => 'Permission check failed'], 403)]);

        $this->expectException(PanelException::class);
        $this->expectExceptionMessageMatches('/lacks the required role/');

        $client->nodes();
    }

    public function test_an_unreachable_node_is_reported_without_leaking_the_secret(): void
    {
        $client = new ProxmoxClient($this->server());

        Http::fake(fn () => throw new ConnectionException('cURL error 7: connection refused'));

        try {
            $client->nodes();
            $this->fail('Expected a PanelException.');
        } catch (PanelException $e) {
            $this->assertStringContainsString('Could not reach Proxmox VE', $e->getMessage());
            $this->assertStringNotContainsString('TOKEN-SECRET', $e->getMessage());
        }
    }

    // ───────────────────────────── provision ─────────────────────────────

    public function test_provision_clones_a_template_waits_for_the_task_and_verifies_the_vm(): void
    {
        $server = $this->server();
        $this->fakePve();

        $result = (new Proxmox)->provision($this->service($server), [
            'cpu' => 4,
            'ram' => 4096,
            'disk' => 60,
            'template_vmid' => 900,
            'node' => 'pve1',
            'storage' => 'local-zfs',
        ]);

        $this->assertTrue($result->success, $result->message);

        // Cloned the template into the next free id above the used 900.
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/nodes/pve1/qemu/900/clone')
            && $r['newid'] === 901
            && $r['full'] === 1);

        // The task was polled — a queued UPID is never treated as done.
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/tasks/') && str_contains($r->url(), '/status'));

        $account = PanelAccount::sole();
        $this->assertSame('901', $account->external_id);
        $this->assertSame(PanelAccount::STATUS_ACTIVE, $account->status);
        $this->assertSame('pve1', $account->meta['meta']['node']);
    }

    public function test_provision_grows_the_disk_because_a_clone_inherits_the_template_size(): void
    {
        $server = $this->server();

        // Template disk is 50G; asking for 80G must send a grow-only resize.
        $this->fakePve();

        (new Proxmox)->provision($this->service($server), [
            'cpu' => 4,
            'ram' => 4096,
            'disk' => 80,
            'template_vmid' => 900,
            'node' => 'pve1',
        ]);

        // acceptJson() makes Laravel send this as a JSON body, not form fields.
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/resize')
            && str_contains($r->body(), '"disk":"scsi0"')
            && str_contains($r->body(), '"size":"80G"'));
    }

    public function test_provision_does_not_shrink_a_disk(): void
    {
        $server = $this->server();
        $this->fakePve();

        // Template disk is 50G; asking for 20G must not send a resize at all —
        // PVE cannot shrink a disk and the call would fail the provision.
        (new Proxmox)->provision($this->service($server), [
            'cpu' => 2,
            'ram' => 2048,
            'disk' => 20,
            'template_vmid' => 900,
            'node' => 'pve1',
        ]);

        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/resize'));
    }

    public function test_provision_fails_when_the_task_reports_a_failure(): void
    {
        $server = $this->server();

        $this->fakePve([
            '*/api2/json/nodes/*/tasks/*/status' => Http::response(['data' => [
                'status' => 'stopped',
                'exitstatus' => 'clone failed: no space left on device',
            ]]),
        ]);

        $result = (new Proxmox)->provision($this->service($server), [
            'cpu' => 2,
            'ram' => 2048,
            'disk' => 50,
            'template_vmid' => 900,
            'node' => 'pve1',
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('no space left on device', $result->message);
        // Nothing may be recorded when the clone never produced a VM.
        $this->assertSame(0, PanelAccount::count());
    }

    public function test_provision_fails_when_the_vm_is_absent_after_a_successful_task(): void
    {
        $server = $this->server();

        $this->fakePve([
            // The clone task succeeds but the VM is not on the node.
            '*/api2/json/nodes/pve1/qemu/901/status/current' => Http::response(['message' => 'Configuration file does not exist'], 500),
        ]);

        $result = (new Proxmox)->provision($this->service($server), [
            'cpu' => 2,
            'ram' => 2048,
            'disk' => 50,
            'template_vmid' => 900,
            'node' => 'pve1',
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('not present on the node', $result->message);
        $this->assertSame(0, PanelAccount::count());
    }

    public function test_provision_without_a_template_builds_an_empty_vm_and_says_so(): void
    {
        $server = $this->server();

        $this->fakePve([
            '*/api2/json/nodes/pve1/qemu' => Http::response(['data' => null]),
        ]);

        $result = (new Proxmox)->provision($this->service($server), [
            'cpu' => 2,
            'ram' => 2048,
            'disk' => 50,
            'node' => 'pve1',
            'start_after_create' => true,
        ]);

        $this->assertTrue($result->success, $result->message);
        $this->assertStringContainsString('still needs an OS install', $result->message);

        // The empty build must name a pool; the roomiest eligible one wins.
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && str_contains($r->url(), '/nodes/pve1/qemu')
            && str_contains($r->body(), '"scsi0":"ceph_storage:50"'));
    }

    /**
     * Regression for a live-only defect: PVE lists storages in an arbitrary
     * order and includes disabled pools with no space. Taking the first
     * "disk-capable" entry returned `local` on one call and `local-zfs` on the
     * next on the same node — and that `local` was disabled with 0 bytes free.
     */
    public function test_storage_picks_a_usable_pool_and_is_stable(): void
    {
        $client = new ProxmoxClient($this->server());

        $this->fakePve();

        $first = $client->storageFor('pve1');
        $second = $client->storageFor('pve1');

        // `local` is disabled + empty, `pvstorage` is backup-only: both excluded.
        $this->assertSame('ceph_storage', $first);
        $this->assertSame($first, $second, 'Storage choice must not drift between calls.');
    }

    public function test_storage_rejects_a_pool_that_cannot_hold_a_disk(): void
    {
        $client = new ProxmoxClient($this->server());

        $this->fakePve();

        $this->expectException(PanelException::class);
        $this->expectExceptionMessageMatches('/cannot hold VM disks/');

        // `pvstorage` is a backup-only PBS pool.
        $client->storageFor('pve1', 'pvstorage');
    }

    public function test_storage_rejects_a_disabled_pool(): void
    {
        $client = new ProxmoxClient($this->server());

        $this->fakePve();

        $this->expectException(PanelException::class);
        $this->expectExceptionMessageMatches('/is not active/');

        $client->storageFor('pve1', 'local');
    }

    public function test_storage_rejects_an_unknown_pool_naming_the_alternatives(): void
    {
        $client = new ProxmoxClient($this->server());

        $this->fakePve();

        try {
            $client->storageFor('pve1', 'nope-pool');
            $this->fail('Expected a PanelException.');
        } catch (PanelException $e) {
            $this->assertStringContainsString('nope-pool', $e->getMessage());
            $this->assertStringContainsString('ceph_storage', $e->getMessage());
        }
    }

    public function test_storage_rejects_a_pool_with_no_free_space(): void
    {
        $client = new ProxmoxClient($this->server());

        $this->fakePve([
            '*/api2/json/nodes/pve1/storage' => Http::response(['data' => [
                ['storage' => 'local-zfs', 'type' => 'zfspool', 'active' => 1, 'avail' => 0, 'content' => 'images,rootdir'],
            ]]),
        ]);

        $this->expectException(PanelException::class);
        $this->expectExceptionMessageMatches('/has no free space/');

        $client->storageFor('pve1', 'local-zfs');
    }

    public function test_provision_fails_clearly_when_the_pinned_storage_is_unusable(): void
    {
        $server = $this->server();

        $this->fakePve();

        $result = (new Proxmox)->provision($this->service($server), [
            'cpu' => 2,
            'ram' => 2048,
            'disk' => 50,
            'template_vmid' => 900,
            'node' => 'pve1',
            'storage' => 'pvstorage',
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('cannot hold VM disks', $result->message);
        // Fails before any clone work starts.
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/clone'));
        $this->assertSame(0, PanelAccount::count());
    }

    public function test_provision_uses_a_fixed_vmid_when_the_product_sets_one(): void
    {
        $server = $this->server();

        $this->fakePve([
            '*/api2/json/nodes/pve1/qemu/500/clone' => Http::response(['data' => 'UPID:pve1:0000:clone']),
            '*/api2/json/nodes/pve1/qemu/500/status/current' => Http::response(['data' => ['status' => 'stopped']]),
            '*/api2/json/nodes/pve1/qemu/500/config' => Http::response(['data' => null]),
            '*/api2/json/nodes/pve1/qemu/500/resize' => Http::response(['data' => 'UPID:pve1:0000:resize']),
        ]);

        $result = (new Proxmox)->provision($this->service($server), [
            'cpu' => 2,
            'ram' => 2048,
            'disk' => 50,
            'template_vmid' => 900,
            'node' => 'pve1',
            'vmid' => 500,
        ]);

        $this->assertTrue($result->success, $result->message);
        $this->assertSame('500', PanelAccount::sole()->external_id);
    }

    public function test_provision_rejects_a_pinned_vmid_that_is_already_taken(): void
    {
        $server = $this->server();

        $this->fakePve();

        $result = (new Proxmox)->provision($this->service($server), [
            'cpu' => 2,
            'ram' => 2048,
            'disk' => 50,
            'template_vmid' => 900,
            'node' => 'pve1',
            // 101 is in use on the fake cluster.
            'vmid' => 101,
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('VMID 101 is already in use', $result->message);
        // Fails before any clone work starts.
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/clone'));
        $this->assertSame(0, PanelAccount::count());
    }

    public function test_provision_falls_back_to_the_first_online_node(): void
    {
        $server = $this->server();

        $this->fakePve([
            '*/api2/json/nodes' => Http::response(['data' => [
                ['node' => 'pve-offline', 'status' => 'offline'],
                ['node' => 'pve-live', 'status' => 'online'],
            ]]),
            '*/api2/json/nodes/pve-live/qemu/900/clone' => Http::response(['data' => 'UPID:pve-live:0000:clone']),
            '*/api2/json/nodes/pve-live/qemu/901/status/current' => Http::response(['data' => ['status' => 'running']]),
            '*/api2/json/nodes/pve-live/qemu/901/config' => Http::response(['data' => null]),
            '*/api2/json/nodes/pve-live/qemu/901/resize' => Http::response(['data' => 'UPID:pve-live:0000:resize']),
        ]);

        $result = (new Proxmox)->provision($this->service($server), [
            'cpu' => 2,
            'ram' => 2048,
            'disk' => 50,
            'template_vmid' => 900,
        ]);

        $this->assertTrue($result->success, $result->message);
        $this->assertSame('pve-live', PanelAccount::sole()->meta['meta']['node']);
    }

    // ───────────────────────────── lifecycle ─────────────────────────────

    public function test_suspend_stops_a_running_vm(): void
    {
        $server = $this->server();
        $proxmox = new Proxmox;
        $service = $this->service($server);

        $this->fakePve();
        $proxmox->provision($service, ['cpu' => 2, 'ram' => 2048, 'disk' => 50, 'template_vmid' => 900, 'node' => 'pve1']);

        $this->fakePve([
            '*/api2/json/nodes/pve1/qemu/901/status/shutdown' => Http::response(['data' => 'UPID:pve1:0000:shutdown']),
            '*/api2/json/nodes/pve1/qemu/901/status/current' => Http::response(['data' => ['status' => 'stopped']]),
        ]);

        $result = $proxmox->suspend($service->fresh(), []);

        $this->assertTrue($result->success, $result->message);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/status/shutdown'));
        $this->assertSame(PanelAccount::STATUS_SUSPENDED, PanelAccount::sole()->status);
    }

    public function test_suspend_of_an_already_stopped_vm_is_a_noop_not_a_failure(): void
    {
        $server = $this->server();
        $proxmox = new Proxmox;
        $service = $this->service($server);

        PanelAccount::create([
            'service_instance_id' => $service->id,
            'server_id' => $server->id,
            'panel' => 'proxmox',
            'username' => 'acme',
            'external_id' => '101',
            'status' => PanelAccount::STATUS_ACTIVE,
            'meta' => ['meta' => ['node' => 'pve1']],
            'provisioned_at' => now(),
        ]);

        Http::fake([
            '*/api2/json/nodes' => Http::response(['data' => [['node' => 'pve1', 'status' => 'online']]]),
            '*/api2/json/nodes/pve1/qemu/101/status/current' => Http::response(['data' => ['status' => 'stopped']]),
        ]);

        $result = $proxmox->suspend($service, []);

        $this->assertTrue($result->success, $result->message);
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/status/shutdown'));
    }

    public function test_unsuspend_starts_the_vm(): void
    {
        $server = $this->server();
        $proxmox = new Proxmox;
        $service = $this->service($server);

        PanelAccount::create([
            'service_instance_id' => $service->id,
            'server_id' => $server->id,
            'panel' => 'proxmox',
            'username' => 'acme',
            'external_id' => '101',
            'status' => PanelAccount::STATUS_SUSPENDED,
            'meta' => ['meta' => ['node' => 'pve1']],
            'provisioned_at' => now(),
        ]);

        Http::fake([
            '*/api2/json/nodes' => Http::response(['data' => [['node' => 'pve1', 'status' => 'online']]]),
            '*/api2/json/nodes/pve1/tasks/*' => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]),
            '*/api2/json/nodes/pve1/qemu/101/status/current' => Http::response(['data' => ['status' => 'stopped']]),
            '*/api2/json/nodes/pve1/qemu/101/status/start' => Http::response(['data' => 'UPID:pve1:0000:start']),
        ]);

        $result = $proxmox->unsuspend($service, []);

        $this->assertTrue($result->success, $result->message);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/status/start'));
        $this->assertSame(PanelAccount::STATUS_ACTIVE, PanelAccount::sole()->status);
    }

    public function test_terminate_destroys_the_vm(): void
    {
        $server = $this->server();
        $proxmox = new Proxmox;
        $service = $this->service($server);

        $this->fakePve();
        $proxmox->provision($service, ['cpu' => 2, 'ram' => 2048, 'disk' => 50, 'template_vmid' => 900, 'node' => 'pve1']);

        $this->fakePve([
            '*/api2/json/nodes/pve1/qemu/901?*' => Http::response(['data' => 'UPID:pve1:0000:destroy']),
        ]);

        $result = $proxmox->terminate($service->fresh(), []);

        $this->assertTrue($result->success, $result->message);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/nodes/pve1/qemu/901')
            && $r->method() === 'DELETE'
            && str_contains($r->url(), 'purge=1'));
        $this->assertSame(PanelAccount::STATUS_TERMINATED, PanelAccount::sole()->status);
    }

    public function test_terminate_keeps_the_vm_when_configured_to(): void
    {
        $server = $this->server();
        $proxmox = new Proxmox;
        $service = $this->service($server);

        $this->fakePve();
        $proxmox->provision($service, ['cpu' => 2, 'ram' => 2048, 'disk' => 50, 'template_vmid' => 900, 'node' => 'pve1']);

        $this->fakePve([
            '*/api2/json/nodes/pve1/qemu/901/status/current' => Http::response(['data' => ['status' => 'running']]),
            '*/api2/json/nodes/pve1/qemu/901/status/shutdown' => Http::response(['data' => 'UPID:pve1:0000:stop']),
        ]);

        $result = $proxmox->terminate($service->fresh(), ['delete_vm_on_terminate' => false]);

        $this->assertTrue($result->success, $result->message);
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'DELETE');
    }

    public function test_terminate_tolerates_an_already_destroyed_vm(): void
    {
        $server = $this->server();
        $proxmox = new Proxmox;
        $service = $this->service($server);

        $this->fakePve();
        $proxmox->provision($service, ['cpu' => 2, 'ram' => 2048, 'disk' => 50, 'template_vmid' => 900, 'node' => 'pve1']);

        $this->fakePve([
            '*/api2/json/nodes/pve1/qemu/901?*' => Http::response(['message' => 'Configuration file does not exist'], 500),
        ]);

        $result = $proxmox->terminate($service->fresh(), []);

        $this->assertTrue($result->success, $result->message);
        $this->assertSame(PanelAccount::STATUS_TERMINATED, PanelAccount::sole()->status);
    }

    public function test_lifecycle_without_a_recorded_vmid_fails_loudly(): void
    {
        $server = $this->server();
        $service = $this->service($server);

        PanelAccount::create([
            'service_instance_id' => $service->id,
            'server_id' => $server->id,
            'panel' => 'proxmox',
            'username' => 'acme',
            'status' => PanelAccount::STATUS_ACTIVE,
            'external_id' => null,
        ]);

        Http::fake();

        $result = (new Proxmox)->suspend($service, []);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('no Proxmox VE VMID recorded', $result->message);
    }

    // ─────────────────────── host-verified idempotency ───────────────────────

    public function test_an_active_record_whose_vm_still_exists_is_not_rebuilt(): void
    {
        $server = $this->server();
        $proxmox = new Proxmox;
        $service = $this->service($server);

        $this->fakePve();
        $proxmox->provision($service, ['cpu' => 2, 'ram' => 2048, 'disk' => 50, 'template_vmid' => 900, 'node' => 'pve1']);

        Http::fake(['*/api2/json/nodes/pve1/qemu/901/status/current' => Http::response(['data' => ['status' => 'running']])]);

        $result = $proxmox->provision($service->fresh(), ['cpu' => 2, 'ram' => 2048, 'disk' => 50]);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('already provisioned', $result->message);
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/clone'));
    }

    /**
     * A stale record whose VM is gone must rebuild rather than report success.
     *
     * The active row is seeded directly: provisioning first would leave the
     * phase-one stubs in place (a second Http::fake() merges into the first),
     * and those stale stubs would then outrank the gone-VM response below.
     */
    public function test_an_active_record_whose_vm_was_destroyed_is_rebuilt(): void
    {
        $server = $this->server();
        $service = $this->service($server);

        PanelAccount::create([
            'service_instance_id' => $service->id,
            'server_id' => $server->id,
            'panel' => 'proxmox',
            'username' => 'acme',
            'external_id' => '901',
            'status' => PanelAccount::STATUS_ACTIVE,
            'meta' => ['meta' => ['node' => 'pve1']],
            'provisioned_at' => now(),
        ]);

        Http::preventStrayRequests();
        Http::fake([
            // The recorded 901 is gone from the node.
            '*/api2/json/nodes/pve1/qemu/901/status/current' => Http::response(['message' => 'Configuration file does not exist'], 500),
            '*/api2/json/cluster/nextid*' => Http::response(['data' => 902]),
            '*/api2/json/nodes' => Http::response(['data' => [['node' => 'pve1', 'status' => 'online']]]),
            '*/api2/json/nodes/pve1/tasks/*' => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]),
            // The template being cloned from, unresolved because nothing is curated.
            '*/api2/json/nodes/pve1/qemu/900/status/current' => Http::response(['data' => ['status' => 'stopped']]),
            '*qemu/*/clone' => Http::response(['data' => 'UPID:pve1:0000:clone']),
            '*qemu/*/config' => Http::response(['data' => ['scsi0' => 'local-lvm:50,size=50G']]),
            '*qemu/*/resize' => Http::response(['data' => 'UPID:pve1:0000:resize']),
            '*qemu/*/status/start' => Http::response(['data' => 'UPID:pve1:0000:start']),
            '*/api2/json/nodes/pve1/qemu/902/status/current' => Http::response(['data' => ['status' => 'running']]),
        ]);

        $result = (new Proxmox)->provision($service, [
            'cpu' => 2,
            'ram' => 2048,
            'disk' => 50,
            'template_vmid' => 900,
            'node' => 'pve1',
        ]);

        $this->assertTrue($result->success, $result->message);
        $this->assertStringNotContainsString('already provisioned', $result->message);

        // Only ever one panel_accounts row — the stale one is re-activated, not
        // duplicated, and it now points at the replacement VM.
        $this->assertSame(1, PanelAccount::count());
        $this->assertSame(PanelAccount::STATUS_ACTIVE, PanelAccount::sole()->status);
        $this->assertSame('902', PanelAccount::sole()->external_id);
    }

    public function test_a_transport_failure_during_the_probe_is_not_read_as_vm_absent(): void
    {
        $server = $this->server();
        $proxmox = new Proxmox;
        $service = $this->service($server);

        $this->fakePve();
        $proxmox->provision($service, ['cpu' => 2, 'ram' => 2048, 'disk' => 50, 'template_vmid' => 900, 'node' => 'pve1']);

        Http::fake(fn () => throw new ConnectionException('cURL error 28: timeout'));

        $result = $proxmox->provision($service->fresh(), ['cpu' => 2, 'ram' => 2048, 'disk' => 50]);

        // Unknown must fail closed — treating it as absent would destroy a live record.
        $this->assertFalse($result->success);
        $this->assertSame(PanelAccount::STATUS_ACTIVE, PanelAccount::sole()->status);
    }

    /**
     * A dead node answers 595, not 500, and a generic PVE 500 must NOT be read
     * as "the VM is gone" — that would rebuild a live VM and orphan the real one.
     * Both must fail closed so the record is left alone.
     */
    public function test_a_node_outage_is_not_read_as_a_deleted_vm(): void
    {
        $client = new ProxmoxClient($this->server());

        // 595 = node unreachable, with an empty body (observed on a live cluster).
        Http::fake(['*' => Http::response('', 595)]);

        $this->assertFalse($client->isMissingVm('Proxmox VE /nodes/pve3/qemu/105/status/current returned HTTP 595'));

        $this->expectException(PanelException::class);
        $client->vmExists('pve3', 105);
    }

    public function test_a_generic_500_is_not_read_as_a_deleted_vm(): void
    {
        $client = new ProxmoxClient($this->server());

        Http::fake(['*' => Http::response(['message' => 'internal error: storage unavailable'], 500)]);

        $this->assertFalse($client->isMissingVm('Proxmox VE /nodes/pve1/qemu/105/config returned HTTP 500: internal error: storage unavailable'));

        $this->expectException(PanelException::class);
        $client->vmExists('pve1', 105);
    }

    public function test_a_missing_vm_is_recognised_from_pves_message(): void
    {
        $client = new ProxmoxClient($this->server());

        // Exact live shape: HTTP 500 + "Configuration file ... does not exist".
        $this->assertTrue($client->isMissingVm(
            'Proxmox VE /nodes/Pve1-Twin04-Node01/qemu/105/status/current returned HTTP 500: '
            ."Configuration file 'nodes/Pve1-Twin04-Node01/qemu-server/105.conf' does not exist",
        ));
    }

    public function test_a_provision_probe_on_a_dead_node_fails_closed(): void
    {
        $server = $this->server();
        $service = $this->service($server);

        PanelAccount::create([
            'service_instance_id' => $service->id,
            'server_id' => $server->id,
            'panel' => 'proxmox',
            'username' => 'acme',
            'external_id' => '105',
            'status' => PanelAccount::STATUS_ACTIVE,
            'meta' => ['meta' => ['node' => 'pve3']],
            'provisioned_at' => now(),
        ]);

        // The node hosting this VM is down.
        Http::fake(['*' => Http::response('', 595)]);

        $result = (new Proxmox)->provision($service, ['cpu' => 2, 'ram' => 2048, 'disk' => 50]);

        $this->assertFalse($result->success);
        // The record must be untouched: no rebuild, no terminate, no duplicate.
        $this->assertSame(1, PanelAccount::count());
        $this->assertSame(PanelAccount::STATUS_ACTIVE, PanelAccount::sole()->status);
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/clone'));
    }

    // ───────────────────── service status sync on provision ─────────────────────

    /**
     * A built machine means the service is live. Every driver creates its panel
     * account through the base class, so the service row is advanced there —
     * callers that do their own sync have paths that skip it (the manual
     * provisioner's "already provisioned" short-circuit returns before touching
     * the service, which left a service reading `pending` forever).
     */
    public function test_provisioning_advances_a_pending_service_to_active(): void
    {
        $server = $this->server();
        $service = $this->service($server);
        $service->update(['status' => 'pending']);

        $this->fakePve();

        $result = (new Proxmox)->provision($service->fresh(), [
            'cpu' => 2, 'ram' => 2048, 'disk' => 50,
            'template_vmid' => 900, 'node' => 'pve1',
        ]);

        $this->assertTrue($result->success, $result->message);
        $this->assertSame('active', $service->fresh()->status);
    }

    public function test_provisioning_does_not_reactivate_a_suspended_service(): void
    {
        $server = $this->server();
        $service = $this->service($server);
        $service->update(['status' => 'suspended']);

        $this->fakePve();

        $result = (new Proxmox)->provision($service->fresh(), [
            'cpu' => 2, 'ram' => 2048, 'disk' => 50,
            'template_vmid' => 900, 'node' => 'pve1',
        ]);

        $this->assertTrue($result->success, $result->message);
        // A re-provision must not silently un-suspend a suspended service.
        $this->assertSame('suspended', $service->fresh()->status);
    }

    // ──────────────────────────── host info ────────────────────────────

    public function test_server_info_reports_version_and_vm_counts(): void
    {
        $client = new ProxmoxClient($this->server());

        Http::fake([
            '*/api2/json/version' => Http::response(['data' => ['version' => '8.2.2', 'release' => '8.2']]),
            '*/api2/json/nodes' => Http::response(['data' => [['node' => 'pve1', 'status' => 'online']]]),
            '*/api2/json/cluster/status' => Http::response(['data' => [['type' => 'cluster', 'name' => 'homelab']]]),
            '*/api2/json/cluster/resources*' => Http::response(['data' => [
                ['vmid' => 101, 'type' => 'qemu', 'status' => 'running', 'node' => 'pve1', 'maxmem' => 2048, 'maxcpu' => 2, 'cpu' => 0.5, 'mem' => 1024, 'maxdisk' => 10737418240, 'uptime' => 7200],
                ['vmid' => 102, 'type' => 'qemu', 'status' => 'stopped', 'node' => 'pve1'],
                ['vmid' => 103, 'type' => 'lxc', 'status' => 'running', 'node' => 'pve1'],
            ]]),
        ]);

        $dto = $client->getServerInfo();

        $this->assertSame('homelab', $dto->hostname);
        $this->assertSame('8.2-8.2.2', $dto->version);
        // LXC containers are not QEMU VMs and must not be counted or listed.
        $this->assertSame(2, $dto->totalAccounts);
        $this->assertSame(1, $dto->meta['vms_running']);
        $this->assertArrayNotHasKey('error', $dto->meta);
    }

    public function test_list_vms_emits_the_presenter_contract(): void
    {
        $client = new ProxmoxClient($this->server());

        Http::fake(['*/api2/json/cluster/resources*' => Http::response(['data' => [
            ['vmid' => 101, 'type' => 'qemu', 'name' => 'web-1', 'status' => 'running', 'node' => 'pve1', 'maxmem' => 2048, 'maxcpu' => 2, 'cpu' => 0.5, 'mem' => 1024, 'maxdisk' => 10737418240, 'uptime' => 90000],
            ['vmid' => 103, 'type' => 'lxc', 'name' => 'ct-1', 'status' => 'running', 'node' => 'pve1'],
        ]])]);

        $vms = $client->listVms();

        $this->assertCount(1, $vms);
        $vm = $vms[0];

        // vmId must equal panel_accounts.external_id or the presenter cannot
        // correlate the row with the host.
        $this->assertSame('101', $vm['vmId']);
        $this->assertSame('web-1', $vm['name']);
        $this->assertSame('running', $vm['state']);
        $this->assertSame(50, $vm['cpuUsage']);
        $this->assertSame(2048, $vm['memoryAssigned']);
        $this->assertSame(2, $vm['processorCount']);
        $this->assertSame(10, $vm['diskGb']);
        $this->assertSame('1d 1h', $vm['uptime']);
        // Not a template — the admin destroy affordance keys off this.
        $this->assertFalse($vm['template']);
    }

    public function test_list_vms_degrades_to_empty_on_failure(): void
    {
        $client = new ProxmoxClient($this->server());

        Http::fake(fn () => throw new ConnectionException('down'));

        $this->assertSame([], $client->listVms());
    }

    public function test_test_connection_succeeds_and_carries_no_secret(): void
    {
        $client = new ProxmoxClient($this->server());

        Http::fake([
            '*/api2/json/version' => Http::response(['data' => ['version' => '8.2.2', 'release' => '8.2']]),
            '*/api2/json/nodes' => Http::response(['data' => [['node' => 'pve1', 'status' => 'online']]]),
            '*/api2/json/access/permissions' => Http::response(['data' => ['/vms' => ['VM.Audit' => 1]]]),
            '*/api2/json/cluster/resources*' => Http::response(['data' => [
                ['vmid' => 101, 'type' => 'qemu', 'status' => 'running'],
            ]]),
            '*/api2/json/nodes/pve1/storage' => Http::response(['data' => [['storage' => 'local-zfs']]]),
        ]);

        $result = $client->testConnection();

        $this->assertTrue($result->ok, $result->message);
        $this->assertStringContainsString('8.2-8.2.2', $result->message);
        $this->assertStringContainsString('1 VM visible', $result->message);
        $this->assertSame(1, $result->meta['node_count']);
        $this->assertSame(1, $result->meta['vms_visible']);
        $this->assertSame(1, $result->meta['datastores_visible']);
        $this->assertStringNotContainsString('TOKEN-SECRET', json_encode($result->meta));
    }

    /**
     * A freshly created PVE API token has privilege separation ON and no ACL:
     * it authenticates and answers /version and /nodes, but sees no VMs. This
     * must NOT be reported as a healthy connection — that false green hides a
     * credential that cannot provision anything.
     */
    public function test_test_connection_rejects_a_credential_with_no_privileges(): void
    {
        $client = new ProxmoxClient($this->server());

        Http::fake([
            '*/api2/json/version' => Http::response(['data' => ['version' => '9.1', 'release' => '9.1']]),
            '*/api2/json/nodes' => Http::response(['data' => [['node' => 'pve1', 'status' => 'online']]]),
            // Exactly what a privilege-separated token returns.
            '*/api2/json/access/permissions' => Http::response(['data' => []]),
        ]);

        $result = $client->testConnection();

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('no effective privileges', $result->message);
        $this->assertStringContainsString('Privilege Separation', $result->message);
        $this->assertStringContainsString('PVEVMAdmin', $result->message);
        // The node data still proves it reached PVE.
        $this->assertStringContainsString('9.1', $result->message);
    }

    public function test_test_connection_warns_when_no_datastore_is_visible(): void
    {
        $client = new ProxmoxClient($this->server());

        Http::fake([
            '*/api2/json/version' => Http::response(['data' => ['version' => '9.1', 'release' => '9.1']]),
            '*/api2/json/nodes' => Http::response(['data' => [['node' => 'pve1', 'status' => 'online']]]),
            '*/api2/json/access/permissions' => Http::response(['data' => ['/vms' => ['VM.Audit' => 1]]]),
            '*/api2/json/cluster/resources*' => Http::response(['data' => []]),
            '*/api2/json/nodes/pve1/storage' => Http::response(['data' => []]),
        ]);

        $result = $client->testConnection();

        $this->assertTrue($result->ok, $result->message);
        $this->assertStringContainsString('no datastores are visible', $result->message);
        $this->assertSame(0, $result->meta['datastores_visible']);
    }

    public function test_test_connection_failure_hides_the_credential(): void
    {
        $client = new ProxmoxClient($this->server());

        Http::fake(fn () => throw new ConnectionException('connection refused: TOKEN-SECRET'));

        $result = $client->testConnection();

        $this->assertFalse($result->ok);
        $this->assertStringNotContainsString('TOKEN-SECRET', $result->message);
    }

    // ─────────────────────────── node resolution ───────────────────────────

    public function test_a_lifecycle_action_finds_the_vm_on_its_actual_node(): void
    {
        $server = $this->server();
        $proxmox = new Proxmox;
        $service = $this->service($server);

        // Recorded node says pve1 but the VM actually moved to pve2.
        PanelAccount::create([
            'service_instance_id' => $service->id,
            'server_id' => $server->id,
            'panel' => 'proxmox',
            'username' => 'acme',
            'external_id' => '101',
            'status' => PanelAccount::STATUS_ACTIVE,
            'meta' => ['meta' => ['node' => 'pve1']],
            'provisioned_at' => now(),
        ]);

        Http::fake([
            '*/api2/json/nodes/pve1/qemu/101/status/current' => Http::response(['message' => 'does not exist'], 500),
            '*/api2/json/nodes' => Http::response(['data' => [['node' => 'pve2', 'status' => 'online']]]),
            '*/api2/json/nodes/pve2/tasks/*' => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]),
            '*/api2/json/nodes/pve2/qemu/101/status/current' => Http::response(['data' => ['status' => 'stopped']]),
            '*/api2/json/nodes/pve2/qemu/101/status/start' => Http::response(['data' => 'UPID:pve2:0000:start']),
        ]);

        $result = $proxmox->unsuspend($service, []);

        $this->assertTrue($result->success, $result->message);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/nodes/pve2/qemu/101/status/start'));
    }

    // ────────────────────── post-clone failure cleanup ──────────────────────

    /**
     * A failure after a successful clone (resize, config, probe) used to leave
     * the VM on the node with no panel record — and the next attempt allocated
     * a NEW VMID, so the orphan leaked its disks and its id permanently. The
     * partial build must be removed before the failure is reported.
     */
    public function test_a_failure_after_a_successful_clone_destroys_the_orphan(): void
    {
        $server = $this->server();

        $this->fakePve([
            // The clone succeeds; the grow-only resize then fails.
            '*/api2/json/nodes/pve1/qemu/901/resize' => Http::response(['message' => 'no space left on device'], 500),
        ]);

        $result = (new Proxmox)->provision($this->service($server), [
            'cpu' => 2, 'ram' => 2048, 'disk' => 80,
            'template_vmid' => 900, 'node' => 'pve1',
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('no space left on device', $result->message);
        $this->assertStringContainsString('partial VM was removed', $result->message);
        $this->assertSame(0, PanelAccount::count());

        // The orphan was destroyed before the failure was reported.
        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE'
            && str_contains($r->url(), '/nodes/pve1/qemu/901'));
    }

    public function test_a_clone_timeout_reports_that_the_state_is_unknown(): void
    {
        $server = $this->server();

        $this->fakePve([
            // The clone task never leaves the running state.
            '*/api2/json/nodes/pve1/tasks/*' => Http::response(['data' => ['status' => 'running']]),
        ]);

        $result = (new Proxmox)->provision($this->service($server), [
            'cpu' => 2, 'ram' => 2048, 'disk' => 50,
            'template_vmid' => 900, 'node' => 'pve1',
            'clone_timeout' => 1,
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('did not finish within 1 seconds', $result->message);
        // A running clone may still finish later, so the operator is told where
        // to look instead of being told nothing happened.
        $this->assertStringContainsString('may still be running', $result->message);
        $this->assertStringContainsString('VMID 901', $result->message);
        $this->assertSame(0, PanelAccount::count());
        // No blind DELETE: the clone may legitimately be mid-flight.
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'DELETE');
    }

    // ───────────────────────── ISO / empty-VM builds ─────────────────────────

    /**
     * ISOs routinely live on `local` while disks live on `local-zfs`/RBD/Ceph.
     * Attaching the ISO to the disk pool fails with a PVE content-type
     * rejection, so the CD-ROM must resolve its own ISO-capable pool.
     */
    public function test_an_empty_build_attaches_the_iso_from_an_iso_capable_pool(): void
    {
        $server = $this->server();

        $this->fakePve([
            '*/api2/json/nodes/pve1/storage' => Http::response(['data' => [
                ['storage' => 'local', 'type' => 'dir', 'active' => 1, 'avail' => 10000000000, 'content' => 'iso,vztmpl,backup'],
                ['storage' => 'local-zfs', 'type' => 'zfspool', 'active' => 1, 'avail' => 40000000000, 'content' => 'images,rootdir'],
            ]]),
            '*/api2/json/nodes/pve1/storage/local/content*' => Http::response(['data' => [
                ['volid' => 'local:iso/ubuntu-22.04.iso', 'content' => 'iso'],
            ]]),
        ]);

        $result = (new Proxmox)->provision($this->service($server), [
            'cpu' => 2, 'ram' => 2048, 'disk' => 50,
            'node' => 'pve1',
            'iso' => 'ubuntu-22.04.iso',
            'start_after_create' => false,
        ]);

        $this->assertTrue($result->success, $result->message);
        $this->assertStringContainsString('still needs an OS install', $result->message);

        // The disk is on the disk pool, the CD-ROM on the ISO pool. (The body is
        // JSON, so the slash in the volid arrives escaped — match around it.)
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
            && str_contains($r->url(), '/nodes/pve1/qemu')
            && str_contains($r->body(), '"scsi0":"local-zfs:50"')
            && str_contains($r->body(), '"ide2":"local:iso')
            && str_contains($r->body(), 'ubuntu-22.04.iso,media=cdrom'));
    }

    public function test_an_empty_build_fails_before_creating_when_the_iso_is_missing(): void
    {
        $server = $this->server();

        $this->fakePve([
            '*/api2/json/nodes/pve1/storage' => Http::response(['data' => [
                ['storage' => 'local', 'type' => 'dir', 'active' => 1, 'avail' => 10000000000, 'content' => 'iso,vztmpl,backup'],
                ['storage' => 'local-zfs', 'type' => 'zfspool', 'active' => 1, 'avail' => 40000000000, 'content' => 'images,rootdir'],
            ]]),
            '*/api2/json/nodes/pve1/storage/local/content*' => Http::response(['data' => []]),
        ]);

        $result = (new Proxmox)->provision($this->service($server), [
            'cpu' => 2, 'ram' => 2048, 'disk' => 50,
            'node' => 'pve1',
            'iso' => 'missing.iso',
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('was not found on storage "local"', $result->message);
        // Nothing was created.
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'POST'
            && str_contains($r->url(), '/nodes/pve1/qemu'));
    }

    public function test_a_bridge_value_cannot_inject_pve_options(): void
    {
        $server = $this->server();

        $this->fakePve();

        $result = (new Proxmox)->provision($this->service($server), [
            'cpu' => 2, 'ram' => 2048, 'disk' => 50,
            'node' => 'pve1',
            'bridge' => 'vmbr0,firewall=0',
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('not a valid Proxmox VE bridge name', $result->message);
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'POST'
            && str_contains($r->url(), '/nodes/pve1/qemu'));
    }

    public function test_a_vlan_tag_must_be_a_number_in_range(): void
    {
        $server = $this->server();

        $this->fakePve();

        $result = (new Proxmox)->provision($this->service($server), [
            'cpu' => 2, 'ram' => 2048, 'disk' => 50,
            'node' => 'pve1',
            'vlan_tag' => '10,firewall=0',
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('PVE accepts 1-4094', $result->message);
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'POST'
            && str_contains($r->url(), '/nodes/pve1/qemu'));
    }

    // ───────────────────────── cloud-init credentials ─────────────────────────

    /**
     * PVE only consumes ciuser/cipassword through a cloud-init drive. When the
     * template provides one, the module must set them — otherwise the welcome
     * email pairs a guest username with a password that exists nowhere.
     */
    public function test_guest_credentials_are_applied_when_the_clone_has_a_cloud_init_drive(): void
    {
        $server = $this->server();

        $this->fakePve([
            '*qemu/*/config' => Http::response(['data' => [
                'scsi0' => 'local-lvm:50,size=50G',
                'ide2' => 'local-lvm:vm-901-cloudinit,media=cdrom',
                'citype' => 'nocloud',
            ]]),
        ]);

        $result = (new Proxmox)->provision($this->service($server), [
            'cpu' => 2, 'ram' => 2048, 'disk' => 50,
            'template_vmid' => 900, 'node' => 'pve1',
            'guest_username' => 'ubuntu',
            'guest_password' => 's3cret-guest',
            'start_after_create' => false,
        ]);

        $this->assertTrue($result->success, $result->message);
        $this->assertStringNotContainsString('no cloud-init drive', $result->message);

        Http::assertSent(fn (Request $r): bool => $r->method() === 'PUT'
            && str_contains($r->url(), '/nodes/pve1/qemu/901/config')
            && str_contains($r->body(), '"ciuser":"ubuntu"')
            && str_contains($r->body(), '"cipassword":"s3cret-guest"'));

        $account = PanelAccount::sole();
        $this->assertSame('ubuntu', $account->guest_username);
        $this->assertSame('s3cret-guest', $account->guest_password_encrypted);
    }

    /**
     * The narrow customer-visible bug: with only `guest_username` set, the
     * welcome email paired it with the random panel password — a credential
     * that exists nowhere. On a cloud-init template the generated password is
     * now what is actually applied, so the delivered pair is real.
     */
    public function test_a_generated_password_is_applied_when_only_the_guest_username_is_set(): void
    {
        $server = $this->server();

        $this->fakePve([
            '*qemu/*/config' => Http::response(['data' => [
                'scsi0' => 'local-lvm:50,size=50G',
                'ide2' => 'local-lvm:vm-901-cloudinit,media=cdrom',
            ]]),
        ]);

        $result = (new Proxmox)->provision($this->service($server), [
            'cpu' => 2, 'ram' => 2048, 'disk' => 50,
            'template_vmid' => 900, 'node' => 'pve1',
            'guest_username' => 'ubuntu',
            'start_after_create' => false,
        ]);

        $this->assertTrue($result->success, $result->message);

        $account = PanelAccount::sole();
        $this->assertSame('ubuntu', $account->guest_username);
        // The recorded guest password is the same one the panel generated and
        // applied through cloud-init.
        $this->assertSame($account->password_encrypted, $account->guest_password_encrypted);
        $this->assertNotSame('', (string) $account->guest_password_encrypted);
    }

    public function test_guest_credentials_are_not_reported_when_the_clone_has_no_cloud_init_drive(): void
    {
        $server = $this->server();

        $this->fakePve();

        $result = (new Proxmox)->provision($this->service($server), [
            'cpu' => 2, 'ram' => 2048, 'disk' => 50,
            'template_vmid' => 900, 'node' => 'pve1',
            'guest_username' => 'ubuntu',
            'guest_password' => 's3cret-guest',
            'start_after_create' => false,
        ]);

        $this->assertTrue($result->success, $result->message);
        $this->assertStringContainsString('no cloud-init drive', $result->message);

        // Nothing was applied, so nothing may be stored or mailed as a login.
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->body(), '"ciuser"'));
        $this->assertNull(PanelAccount::sole()->guest_username);
    }

    // ───────────────────────────── minor fixes ─────────────────────────────

    /**
     * Legacy rows saved before `start_after_create` existed read the schema
     * default (true) — the old `! empty()` check silently treated them as
     * false, contradicting the schema and README.
     */
    public function test_start_after_create_defaults_to_true_for_legacy_config(): void
    {
        $server = $this->server();

        $this->fakePve([
            '*/api2/json/nodes/pve1/qemu/901/status/current' => Http::response(['data' => ['status' => 'stopped']]),
        ]);

        $result = (new Proxmox)->provision($this->service($server), [
            'cpu' => 2, 'ram' => 2048, 'disk' => 50,
            'template_vmid' => 900, 'node' => 'pve1',
            // no start_after_create key at all
        ]);

        $this->assertTrue($result->success, $result->message);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/nodes/pve1/qemu/901/status/start'));
        $this->assertTrue((bool) PanelAccount::sole()->meta['meta']['running']);
    }

    public function test_list_vms_reports_memory_demand_even_without_maxmem(): void
    {
        $client = new ProxmoxClient($this->server());

        Http::fake(['*/api2/json/cluster/resources*' => Http::response(['data' => [
            ['vmid' => 101, 'type' => 'qemu', 'name' => 'web-1', 'status' => 'running', 'node' => 'pve1',
                'maxmem' => 0, 'mem' => 1048576, 'maxcpu' => 1, 'cpu' => 0.25, 'maxdisk' => 0],
        ]])]);

        $vm = $client->listVms()[0];

        // `mem` is the actual usage; a partially populated row (maxmem 0) must
        // not zero it out.
        $this->assertSame(1048576, $vm['memoryDemand']);
        // PVE normalises cpu to the VM's own vCPU count (0..1).
        $this->assertSame(25, $vm['cpuUsage']);
    }

    public function test_a_bare_ipv6_address_is_kept_whole_and_bracketed(): void
    {
        $server = Server::create([
            'name' => 'pve-v6',
            'ip_address' => '::1',
            'server_type' => 'proxmox',
            'api_username' => 'root@pam!automation',
            'api_password_encrypted' => 'TOKEN-SECRET',
            'max_accounts' => 0,
            'status' => 'active',
            'connection_meta' => ['auth_type' => 'token', 'port' => 8006, 'verify_tls' => false],
        ]);

        $client = new ProxmoxClient($server);

        // Before the fix this parsed as host ":" + port 1.
        $this->assertSame('[::1]', $client->hostLabel());

        Http::preventStrayRequests();
        Http::fake(['*/api2/json/version' => Http::response(['data' => ['version' => '9.1', 'release' => '9.1']])]);

        $client->version();

        Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), 'https://[::1]:8006/api2/json/version'));
    }

    /**
     * PVE refuses to destroy a running VM ("VM is running - destroy failed"),
     * so a terminate on a live VM would fail. The destroy must stop it first.
     */
    public function test_destroy_stops_a_running_vm_before_deleting(): void
    {
        $client = new ProxmoxClient($this->server());

        Http::fake([
            '*/api2/json/nodes/pve1/qemu/105/status/current' => Http::response(['data' => ['status' => 'running']]),
            '*/api2/json/nodes/pve1/qemu/105/status/shutdown' => Http::response(['data' => 'UPID:pve1:0000:shutdown']),
            '*/api2/json/nodes/pve1/qemu/105/status/stop' => Http::response(['data' => 'UPID:pve1:0000:stop']),
            '*/api2/json/nodes/pve1/tasks/*' => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]),
            '*/api2/json/nodes/pve1/qemu/105?*' => Http::response(['data' => 'UPID:pve1:0000:destroy']),
        ]);

        $client->destroyVm('pve1', 105);

        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/status/shutdown'));
        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE'
            && str_contains($r->url(), '/nodes/pve1/qemu/105'));
    }
}
